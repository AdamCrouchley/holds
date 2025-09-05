<?php

use Illuminate\Foundation\Application;

return (function () {
    // If we're on Laravel 11+, use the new Application::configure API.
    if (method_exists(Application::class, 'configure')) {
        return Application::configure(basePath: dirname(__DIR__))
            ->withRouting(
                web: __DIR__ . '/../routes/web.php',
                api: __DIR__ . '/../routes/api.php',   // <- ensures routes/api.php is loaded under /api
                commands: __DIR__ . '/../routes/console.php',
                health: '/up',
            )
            // Keep these closures untyped so this file also works on <=10 where the classes don't exist.
            ->withMiddleware(function ($middleware) {
                // You can add global middleware here if needed.
            })
            ->withExceptions(function ($exceptions) {
                // You can customize exception rendering here if needed.
            })
            ->create();
    }

    // Fallback for Laravel 8–10 style bootstrap.
    $app = new Application($_ENV['APP_BASE_PATH'] ?? dirname(__DIR__));

    // Optional: keep custom app path if you need it.
    $app->useAppPath($app->basePath('app'));

    $app->singleton(
        Illuminate\Contracts\Http\Kernel::class,
        App\Http\Kernel::class
    );

    $app->singleton(
        Illuminate\Contracts\Console\Kernel::class,
        App\Console\Kernel::class
    );

    $app->singleton(
        Illuminate\Contracts\Debug\ExceptionHandler::class,
        App\Exceptions\Handler::class
    );

    return $app;
})();
