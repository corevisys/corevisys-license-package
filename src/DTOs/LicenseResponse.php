<?php

namespace CoreVisys\License\DTOs;

use Carbon\Carbon;

/**
 * Raw-but-typed mapping of a CoreVisys API response envelope
 * ({success, message, data, signature, key_id}), before signature
 * verification decides whether it can be trusted.
 */
final class LicenseResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $message,
        public readonly array $data,
        public readonly ?string $signature,
        public readonly ?string $keyId,
        public readonly array $raw,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        return new self(
            success: (bool) ($payload['success'] ?? false),
            message: $payload['message'] ?? null,
            data: $payload['data'] ?? [],
            signature: $payload['signature'] ?? null,
            keyId: $payload['key_id'] ?? null,
            raw: $payload,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * The exact byte-for-byte JSON that the signature was computed over.
     * CoreVisys signs the `data` object using canonical (key-sorted) JSON.
     */
    public function canonicalDataJson(): string
    {
        $data = $this->data;
        ksort($data);

        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function toLicenseStatus(bool $fromCache = false, bool $offline = false): LicenseStatus
    {
        $expiresAt = $this->get('expires_at') ? Carbon::parse($this->get('expires_at')) : null;
        $graceExpiresAt = $this->get('grace_expires_at') ? Carbon::parse($this->get('grace_expires_at')) : null;
        $serverTime = $this->get('checked_at') ?? $this->get('server_time');

        $status = $this->get('status', 'unknown');

        return new LicenseStatus(
            valid: $this->success && $status === 'active',
            status: $status,
            licenseId: $this->get('license_id'),
            licenseType: $this->get('type', $this->get('license_type')),
            productCode: $this->get('product_code'),
            boundDomain: $this->get('domain', $this->get('bound_domain')),
            expiresAt: $expiresAt,
            graceExpiresAt: $graceExpiresAt,
            features: $this->get('features', []),
            serverTime: $serverTime ? Carbon::parse($serverTime) : null,
            checkedAt: Carbon::now(),
            fromCache: $fromCache,
            offline: $offline,
            keyId: $this->keyId,
            signature: $this->signature,
        );
    }
}
