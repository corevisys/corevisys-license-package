<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Exceptions\ActivationLimitExceededException;
use CoreVisys\License\Exceptions\LicenseClientException;
use CoreVisys\License\Exceptions\LicenseServerUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level HTTP transport shared by the Activator, Verifier and Heartbeat
 * services. Centralizes: HTTPS-only enforcement, timeouts, exponential
 * backoff retries, response-size limiting, and per-status-code handling.
 * The license key always travels in the JSON body — never a query string —
 * and is stripped from any log line this class writes.
 */
class ApiRequestHandler
{
    public function __construct(
        protected string $serverUrl,
        protected string $apiVersion,
        protected array $config,
    ) {
    }

    /**
     * @throws LicenseClientException
     */
    public function post(string $endpoint, array $body): LicenseResponse
    {
        $this->assertHttps();

        $url = rtrim($this->serverUrl, '/')."/api/{$this->apiVersion}/{$endpoint}";
        $attempts = max(1, (int) ($this->config['retry']['times'] ?? 3));
        $baseDelay = (int) ($this->config['retry']['base_delay_ms'] ?? 250);
        $maxDelay = (int) ($this->config['retry']['max_delay_ms'] ?? 4000);

        $lastException = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::timeout($this->config['connection_timeout'] ?? 10)
                    ->withOptions(['verify' => $this->config['verify_ssl'] ?? true])
                    ->acceptJson()
                    ->post($url, $body);

                $this->assertResponseSize($response->body());

                return $this->handleStatus($response, $endpoint);
            } catch (ConnectionException|LicenseServerUnavailableException $e) {
                $lastException = $e;

                if ($attempt < $attempts) {
                    usleep(min($maxDelay, $baseDelay * (2 ** ($attempt - 1))) * 1000);

                    continue;
                }
            }
        }

        $this->log('warning', "CoreVisys license: {$endpoint} failed after retries.", $lastException);

        throw new LicenseServerUnavailableException(
            'Could not reach the CoreVisys license server.',
            $lastException
        );
    }

    protected function handleStatus(\Illuminate\Http\Client\Response $response, string $endpoint): LicenseResponse
    {
        $status = $response->status();

        if (in_array($status, [500, 502, 503, 504], true)) {
            throw new LicenseServerUnavailableException("The license server returned a {$status} error.");
        }

        if ($status === 429) {
            throw new LicenseServerUnavailableException('The license server rate-limited this request. Please retry later.');
        }

        if ($status === 409) {
            throw new ActivationLimitExceededException();
        }

        if (in_array($status, [401, 403, 404, 422], true) && ! $response->successful()) {
            $message = $response->json('message') ?? 'The license request was rejected by the server.';

            throw new LicenseClientException($message, 'request_rejected', $status, false);
        }

        if (! $response->successful()) {
            throw new LicenseClientException(
                'The license server returned an unexpected response.',
                'unexpected_response',
                $status,
                false
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new LicenseClientException('The license server response could not be parsed.', 'malformed_response', $status);
        }

        return LicenseResponse::fromArray($json);
    }

    protected function assertHttps(): void
    {
        if (! str_starts_with(strtolower($this->serverUrl), 'https://')) {
            if (app()->environment('production')) {
                throw new LicenseClientException(
                    'The CoreVisys license server URL must use HTTPS in production.',
                    'insecure_server_url',
                    500
                );
            }
        }
    }

    protected function assertResponseSize(string $body): void
    {
        $max = (int) config('corevisys-license.max_response_bytes', 1_048_576);

        if (strlen($body) > $max) {
            throw new LicenseClientException('The license server response exceeded the allowed size limit.', 'response_too_large', 502);
        }
    }

    protected function log(string $level, string $message, ?\Throwable $e = null): void
    {
        if (! config('corevisys-license.logging.enabled', true)) {
            return;
        }

        Log::channel(config('corevisys-license.logging.channel', 'stack'))->{$level}($message, [
            'error' => $e?->getMessage(),
        ]);
    }
}
