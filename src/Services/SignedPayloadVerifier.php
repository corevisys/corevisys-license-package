<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Exceptions\SignatureVerificationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies that a LicenseResponse was genuinely signed by CoreVisys, using
 * the server's published public key. Fails closed: any missing signature,
 * unresolvable key, algorithm mismatch, or bad signature is rejected.
 */
class SignedPayloadVerifier
{
    public function __construct(
        protected array $config,
        protected LicenseStorageInterface $storage,
        protected string $serverUrl,
        protected string $apiVersion,
    ) {
    }

    /**
     * @throws SignatureVerificationException
     */
    public function verify(LicenseResponse $response): void
    {
        if (empty($response->signature)) {
            throw new SignatureVerificationException('The server response was not signed.');
        }

        if (empty($response->keyId)) {
            throw new SignatureVerificationException('The server response is missing a key identifier.');
        }

        $publicKey = $this->resolvePublicKey($response->keyId);

        if (! $publicKey) {
            throw new SignatureVerificationException('Unable to resolve the public key needed to verify this response.');
        }

        $algorithm = $this->config['algorithm'] ?? 'rsa';
        $canonicalJson = $response->canonicalDataJson();
        $signature = base64_decode($response->signature, true);

        if ($signature === false) {
            throw new SignatureVerificationException('The response signature is not validly encoded.');
        }

        $verified = match ($algorithm) {
            'ed25519' => $this->verifyEd25519($canonicalJson, $signature, $publicKey),
            default => $this->verifyRsa($canonicalJson, $signature, $publicKey),
        };

        if (! $verified) {
            throw new SignatureVerificationException('The license response signature is invalid.');
        }

        $this->assertFreshTimestamp($response);
    }

    protected function verifyRsa(string $data, string $signature, string $publicKeyPem): bool
    {
        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            return false;
        }

        $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    protected function verifyEd25519(string $data, string $signature, string $publicKeyPem): bool
    {
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new SignatureVerificationException('Ed25519 verification requires the sodium extension.');
        }

        // Accept either a raw 32-byte base64 key or a PEM-wrapped SPKI key.
        $rawKey = $this->extractRawEd25519Key($publicKeyPem);

        try {
            return sodium_crypto_sign_verify_detached($signature, $data, $rawKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    protected function extractRawEd25519Key(string $key): string
    {
        $trimmed = trim($key);

        if (! str_contains($trimmed, 'BEGIN PUBLIC KEY')) {
            $decoded = base64_decode($trimmed, true);

            return $decoded !== false ? $decoded : $trimmed;
        }

        // Strip PEM armor; last 32 bytes of the DER-encoded SPKI blob are the raw key.
        $lines = preg_replace('/-----[^-]+-----/', '', $trimmed);
        $der = base64_decode(str_replace(["\r", "\n"], '', $lines), true);

        return $der !== false ? substr($der, -32) : $trimmed;
    }

    protected function assertFreshTimestamp(LicenseResponse $response): void
    {
        $timestamp = $response->get('checked_at') ?? $response->get('server_time');

        if (! $timestamp) {
            return;
        }

        $tolerance = $this->config['timestamp_tolerance'] ?? 300;

        try {
            $serverTime = \Carbon\Carbon::parse($timestamp);
        } catch (\Throwable) {
            throw new SignatureVerificationException('The response timestamp could not be parsed.');
        }

        if (abs(now()->diffInSeconds($serverTime, false)) > $tolerance) {
            throw new SignatureVerificationException('The response timestamp is outside the allowed tolerance (possible replay).');
        }
    }

    protected function resolvePublicKey(string $keyId): ?string
    {
        $cached = $this->storage->getPublicKey($keyId);

        if ($cached) {
            return $cached;
        }

        $fetched = $this->fetchPublicKeyFromServer();

        if (! $fetched) {
            return null;
        }

        [$fetchedKeyId, $pem] = $fetched;

        $ttl = $this->config['public_key_cache_ttl'] ?? 86400;
        $this->storage->putPublicKey($fetchedKeyId, $pem, $ttl);

        return $fetchedKeyId === $keyId ? $pem : null;
    }

    /**
     * @return array{0: string, 1: string}|null [key_id, pem]
     */
    protected function fetchPublicKeyFromServer(): ?array
    {
        try {
            $response = Http::timeout(config('corevisys-license.connection_timeout', 10))
                ->withOptions(['verify' => config('corevisys-license.verify_ssl', true)])
                ->get(rtrim($this->serverUrl, '/')."/api/{$this->apiVersion}/license/public-key");

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            $pem = $body['public_key'] ?? $body['data']['public_key'] ?? null;
            $keyId = $body['key_id'] ?? $body['data']['key_id'] ?? null;

            if (! $pem || ! $keyId) {
                return null;
            }

            return [$keyId, $pem];
        } catch (\Throwable $e) {
            if (config('corevisys-license.logging.enabled', true)) {
                Log::channel(config('corevisys-license.logging.channel', 'stack'))
                    ->warning('CoreVisys license: failed to fetch public key.', ['error' => $e->getMessage()]);
            }

            return null;
        }
    }
}
