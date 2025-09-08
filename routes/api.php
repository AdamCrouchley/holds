<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Stateless JSON endpoints using the "api" middleware group.
| Versioned under /api/v1. Webhooks can live here (no CSRF).
|--------------------------------------------------------------------------
*/

/**
 * NOTE: This file contains a few closure routes (ping/health/options/fallback).
 * If you intend to use `php artisan route:cache`, move these to controller actions.
 */

/*
|--------------------------------------------------------------------------
| v1: Basic utilities
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    // Simple ping with timestamp & path
    Route::get('/ping', function () {
        return response()->json([
            'ok'   => true,
            'path' => '/api/v1/ping',
            'ts'   => now()->toIso8601String(),
        ]);
    })->name('api.v1.ping');
});

/*
|--------------------------------------------------------------------------
| CORS preflight convenience
|--------------------------------------------------------------------------
*/
Route::options('/{any}', fn () => response()->noContent())
    ->where('any', '.*');

/*
|--------------------------------------------------------------------------
| v1 Public + Authenticated
|--------------------------------------------------------------------------
*/
Route::prefix('v1')
    ->middleware(['throttle:api'])
    ->group(function () {
        /*
        |----------------------------------------------------------------------
        | Health
        |----------------------------------------------------------------------
        */
        Route::get('/health', function () {
            return response()->json([
                'ok'      => true,
                'service' => config('app.name'),
                'env'     => config('app.env'),
                'version' => 'v1',
                'time'    => now()->toIso8601String(),
            ]);
        })->name('api.v1.health');

        /*
        |----------------------------------------------------------------------
        | Triggers / one-off actions
        | Example: POST /api/v1/triggers/payment-request
        |----------------------------------------------------------------------
        */
        if (class_exists(\App\Http\Controllers\Api\TriggerController::class)) {
            Route::post('/triggers/payment-request', [\App\Http\Controllers\Api\TriggerController::class, 'paymentRequest'])
                ->name('api.v1.triggers.payment-request');
        }

        /*
        |----------------------------------------------------------------------
        | Holds API
        | Controller: App\Http\Controllers\Holds\Api\V1\HoldsApiController
        |----------------------------------------------------------------------
        */
        if (class_exists(\App\Http\Controllers\Holds\Api\V1\HoldsApiController::class)) {
            $holds = \App\Http\Controllers\Holds\Api\V1\HoldsApiController::class;

            Route::prefix('holds')->group(function () use ($holds) {
                // Create/place a hold
                Route::post('/', [$holds, 'store'])->name('api.v1.holds.store');

                // Get a hold by reference/id
                Route::get('/{reference}', [$holds, 'show'])
                    ->where('reference', '[A-Za-z0-9\-_]+')
                    ->name('api.v1.holds.show');

                // Check status (trimmed payload)
                Route::get('/{reference}/status', [$holds, 'status'])
                    ->where('reference', '[A-Za-z0-9\-_]+')
                    ->name('api.v1.holds.status');

                // Capture funds (full or partial)
                Route::post('/{reference}/capture', [$holds, 'capture'])
                    ->where('reference', '[A-Za-z0-9\-_]+')
                    ->name('api.v1.holds.capture');

                // Cancel/release a hold
                Route::post('/{reference}/cancel', [$holds, 'cancel'])
                    ->where('reference', '[A-Za-z0-9\-_]+')
                    ->name('api.v1.holds.cancel');
            });
        }

        /*
        |----------------------------------------------------------------------
        | Payments (optional, guarded by class_exists)
        | Controller: App\Http\Controllers\Api\V1\PaymentsController
        |----------------------------------------------------------------------
        */
        if (class_exists(\App\Http\Controllers\Api\V1\PaymentsController::class)) {
            $payments = \App\Http\Controllers\Api\V1\PaymentsController::class;

            Route::prefix('payments')->group(function () use ($payments) {
                Route::post('/intents', [$payments, 'createIntent'])
                    ->name('api.v1.payments.intents.create');

                Route::post('/intents/{id}/confirm', [$payments, 'confirmIntent'])
                    ->where('id', '[A-Za-z0-9\-_]+')
                    ->name('api.v1.payments.intents.confirm');

                Route::post('/charges', [$payments, 'charge'])
                    ->name('api.v1.payments.charge');
            });
        }

        /*
        |----------------------------------------------------------------------
        | API Keys (optional verification endpoint)
        | Controller: App\Http\Controllers\Api\V1\ApiKeyController
        |----------------------------------------------------------------------
        */
        if (class_exists(\App\Http\Controllers\Api\V1\ApiKeyController::class)) {
            $keys = \App\Http\Controllers\Api\V1\ApiKeyController::class;

            Route::get('/keys/verify', [$keys, 'verify'])
                ->name('api.v1.keys.verify');
        }

        /*
        |----------------------------------------------------------------------
        | Authenticated (Sanctum/token guard)
        |----------------------------------------------------------------------
        */
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', function (Request $request) {
                return $request->user();
            })->name('api.v1.me');
        });
    });

/*
|--------------------------------------------------------------------------
| Webhooks (Stripe, etc.)
| Keep outside throttle if desired. Ensure VerifyCsrfToken excludes this path.
|--------------------------------------------------------------------------
*/
if (class_exists(\App\Http\Controllers\WebhookController::class)) {
    // Full URL: POST /api/webhooks/stripe
    Route::post('/webhooks/stripe', [\App\Http\Controllers\WebhookController::class, 'handle'])
        ->name('webhooks.stripe.api');
}

/*
|--------------------------------------------------------------------------
| Fallback (JSON 404)
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
    return response()->json([
        'ok'      => false,
        'error'   => 'Not Found',
        'message' => 'Route not found. Check path/method and API version.',
    ], 404);
});
