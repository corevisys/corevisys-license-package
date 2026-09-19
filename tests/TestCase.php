<?php

namespace CoreVisys\License\Tests;

use CoreVisys\License\CoreVisysServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CoreVisysServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('corevisys-license.server_url', 'https://license.test');
        $app['config']->set('corevisys-license.product_code', 'test-product');
        $app['config']->set('corevisys-license.license_key', 'TEST-KEY-0000');
        $app['config']->set('corevisys-license.verify_ssl', true);
        $app['config']->set('corevisys-license.retry.times', 1);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
