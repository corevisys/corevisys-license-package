<?php

namespace CoreVisys\License\Services;

use Illuminate\Support\Facades\Request;

/**
 * Produces a deterministic, non-reversible fingerprint for this
 * installation. The raw inputs (domain, IP, etc.) are never sent to the
 * API or written to logs — only the resulting hash ever leaves this class.
 */
class FingerprintGenerator
{
    public function __construct(protected array $config)
    {
    }

    public function generate(): string
    {
        $parts = $this->collectParts();
        $payload = implode('|', $parts);

        $algorithm = $this->config['algorithm'] ?? 'sha256';

        if ($algorithm === 'hmac-sha256') {
            $secret = $this->config['hmac_secret'] ?? '';

            return hash_hmac('sha256', $payload, (string) $secret);
        }

        return hash('sha256', $payload);
    }

    /**
     * @return string[] Ordered, normalized inputs that feed the hash.
     */
    protected function collectParts(): array
    {
        $parts = [];

        if ($this->config['include_domain'] ?? true) {
            $parts[] = 'domain:'.$this->normalizedDomain();
        }

        if ($this->config['include_ip'] ?? true) {
            $parts[] = 'ip:'.$this->serverIp();
        }

        $parts[] = 'product:'.(config('corevisys-license.product_code') ?? '');
        $parts[] = 'env:'.app()->environment();

        if ($this->config['include_app_key'] ?? false) {
            $parts[] = 'app_key:'.substr((string) config('app.key'), 0, 16);
        }

        if ($this->config['include_machine_data'] ?? false) {
            $parts[] = 'machine:'.php_uname('n');
        }

        return $parts;
    }

    public function normalizedDomain(): string
    {
        $url = config('app.url', '');

        if (app()->runningInConsole() === false) {
            try {
                $url = Request::getHost() ?: $url;
            } catch (\Throwable) {
                // fall back to config('app.url')
            }
        }

        $domain = (string) parse_url($url, PHP_URL_HOST) ?: (string) $url;
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '/');

        if (($this->config['strip_www'] ?? true) && str_starts_with($domain, 'www.')) {
            $domain = substr($domain, 4);
        }

        return $domain;
    }

    protected function serverIp(): string
    {
        return (string) (
            $_SERVER['SERVER_ADDR']
            ?? gethostbyname(gethostname() ?: 'localhost')
            ?? '127.0.0.1'
        );
    }
}
