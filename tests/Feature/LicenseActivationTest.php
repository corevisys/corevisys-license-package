<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class LicenseActivationTest extends TestCase
{
    use SignsPayloads;

    public function test_valid_license_activation_succeeds(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_123',
                'status' => 'active',
                'type' => 'subscription',
                'product_code' => 'test-product',
                'domain' => 'license.test',
                'expires_at' => now()->addYear()->toIso8601String(),
                'grace_expires_at' => null,
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('VALID-KEY-1234');

        $this->assertTrue($result->success);
        $this->assertTrue($result->status->isActive());
    }

    public function test_invalid_license_activation_is_rejected(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'License key not found.'], 404),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('BAD-KEY');

        $this->assertFalse($result->success);
    }

    public function test_activation_limit_exceeded_returns_failure(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'Activation limit exceeded.'], 409),
        ]);

        $result = $this->app->make(LicenseClientInterface::class)->activate('OVER-LIMIT-KEY');

        $this->assertFalse($result->success);
        $this->assertSame('activation_limit_exceeded', $result->errorCode);
    }

    public function test_license_key_never_appears_in_logs(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'error'], 500),
        ]);

        $loggedLines = [];

        \Illuminate\Support\Facades\Log::listen(function ($event) use (&$loggedLines) {
            $loggedLines[] = $event->message.' '.json_encode($event->context);
        });

        $this->app->make(LicenseClientInterface::class)->activate('SECRET-KEY-DO-NOT-LOG');

        $this->assertNotEmpty($loggedLines, 'Expected the failed activation to produce at least one log line to check.');

        foreach ($loggedLines as $line) {
            $this->assertStringNotContainsString('SECRET-KEY-DO-NOT-LOG', $line);
        }
    }
}
