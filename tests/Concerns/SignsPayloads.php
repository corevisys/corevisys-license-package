<?php

namespace CoreVisys\License\Tests\Concerns;

/**
 * Generates an RSA keypair and produces CoreVisys-style signed response
 * envelopes for use in tests, without ever calling a live server.
 */
trait SignsPayloads
{
    protected ?array $keyPair = null;

    protected function keyPair(): array
    {
        if ($this->keyPair) {
            return $this->keyPair;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        return $this->keyPair = [
            'private' => $privateKey,
            'public' => $details['key'],
        ];
    }

    protected function signedEnvelope(array $data, string $keyId = 'test-key-1', bool $corruptSignature = false): array
    {
        ksort($data);
        $canonical = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        openssl_sign($canonical, $signature, $this->keyPair()['private'], OPENSSL_ALGO_SHA256);

        $encoded = base64_encode($signature);

        if ($corruptSignature) {
            $encoded = base64_encode($signature.'x');
        }

        return [
            'success' => true,
            'message' => 'ok',
            'data' => $data,
            'signature' => $encoded,
            'key_id' => $keyId,
        ];
    }

    protected function publicKeyResponse(string $keyId = 'test-key-1'): array
    {
        return [
            'public_key' => $this->keyPair()['public'],
            'key_id' => $keyId,
        ];
    }
}
