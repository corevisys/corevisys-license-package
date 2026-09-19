<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Mockery;

class MiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->middleware('corevisys.license')->get('/protected', fn () => 'ok');
        $router->middleware('corevisys.feature:reports')->get('/reports', fn () => 'reports-ok');
    }

    public function test_middleware_allows_valid_license(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(true);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/protected')->assertOk()->assertSee('ok');
    }

    public function test_middleware_blocks_invalid_license(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(false);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/protected')->assertStatus(403);
    }

    public function test_feature_middleware_blocks_missing_feature(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(true);
        $mock->shouldReceive('feature')->with('reports')->andReturn(false);
        $mock->shouldReceive('status')->andReturn(null);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/reports')->assertStatus(403);
    }

    public function test_feature_middleware_allows_licensed_feature(): void
    {
        $mock = Mockery::mock(LicenseClientInterface::class);
        $mock->shouldReceive('isValid')->andReturn(true);
        $mock->shouldReceive('feature')->with('reports')->andReturn(true);
        $this->app->instance(LicenseClientInterface::class, $mock);

        $this->get('/reports')->assertOk()->assertSee('reports-ok');
    }
}
