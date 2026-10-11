<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Infrastructure\Failure;

use AhmedNour\CircuitBreaker\Domain\Contracts\FailureClassifier;
use Error;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use InvalidArgumentException;
use Throwable;
use TypeError;

final class HttpFailureClassifier implements FailureClassifier
{
    /**
     * @var list<string> Substrings in exception messages indicating network/connection issues
     */
    private const NETWORK_ERROR_PATTERNS = [
        'timed out',
        'timeout',
        'could not resolve host',
        'connection refused',
        'failed to connect',
        'network is unreachable',
        'connection reset by peer',
        'ssl connection timeout',
        'resolving timed out',
        'operation timed out',
        'cURL error 7',  // CURLE_COULDNT_CONNECT
        'cURL error 28', // CURLE_OPERATION_TIMEDOUT
        'cURL error 6',  // CURLE_COULDNT_RESOLVE_HOST
        'cURL error 35', // CURLE_SSL_CONNECT_ERROR
        'cURL error 52', // CURLE_GOT_NOTHING
        'cURL error 56', // CURLE_RECV_ERROR
        '500 internal server error',
        '502 bad gateway',
        '503 service unavailable',
        '504 gateway timeout',
        'server error',
        'service unavailable',
        'bad gateway',
        'gateway timeout',
    ];

    /**
     * @param  list<class-string<Throwable>>  $additionalTripClasses
     * @param  list<class-string<Throwable>>  $ignoreClasses
     * @param  list<int>  $tripStatusCodes
     * @param  bool  $matchMessagePatterns  Opt in to tripping on message substrings such as "timeout".
     *                                      Off by default: unrelated exceptions can contain those words.
     */
    public function __construct(
        private readonly array $additionalTripClasses = [],
        private readonly array $ignoreClasses = [],
        private readonly array $tripStatusCodes = [500, 502, 503, 504, 520, 521, 522, 523, 524],
        private readonly bool $matchMessagePatterns = false,
    ) {}

    public function shouldTrip(Throwable $exception): bool
    {
        // Never trip on application code errors, syntax errors, or type errors
        if ($exception instanceof Error || $exception instanceof TypeError || $exception instanceof InvalidArgumentException) {
            return false;
        }

        // Check if explicitly configured to ignore
        foreach ($this->ignoreClasses as $ignoreClass) {
            if ($exception instanceof $ignoreClass) {
                return false;
            }
        }

        // Check additional user-defined trip classes
        foreach ($this->additionalTripClasses as $tripClass) {
            if ($exception instanceof $tripClass) {
                return true;
            }
        }

        // Guzzle connection or server (5xx) exceptions
        if ($exception instanceof ConnectException || $exception instanceof ServerException) {
            return true;
        }

        // Laravel HTTP Client ConnectionException (if illuminate/http is installed)
        if (is_a($exception, 'Illuminate\Http\Client\ConnectionException')) {
            return true;
        }

        // Check for HTTP response status code in exception
        $statusCode = $this->extractStatusCode($exception);
        if ($statusCode !== null) {
            if ($statusCode >= 400 && $statusCode < 500) {
                return false; // Client errors (4xx) should not trip the circuit
            }

            if (in_array($statusCode, $this->tripStatusCodes, true) || $statusCode >= 500) {
                return true;
            }
        }

        if (! $this->matchMessagePatterns) {
            return false;
        }

        // Check message for connection/timeout patterns
        $message = strtolower($exception->getMessage());
        foreach (self::NETWORK_ERROR_PATTERNS as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function extractStatusCode(Throwable $exception): ?int
    {
        // Check for getResponse()->getStatusCode() (PSR-7 / Guzzle / Laravel RequestException)
        if (method_exists($exception, 'getResponse')) {
            $response = $exception->getResponse();
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                return (int) $response->getStatusCode();
            }
            if (is_object($response) && method_exists($response, 'status')) {
                return (int) $response->status();
            }
        }

        // Check for getStatusCode() method
        if (method_exists($exception, 'getStatusCode')) {
            $code = $exception->getStatusCode();
            if (is_int($code) && $code >= 100 && $code <= 599) {
                return $code;
            }
        }

        // Check if code is a valid HTTP status code
        $code = $exception->getCode();
        if ($code >= 400 && $code <= 599) {
            return $code;
        }

        return null;
    }
}
