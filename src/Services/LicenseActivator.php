<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\ActivationResult;
use CoreVisys\License\Events\LicenseActivated;
use CoreVisys\License\Exceptions\LicenseClientException;
use Illuminate\Support\Facades\Event;

/**
 * Handles the one-time (or re-)activation handshake against
 * POST /license/activate.
 */
class LicenseActivator
{
    public function __construct(
        protected ApiRequestHandler $api,
        protected SignedPayloadVerifier $signatureVerifier,
        protected FingerprintGenerator $fingerprint,
        protected LicenseStorageInterface $storage,
        protected string $productCode,
    ) {
    }

    public function activate(string $licenseKey): ActivationResult
    {
        $fingerprint = $this->fingerprint->generate();

        try {
            $response = $this->api->post('license/activate', [
                'license_key' => $licenseKey,
                'product_code' => $this->productCode,
                'domain' => $this->fingerprint->normalizedDomain(),
                'ip' => request()?->ip() ?? '127.0.0.1',
                'fingerprint' => $fingerprint,
                'app_url' => config('app.url'),
                'package_version' => $this->packageVersion(),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
            ]);

            $this->signatureVerifier->verify($response);

            $status = $response->toLicenseStatus();

            if (! $response->success || ! $status->isActive()) {
                return ActivationResult::failure(
                    $response->message ?? 'Activation was rejected by the server.',
                    'activation_rejected'
                );
            }

            $this->storage->put($this->productCode, [
                'license_id' => $status->licenseId,
                'license_key' => $licenseKey,
                'status' => $status->status,
                'license_type' => $status->licenseType,
                'bound_domain' => $status->boundDomain,
                'fingerprint_hash' => $fingerprint,
                'expires_at' => $status->expiresAt,
                'grace_expires_at' => $status->graceExpiresAt,
                'issued_at' => $status->issuedAt,
                'offline_valid_until' => $status->offlineValidUntil,
                'is_grace_period' => $status->isGracePeriod,
                'features' => $status->features,
                'signed_payload' => $response->canonicalDataJson(),
                'signature' => $response->signature,
                'key_id' => $response->keyId,
                'last_successful_check_at' => now(),
                'next_check_at' => now()->addSeconds((int) config('corevisys-license.check_interval', 86400)),
                'last_error_at' => null,
                'last_error_message' => null,
            ]);

            Event::dispatch(new LicenseActivated($status));

            return ActivationResult::success($response->message ?? 'License activated successfully.', $status);
        } catch (LicenseClientException $e) {
            $this->storage->put($this->productCode, [
                'last_error_at' => now(),
                'last_error_message' => $e->getMessage(),
            ]);

            return ActivationResult::failure($e->getMessage(), $e->errorCode());
        }
    }

    protected function packageVersion(): string
    {
        return '1.0.0';
    }
}
