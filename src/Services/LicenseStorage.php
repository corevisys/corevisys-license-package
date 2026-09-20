<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Local persistence for verified license state and the cached CoreVisys
 * public key(s). Two backends are supported: "database" (the
 * corevisys_license_cache table) and "cache" (Laravel's cache store).
 * The license key itself is always stored encrypted and is never logged.
 */
class LicenseStorage implements LicenseStorageInterface
{
    protected const TABLE = 'corevisys_license_cache';

    public function __construct(protected array $config)
    {
    }

    public function get(string $productCode): ?array
    {
        return $this->usingDatabase()
            ? $this->getFromDatabase($productCode)
            : $this->getFromCacheStore($productCode);
    }

    public function put(string $productCode, array $attributes): void
    {
        if (isset($attributes['license_key'])) {
            $attributes['encrypted_license_key'] = $attributes['license_key']
                ? Crypt::encryptString($attributes['license_key'])
                : null;
            unset($attributes['license_key']);
        }

        $attributes['product_code'] = $productCode;
        $attributes['last_checked_at'] = $attributes['last_checked_at'] ?? now();

        if ($this->usingDatabase()) {
            $this->putInDatabase($productCode, $attributes);
        } else {
            $this->putInCacheStore($productCode, $attributes);
        }
    }

    public function forget(string $productCode): void
    {
        if ($this->usingDatabase()) {
            DB::table(self::TABLE)->where('product_code', $productCode)->delete();
        } else {
            Cache::store($this->cacheStoreName())->forget($this->cacheKey($productCode));
        }
    }

    public function getPublicKey(string $keyId): ?string
    {
        return Cache::store($this->cacheStoreName())->get($this->publicKeyCacheKey($keyId));
    }

    public function putPublicKey(string $keyId, string $publicKeyPem, int $ttlSeconds): void
    {
        Cache::store($this->cacheStoreName())->put($this->publicKeyCacheKey($keyId), $publicKeyPem, $ttlSeconds);
    }

    public function getPublicKeyMetadata(): ?array
    {
        $metadata = Cache::store($this->cacheStoreName())->get($this->publicKeyMetadataCacheKey());

        return is_array($metadata) ? $metadata : null;
    }

    public function putPublicKeyMetadata(array $metadata, int $ttlSeconds): void
    {
        Cache::store($this->cacheStoreName())->put($this->publicKeyMetadataCacheKey(), $metadata, $ttlSeconds);
    }

    /**
     * Decrypt the stored license key from a raw cache record, returning
     * null if there isn't one or it fails to decrypt (e.g. APP_KEY rotated
     * or the row was tampered with).
     */
    public function decryptLicenseKey(?array $record): ?string
    {
        if (! $record || empty($record['encrypted_license_key'])) {
            return null;
        }

        try {
            return Crypt::decryptString($record['encrypted_license_key']);
        } catch (DecryptException) {
            return null;
        }
    }

    protected function usingDatabase(): bool
    {
        return ($this->config['cache_driver'] ?? 'database') === 'database';
    }

    protected function cacheStoreName(): ?string
    {
        return $this->config['cache_store'] ?? null;
    }

    protected function cacheKey(string $productCode): string
    {
        return ($this->config['cache_key'] ?? 'corevisys.license.cache').':'.$productCode;
    }

    protected function publicKeyCacheKey(string $keyId): string
    {
        return ($this->config['public_key_cache_key'] ?? 'corevisys.license.public_key').':'.$keyId;
    }

    protected function publicKeyMetadataCacheKey(): string
    {
        return ($this->config['public_key_cache_key'] ?? 'corevisys.license.public_key').':metadata';
    }

    protected function getFromDatabase(string $productCode): ?array
    {
        $row = DB::table(self::TABLE)
            ->where('product_code', $productCode)
            ->orderByDesc('id')
            ->first();

        return $row ? (array) $row : null;
    }

    protected function putInDatabase(string $productCode, array $attributes): void
    {
        $existing = DB::table(self::TABLE)->where('product_code', $productCode)->orderByDesc('id')->first();

        // Only touch `features` when the caller actually provided it — a
        // partial update (e.g. just recording last_error_message) must
        // never silently wipe out a previously stored features list.
        if (array_key_exists('features', $attributes)) {
            $attributes['features'] = json_encode($attributes['features']);
        }

        $attributes['updated_at'] = now();

        if ($existing) {
            DB::table(self::TABLE)->where('id', $existing->id)->update($this->serializeDatesForDatabase($attributes));
        } else {
            $attributes['created_at'] = now();
            DB::table(self::TABLE)->insert($this->serializeDatesForDatabase($attributes));
        }
    }

    /**
     * PDO cannot bind DateTimeInterface objects (Carbon included) directly —
     * it throws "Object of class Carbon\Carbon could not be converted to
     * string". Every column that may carry a Carbon instance is normalized
     * to a plain datetime string before the raw insert/update.
     */
    protected function serializeDatesForDatabase(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $attributes[$key] = $value->format('Y-m-d H:i:s');
            }
        }

        return $attributes;
    }

    protected function getFromCacheStore(string $productCode): ?array
    {
        return Cache::store($this->cacheStoreName())->get($this->cacheKey($productCode));
    }

    protected function putInCacheStore(string $productCode, array $attributes): void
    {
        // No natural TTL here — the license lifecycle (grace period, expiry)
        // governs validity, not the cache store's own expiration.
        $store = Cache::store($this->cacheStoreName());
        $existing = $store->get($this->cacheKey($productCode), []);
        $store->forever($this->cacheKey($productCode), array_merge(is_array($existing) ? $existing : [], $attributes));
    }
}
