<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class HoldsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Stop loading holds routes here (they’re defined in routes/api.php)
        // $this->loadRoutesFrom(base_path('routes/holds_api.php'));
        // $this->loadRoutesFrom(base_path('routes/holds_web.php'));

        // Views are fine to load here
        $this->loadViewsFrom(resource_path('views/holds'), 'holds');
    }
}
