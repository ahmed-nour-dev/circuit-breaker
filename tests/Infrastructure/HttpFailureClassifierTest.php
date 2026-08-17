<?php

declare(strict_types=1);

namespace Tests\Infrastructure;

use App\Modules\CircuitBreaker\Infrastructure\Failure\HttpFailureClassifier;
use Error;
use Exception;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TypeError;

final class HttpFailureClassifierTest extends TestCase
{
    private HttpFailureClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new HttpFailureClassifier();
    }

    public function test_trips_on_guzzle_connect_exception(): void
    {
        $request = new Request('GET', 'https://api.example.com');
        $exception = new ConnectException('Connection timed out', $request);

        $this->assertTrue($this->classifier->shouldTrip($exception));
    }

    public function test_trips_on_guzzle_server_503_exception(): void
    {
        $request = new Request('POST', 'https://api.example.com/charge');
        $response = new Response(503, [], 'Service Unavailable');
        $exception = new ServerException('Server error', $request, $response);

        $this->assertTrue($this->classifier->shouldTrip($exception));
    }

    public function test_trips_on_curl_timeout_and_network_patterns(): void
    {
        $this->assertTrue($this->classifier->shouldTrip(new RuntimeException('cURL error 28: Operation timeout')));
        $this->assertTrue($this->classifier->shouldTrip(new RuntimeException('cURL error 7: Failed to connect to host')));
        $this->assertTrue($this->classifier->shouldTrip(new RuntimeException('Could not resolve host: api.twilio.com')));
        $this->assertTrue($this->classifier->shouldTrip(new RuntimeException('Connection refused')));
        $this->assertTrue($this->classifier->shouldTrip(new RuntimeException('Network is unreachable')));
    }

    public function test_trips_on_generic_5xx_exception_codes(): void
    {
        $this->assertTrue($this->classifier->shouldTrip(new Exception('Gateway issue', 502)));
        $this->assertTrue($this->classifier->shouldTrip(new Exception('Internal error', 500)));
        $this->assertTrue($this->classifier->shouldTrip(new Exception('Gateway Timeout', 504)));
    }

    public function test_does_not_trip_on_4xx_client_errors(): void
    {
        $this->assertFalse($this->classifier->shouldTrip(new Exception('Bad Request', 400)));
        $this->assertFalse($this->classifier->shouldTrip(new Exception('Unauthorized', 401)));
        $this->assertFalse($this->classifier->shouldTrip(new Exception('Forbidden', 403)));
        $this->assertFalse($this->classifier->shouldTrip(new Exception('Not Found', 404)));
        $this->assertFalse($this->classifier->shouldTrip(new Exception('Unprocessable Entity', 422)));
    }

    public function test_does_not_trip_on_php_errors_and_type_errors(): void
    {
        $this->assertFalse($this->classifier->shouldTrip(new TypeError('Argument 1 must be of type string, int given')));
        $this->assertFalse($this->classifier->shouldTrip(new Error('Call to a member function send() on null')));
        $this->assertFalse($this->classifier->shouldTrip(new InvalidArgumentException('Invalid phone number format')));
    }

    public function test_respects_custom_trip_and_ignore_classes(): void
    {
        $classifier = new HttpFailureClassifier(
            additionalTripClasses: [CustomProviderException::class],
            ignoreClasses: [IgnoreMeException::class],
        );

        $this->assertTrue($classifier->shouldTrip(new CustomProviderException('Custom failure')));
        $this->assertFalse($classifier->shouldTrip(new IgnoreMeException('Connection timed out')));
    }
}

final class CustomProviderException extends RuntimeException
{
}

final class IgnoreMeException extends RuntimeException
{
}
