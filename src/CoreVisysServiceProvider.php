<?php

namespace CoreVisys\License;

use CoreVisys\License\Commands\LicenseActivateCommand;
use CoreVisys\License\Commands\LicenseCheckCommand;
use CoreVisys\License\Commands\LicenseClearCacheCommand;
use CoreVisys\License\Commands\LicenseDeactivateCommand;
use CoreVisys\License\Commands\LicenseInstallCommand;
use CoreVisys\License\Commands\LicenseStatusCommand;
use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Middleware\EnsureLicenseFeature;
use CoreVisys\License\Middleware\EnsureValidLicense;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Services\LicenseActivator;
use CoreVisys\License\Services\LicenseClient;
use CoreVisys\License\Services\LicenseHeartbeat;
use CoreVisys\License\Services\LicenseStorage;
use CoreVisys\License\Services\LicenseVerifier;
use CoreVisys\License\Services\SignedPayloadVerifier;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class CoreVisysServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/corevisys-license.php', 'corevisys-license');

        $this->app->singleton(LicenseStorageInterface::class, function ($app) {
            return new LicenseStorage($app['config']->get('corevisys-license'));
        });

        $this->app->singleton(FingerprintGenerator::class, function ($app) {
            return new FingerprintGenerator($app['config']->get('corevisys-license.fingerprint', []));
        });

        $this->app->singleton(ApiRequestHandler::class, function ($app) {
            $config = $app['config']->get('corevisys-license');

            return new ApiRequestHandler(
                serverUrl: $config['server_url'],
                apiVersion: $config['api_version'],
                config: $config,
            );
        });

        $this->app->singleton(SignedPayloadVerifier::class, function ($app) {
            $config = $app['config']->get('corevisys-license');

            return new SignedPayloadVerifier(
                config: $config['signature'] ?? [],
                storage: $app->make(LicenseStorageInterface::class),
                serverUrl: $config['server_url'],
                apiVersion: $config['api_version'],
            );
        });

        $this->app->singleton(LicenseActivator::class, function ($app) {
            return new LicenseActivator(
                api: $app->make(ApiRequestHandler::class),
                signatureVerifier: $app->make(SignedPayloadVerifier::class),
                fingerprint: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
            );
        });

        $this->app->singleton(LicenseVerifier::class, function ($app) {
            return new LicenseVerifier(
                api: $app->make(ApiRequestHandler::class),
                signatureVerifier: $app->make(SignedPayloadVerifier::class),
                fingerprint: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
                config: $app['config']->get('corevisys-license'),
            );
        });

        $this->app->singleton(LicenseHeartbeat::class, function ($app) {
            return new LicenseHeartbeat(
                api: $app->make(ApiRequestHandler::class),
                signatureVerifier: $app->make(SignedPayloadVerifier::class),
                fingerprint: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
            );
        });

        $this->app->singleton(LicenseClientInterface::class, function ($app) {
            return new LicenseClient(
                activator: $app->make(LicenseActivator::class),
                verifier: $app->make(LicenseVerifier::class),
                heartbeat: $app->make(LicenseHeartbeat::class),
                fingerprintGenerator: $app->make(FingerprintGenerator::class),
                storage: $app->make(LicenseStorageInterface::class),
                productCode: (string) $app['config']->get('corevisys-license.product_code'),
                configuredLicenseKey: $app['config']->get('corevisys-license.license_key'),
            );
        });

        $this->app->alias(LicenseClientInterface::class, 'corevisys.license');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/corevisys-license.php' => config_path('corevisys-license.php'),
        ], 'corevisys-license-config');

        $this->publishes([
            __DIR__.'/../database/migrations/create_corevisys_license_cache_table.php' =>
                database_path('migrations/'.date('Y_m_d_His', time()).'_create_corevisys_license_cache_table.php'),
        ], 'corevisys-license-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'corevisys-license');

        $this->registerMiddlewareAliases();

        if ($this->app->runningInConsole()) {
            $this->commands([
                LicenseInstallCommand::class,
                LicenseActivateCommand::class,
                LicenseCheckCommand::class,
                LicenseDeactivateCommand::class,
                LicenseStatusCommand::class,
                LicenseClearCacheCommand::class,
            ]);

            $this->app->booted(function () {
                $this->scheduleLicenseCheck();
            });
        }
    }

    protected function registerMiddlewareAliases(): void
    {
        /** @var \Illuminate\Routing\Router $router */
        $router = $this->app['router'];

        $router->aliasMiddleware('corevisys.license', EnsureValidLicense::class);
        $router->aliasMiddleware('corevisys.feature', EnsureLicenseFeature::class);
    }

    /**
     * Registers a daily (or config-driven) license check on the app's own
     * scheduler, using withoutOverlapping() so a slow check never stacks
     * concurrent requests against the license server.
     */
    protected function scheduleLicenseCheck(): void
    {
        if (! $this->app['config']->get('corevisys-license.auto_check', true)) {
            return;
        }

        if (! $this->app->bound(Schedule::class)) {
            return;
        }

        $intervalSeconds = (int) $this->app['config']->get('corevisys-license.check_interval', 86400);

        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $schedule->command('corevisys:license:check')
            ->cron($this->cronExpressionForInterval($intervalSeconds))
            ->withoutOverlapping()
            ->onOneServer();
    }

    protected function cronExpressionForInterval(int $seconds): string
    {
        return match (true) {
            $seconds <= 3600 => '* * * * *', // every minute floor; Laravel's minimum granularity
            $seconds <= 21600 => '0 */6 * * *', // every 6 hours
            default => '0 3 * * *', // daily at 03:00
        };
    }
}
