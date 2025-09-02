<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\VeVsApi;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind VeVsApi as a singleton so the same instance is reused app-wide.
        $this->app->singleton(VeVsApi::class, function ($app) {
            // Allow both 'base_url' (preferred) and 'url' as fallbacks.
            $cfg = (array) config('services.vevs', []);

            $baseUrl = (string) ($cfg['base_url'] ?? $cfg['url'] ?? env('VEVS_BASE_URL', ''));
            $apiKey  = (string) ($cfg['api_key'] ?? env('VEVS_API_KEY', ''));

            if ($baseUrl === '') {
                throw new \RuntimeException(
                    'Missing VEVS base URL. Set services.vevs.base_url in config/services.php ' .
                    'or VEVS_BASE_URL in your .env (include the API key in the path if required).'
                );
            }

            return new VeVsApi($baseUrl, $apiKey ?: null);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Place per-environment bootstrap tweaks here if needed.
        // e.g. force HTTPS in production, macros, etc.
    }
}
