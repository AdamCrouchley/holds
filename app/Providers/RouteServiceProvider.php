<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // App routes
        Route::middleware('web')->group(base_path('routes/web.php'));
        Route::prefix('api')->middleware('api')->group(base_path('routes/api.php'));

        // HOLDS web (optional)
        if (file_exists(base_path('routes/holds_web.php'))) {
            Route::middleware('web')->group(base_path('routes/holds_web.php'));
        }

        // HOLDS api (your file already contains its own prefix/middleware)
        if (file_exists(base_path('routes/holds_api.php'))) {
            require base_path('routes/holds_api.php');
        }
    }
}
