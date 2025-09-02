<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TriggerController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| These routes are automatically prefixed with /api and use the "api" middleware
| group. CSRF does not apply here, which is ideal for third-party webhooks.
|--------------------------------------------------------------------------
*/

/* --------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------*/
use App\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Health & Diagnostics
|--------------------------------------------------------------------------
*/
Route::get('/_up', fn () => response()->json(['ok' => true, 'ts' => now()->toIso8601String()]));
Route::get('/ping', fn () => response()->json(['pong' => true]));

/*
|--------------------------------------------------------------------------
| Webhooks (public, CSRF-free)
|  - Stripe:    /api/webhooks/stripe (primary) and /api/stripe/webhook (alias)
|  - VEVS:      /api/webhooks/vevs
|--------------------------------------------------------------------------
| Implement handlers in App\Http\Controllers\WebhookController:
|   - handle(Request $request)   // Stripe webhook
|   - vevs(Request $request)     // VEVS webhook
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [WebhookController::class, 'handle'])->name('api.webhooks.stripe');
Route::post('/stripe/webhook',  [WebhookController::class, 'handle'])->name('api.stripe.webhook'); // alias

Route::post('/webhooks/vevs',   [WebhookController::class, 'vevs'])->name('api.webhooks.vevs');

/*
|--------------------------------------------------------------------------
| (Optional) Authenticated API endpoints
|--------------------------------------------------------------------------
| If you later need protected JSON endpoints (e.g., to query bookings),
| you can place them under the Sanctum middleware like this:
|
| Route::middleware('auth:sanctum')->group(function () {
|     Route::get('/me', function (Request $request) {
|         return $request->user();
|     });
| });
|
*/



Route::prefix('v1')
    ->middleware(['client.throttle:180,60']) // 180 requests per 60 seconds per API key
    ->group(function () {
        Route::post('/triggers/payment-request', [TriggerController::class, 'paymentRequest']);
    });
