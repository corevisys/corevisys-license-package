<?php

namespace CoreVisys\License\Contracts;

interface LicenseStorageInterface
{
    /**
     * Fetch the latest raw cache record for the given product code,
     * or null if nothing has ever been cached.
     */
    public function get(string $productCode): ?array;

    /**
     * Persist a verified license record. $attributes matches the
     * corevisys_license_cache table columns.
     */
    public function put(string $productCode, array $attributes): void;

    /**
     * Remove all cached data (license record + cached public key) for
     * the given product code.
     */
    public function forget(string $productCode): void;

    public function getPublicKey(string $keyId): ?string;

    public function putPublicKey(string $keyId, string $publicKeyPem, int $ttlSeconds): void;
}
