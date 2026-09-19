<?php

namespace CoreVisys\License\Middleware;

use Closure;
use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Exceptions\InvalidLicenseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: corevisys.license
 *
 * Blocks the request unless the license is currently valid. Bypass is
 * available for local development only (config: middleware.bypass_in_local)
 * and is always logged — it can never be enabled in production.
 */
class EnsureValidLicense
{
    public function __construct(protected LicenseClientInterface $license)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypass()) {
            Log::channel(config('corevisys-license.logging.channel', 'stack'))
                ->warning('CoreVisys license: middleware bypassed in local environment.');

            return $next($request);
        }

        if ($this->license->isValid()) {
            return $next($request);
        }

        return $this->deny($request);
    }

    protected function shouldBypass(): bool
    {
        return app()->environment('local')
            && ! app()->environment('production')
            && config('corevisys-license.middleware.bypass_in_local', false);
    }

    protected function deny(Request $request): Response
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            throw new InvalidLicenseException('This command cannot run without a valid license.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'A valid license is required to access this resource.',
                'error_code' => 'invalid_license',
            ], config('corevisys-license.middleware.abort_status', 403));
        }

        $redirect = config('corevisys-license.middleware.redirect_route');

        if ($redirect && \Illuminate\Support\Facades\Route::has($redirect)) {
            return redirect()->route($redirect);
        }

        abort(config('corevisys-license.middleware.abort_status', 403), 'A valid license is required to access this resource.');
    }
}
