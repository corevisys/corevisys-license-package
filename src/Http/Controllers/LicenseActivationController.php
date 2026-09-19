<?php

namespace CoreVisys\License\Http\Controllers;

use CoreVisys\License\Contracts\LicenseClientInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Renders and processes the built-in "activate your license" screen, for
 * end users who will never touch an artisan command. Registered by
 * CoreVisysServiceProvider when config('corevisys-license.ui.enabled')
 * is true; publish the view (corevisys-license::activate) to restyle it,
 * or set ui.enabled to false and build your own screen against the
 * CoreVisysLicense facade instead.
 */
class LicenseActivationController extends Controller
{
    public function __construct(protected LicenseClientInterface $license)
    {
    }

    public function show(Request $request): View
    {
        // A fresh check() (not force-refreshed) so a freshly-expired/revoked
        // license shows its real reason on the page without hammering the
        // server on every page load.
        $status = $this->license->status() ?? $this->license->check();

        return view('corevisys-license::activate', [
            'status' => $status,
            'routeName' => config('corevisys-license.ui.route_name'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'license_key' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->license->activate($validated['license_key']);

        $routeName = config('corevisys-license.ui.route_name');

        if (! $result->success) {
            return redirect()
                ->route($routeName)
                ->withErrors(['license_key' => $result->message ?? 'Activation failed. Please check the key and try again.'])
                ->withInput();
        }

        return redirect()
            ->route($routeName)
            ->with('corevisys_license_activated', true);
    }
}
