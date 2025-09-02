<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Determine where to redirect unauthenticated users.
     * Return null for API/JSON so Laravel responds 401 instead of redirecting.
     */
    protected function redirectTo($request): ?string
    {
        if ($this->shouldBypassRedirect($request)) {
            return null;
        }

        // If this is a portal/customer area, send to portal login
        if ($this->isPortalArea($request)) {
            return $this->portalLoginUrl($request);
        }

        // Optional: if you use Filament/admin, try that login first
        if (app('router')->has('filament.auth.login')) {
            return route('filament.auth.login');
        }

        // Fallback to generic app login if you have one…
        if (app('router')->has('login')) {
            return route('login');
        }

        // …or a hard URL as last resort
        return url('/login');
    }

    /**
     * Return true if we should NOT redirect (so Laravel returns 401 JSON).
     */
    protected function shouldBypassRedirect(Request $request): bool
    {
        // API & JSON/XHR requests should get 401, not a redirect
        if ($request->expectsJson() || $request->is('api/*')) {
            return true;
        }

        // Never redirect webhooks or similar machine endpoints
        if (
            $request->routeIs('stripe.webhook') ||
            $request->is('stripe/webhook') ||
            $request->is('webhooks/*')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Identify portal/customer routes that should use the portal login.
     */
    protected function isPortalArea(Request $request): bool
    {
        return $request->is('p*')
            || $request->routeIs('portal.*')
            || $request->routeIs('customer.*');
    }

    /**
     * Build the portal login URL, including an `intended` parameter when useful.
     */
    protected function portalLoginUrl(Request $request): string
    {
        $hasPortalLogin = app('router')->has('portal.login');

        // Don’t add ?intended when already on/heading to login pages to avoid loops
        $isOnLoginRoute = $request->routeIs('portal.login')
            || $request->is('p/login')
            || $request->is('p/login/*');

        $intended = null;
        if ($request->method() === 'GET' && ! $isOnLoginRoute) {
            // Only allow relative URLs for safety (avoid open redirects)
            $full = $request->fullUrl();
            if (str_starts_with(parse_url($full, PHP_URL_PATH) ?? '/', '/')) {
                $intended = $full;
            }
        }

        if ($hasPortalLogin) {
            return $intended
                ? route('portal.login', ['intended' => $intended])
                : route('portal.login');
        }

        // Hard URL fallback for MVPs without a named route
        return $intended
            ? url('/p/login?'.http_build_query(['intended' => $intended]))
            : url('/p/login');
    }
}
