<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Panel
    |--------------------------------------------------------------------------
    |
    | This defines which panel should be considered the "default" when
    | calling Filament::getCurrentPanel() or similar helpers.
    |
    */

    'default_panel' => 'admin',

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    |
    | Each panel is defined here. Typically, you'll have an 'admin' panel,
    | but you can add more if you want separate Filament dashboards.
    |
    */

    'panels' => [

        'admin' => [
            'id'    => 'admin',
            'path'  => 'admin',
            'domain'=> null,

            /*
            |--------------------------------------------------------------------------
            | Authentication
            |--------------------------------------------------------------------------
            |
            | Filament will use this guard and middleware to handle access.
            |
            */

            'auth' => [
                'guard'      => 'web',
                'middleware' => [
                    'web',
                    \App\Http\Middleware\Authenticate::class,
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Branding
            |--------------------------------------------------------------------------
            */

            'brand' => [
                'name'   => 'Dream Drives Admin',
                'logo'   => null, // e.g. 'images/logo.svg'
                'colors' => [
                    'primary' => \Filament\Support\Colors\Color::Blue,
                ],
            ],

            /*
            |--------------------------------------------------------------------------
            | Resources, Pages, Widgets
            |--------------------------------------------------------------------------
            |
            | You can register resources explicitly or let Filament discover them.
            |
            */

            'discover' => [
                'resources' => [
                    'in'  => app_path('Filament/Resources'),
                    'for' => 'App\\Filament\\Resources',
                ],
                'pages' => [
                    'in'  => app_path('Filament/Pages'),
                    'for' => 'App\\Filament\\Pages',
                ],
                'widgets' => [
                    'in'  => app_path('Filament/Widgets'),
                    'for' => 'App\\Filament\\Widgets',
                ],
            ],

            'resources' => [
                // Explicitly register if discovery fails or for clarity
                \App\Filament\Resources\DepositResource::class,
            ],

            'pages' => [],
            'widgets' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Global Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware are run for every Filament request.
    |
    */

    'middleware' => [
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
    ],

];
