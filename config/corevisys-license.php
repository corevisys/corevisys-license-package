<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CoreVisys License Server
    |--------------------------------------------------------------------------
    */
    'server_url' => env('COREVISYS_LICENSE_SERVER_URL', 'https://license.corevisys.com'),

    'product_code' => env('COREVISYS_PRODUCT_CODE'),

    'license_key' => env('COREVISYS_LICENSE_KEY'),

    'api_version' => env('COREVISYS_LICENSE_API_VERSION', 'v1'),

    'client_version' => env('COREVISYS_LICENSE_CLIENT_VERSION', '1.0.0'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Client
    |--------------------------------------------------------------------------
    */
    'connection_timeout' => env('COREVISYS_LICENSE_TIMEOUT', 10),

    'verify_ssl' => env('COREVISYS_LICENSE_VERIFY_SSL', true),

    'max_response_bytes' => env('COREVISYS_LICENSE_MAX_RESPONSE_BYTES', 1_048_576),

    'retry' => [
        'times' => env('COREVISYS_LICENSE_RETRY_TIMES', 3),
        'base_delay_ms' => env('COREVISYS_LICENSE_RETRY_BASE_DELAY_MS', 250),
        'max_delay_ms' => env('COREVISYS_LICENSE_RETRY_MAX_DELAY_MS', 4000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Activation / Check Behavior
    |--------------------------------------------------------------------------
    */
    'auto_activate' => env('COREVISYS_LICENSE_AUTO_ACTIVATE', false),

    'auto_check' => env('COREVISYS_LICENSE_AUTO_CHECK', true),

    'check_interval' => env('COREVISYS_LICENSE_CHECK_INTERVAL', 86400), // seconds

    // Local offline grace may shorten the server-issued offline_valid_until
    // boundary; it can never extend that server boundary.
    'grace_period' => env('COREVISYS_LICENSE_GRACE_PERIOD', 72), // hours

    'allow_offline_verification' => env('COREVISYS_LICENSE_ALLOW_OFFLINE', true),

    /*
    |--------------------------------------------------------------------------
    | Local Cache Storage
    |--------------------------------------------------------------------------
    | "database" (default, uses corevisys_license_cache table) or "cache"
    | (uses Laravel's cache store — see cache_store below).
    */
    'cache_driver' => env('COREVISYS_LICENSE_CACHE_DRIVER', 'database'),

    'cache_store' => env('COREVISYS_LICENSE_CACHE_STORE', null), // null = default store

    'cache_key' => 'corevisys.license.cache',

    'public_key_cache_key' => 'corevisys.license.public_key',

    /*
    |--------------------------------------------------------------------------
    | Fingerprinting
    |--------------------------------------------------------------------------
    */
    'fingerprint' => [
        'algorithm' => env('COREVISYS_LICENSE_FINGERPRINT_ALGO', 'sha256'), // sha256 | hmac-sha256
        'hmac_secret' => env('COREVISYS_LICENSE_FINGERPRINT_SECRET'),
        'include_domain' => true,
        'include_ip' => true,
        'include_app_key' => false,
        'include_machine_data' => false,
        'strip_www' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature Verification
    |--------------------------------------------------------------------------
    */
    'signature' => [
        'algorithm' => env('COREVISYS_LICENSE_SIGNATURE_ALGO', 'rsa'), // rsa only
        'timestamp_tolerance' => env('COREVISYS_LICENSE_TIMESTAMP_TOLERANCE', 300), // seconds
        'public_key_cache_ttl' => env('COREVISYS_LICENSE_PUBLIC_KEY_TTL', 86400), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    */
    'middleware' => [
        'redirect_route' => null,
        'abort_status' => 403,
        'bypass_in_local' => env('COREVISYS_LICENSE_BYPASS_LOCAL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in Activation Screen
    |--------------------------------------------------------------------------
    | End users installing your application will never run an `artisan`
    | command themselves. This package can optionally register a plain
    | web page — a license-key input and an "Activate" button — that
    | POSTs to CoreVisysLicense::activate() for you. Turn it off if you'd
    | rather build your own activation UI against the facade directly.
    */
    'ui' => [
        'enabled' => env('COREVISYS_LICENSE_UI_ENABLED', true),
        'route_prefix' => env('COREVISYS_LICENSE_UI_PREFIX', 'license'),
        'route_name' => 'corevisys.license.activate',
        // Route-group middleware for the activation page itself. Add
        // 'auth' (or your own gate) here if only logged-in admins should
        // be allowed to (re-)activate the license.
        'middleware' => ['web'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */
    'logging' => [
        'enabled' => env('COREVISYS_LICENSE_LOGGING', true),
        'channel' => env('COREVISYS_LICENSE_LOG_CHANNEL', 'stack'),
    ],

];
