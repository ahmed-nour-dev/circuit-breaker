<?php

declare(strict_types=1);

namespace AhmedNour\CircuitBreaker\Tests\Unit\Application;

use AhmedNour\CircuitBreaker\Application\CircuitPolicyResolver;
use AhmedNour\CircuitBreaker\Domain\CircuitPolicy;
use PHPUnit\Framework\TestCase;

final class CircuitPolicyResolverTest extends TestCase
{
    public function test_resolves_custom_provider_policy(): void
    {
        $resolver = new CircuitPolicyResolver(
            providers: [
                'twilio-sms' => [
                    'failure_threshold' => 10,
                    'recovery_timeout' => 300,
                    'half_open_lock_timeout' => 15,
                ],
            ],
            defaultPolicy: new CircuitPolicy(5, 60, 10),
        );

        $policy = $resolver->resolve('twilio-sms');

        $this->assertSame(10, $policy->failureThreshold);
        $this->assertSame(300, $policy->recoveryTimeout);
        $this->assertSame(15, $policy->halfOpenLockTimeout);
    }

    public function test_falls_back_to_default_policy_when_provider_not_configured(): void
    {
        $resolver = new CircuitPolicyResolver(
            providers: [],
            defaultPolicy: new CircuitPolicy(5, 60, 10),
        );

        $policy = $resolver->resolve('unknown-provider');

        $this->assertSame(5, $policy->failureThreshold);
        $this->assertSame(60, $policy->recoveryTimeout);
        $this->assertSame(60, $policy->halfOpenLockTimeout);
    }

    public function test_falls_back_to_defaults_for_missing_keys_in_provider_config(): void
    {
        $resolver = new CircuitPolicyResolver(
            providers: [
                'payment-gateway' => [
                    'failure_threshold' => 2,
                    // recovery_timeout and half_open_lock_timeout missing
                ],
            ],
            defaultPolicy: new CircuitPolicy(5, 120, 20),
        );

        $policy = $resolver->resolve('payment-gateway');

        $this->assertSame(2, $policy->failureThreshold);
        $this->assertSame(120, $policy->recoveryTimeout);
        $this->assertSame(20, $policy->halfOpenLockTimeout);
    }
}
