<?php

use Illuminate\Support\Facades\Route;

/* --------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------*/
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\VevsWebhookController;
use App\Http\Middleware\VerifyCsrfToken;

/*
|--------------------------------------------------------------------------
| Public pages
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('welcome'))->name('home');

/*
|--------------------------------------------------------------------------
| Global route parameter patterns
|--------------------------------------------------------------------------
*/
Route::pattern('token',   '[A-Za-z0-9]{16,64}');
Route::pattern('booking', '[0-9]+');
Route::pattern('deposit', '[0-9]+');

/*
|--------------------------------------------------------------------------
| VEVS Landing (customer gets forwarded here after booking)
|  Configure VEVS "Forward to page after successful reservation" to:
|    https://your-app.example/vevs/landing?ref_id=%REF_ID%&email=%EMAIL%
|  The controller resolves/creates a portal token and redirects to /p/pay/{token}
|--------------------------------------------------------------------------
*/
Route::get('/vevs/landing', [PortalController::class, 'vevsLanding'])
    ->name('vevs.landing');

/*
|--------------------------------------------------------------------------
| Customer Login + Dashboard
|  - /p/login (GET)            => login form (email + booking ref)
|  - /p/login (POST)           => resolve → redirect to /p/dash/{token}
|  - /p/resend-link (POST)     => re-send portal link (optional)
|  - /p/dash                   => convenience route → shows login if no token
|  - /p/dash/{token}           => customer dashboard (bookings, payments, holds)
|  - /p/account/{token}        => lightweight account/booking overview (optional)
|  - /portal/login, /portal/dash/{token} (aliases)
|--------------------------------------------------------------------------
*/
Route::get('/p/login', [PortalController::class, 'loginForm'])->name('portal.login');
Route::post('/p/login', [PortalController::class, 'loginResolve'])->name('portal.login.resolve');
Route::post('/p/resend-link', [PortalController::class, 'resendLink'])->name('portal.login.resend');

// If someone hits /p/dash without a token, show login (or a helpful message)
Route::get('/p/dash', fn () => redirect()->route('portal.login'))->name('portal.dashboard.missing');

// Main dashboard by token
Route::get('/p/dash/{token}', [PortalController::class, 'dashboard'])->name('portal.dashboard');

// Optional account/overview page keyed by the same portal token
Route::get('/p/account/{token}', [PortalController::class, 'account'])->name('portal.account');

// Back-compat aliases
Route::get('/portal/login', [PortalController::class, 'loginForm'])->name('portal.login.alt');
Route::get('/portal/dash/{token}', [PortalController::class, 'dashboard'])->name('portal.dashboard.alt');

/*
|--------------------------------------------------------------------------
| Customer Portal (hosted payment pages)
|  - /p/pay                 => helpful message if no token
|  - /p/pay/{token}         => payment form + submit
|  - /portal/pay/{token}    => alias (back-compat)
|  - /p/pay/{token}/return  => Stripe return (success)
|  - /p/pay/{token}/cancel  => Stripe cancel
|  - /portal/pay/{token}/return (alias)
|  - /portal/pay/{token}/cancel (alias)
|--------------------------------------------------------------------------
*/
Route::view('/p/pay', 'portal.missing-token')->name('portal.pay.missing');

// Primary (short) URLs
Route::get('/p/pay/{token}',  [PortalController::class, 'pay'])->name('portal.pay');
Route::post('/p/pay/{token}', [PortalController::class, 'paySubmit'])->name('portal.pay.submit');

// Stripe return/cancel for primary route
Route::get('/p/pay/{token}/return', [PortalController::class, 'return'])->name('portal.return.short');
Route::get('/p/pay/{token}/cancel', [PortalController::class, 'cancel'])->name('portal.cancel.short');

// Alias URLs (used by tests or for backwards compatibility)
Route::get('/portal/pay/{token}',  [PortalController::class, 'pay'])->name('portal.pay.alt');
Route::post('/portal/pay/{token}', [PortalController::class, 'paySubmit'])->name('portal.pay.submit.alt');

// Stripe return/cancel for alias route
Route::get('/portal/pay/{token}/return', [PortalController::class, 'return'])->name('portal.return');
Route::get('/portal/pay/{token}/cancel', [PortalController::class, 'cancel'])->name('portal.cancel');

/*
|--------------------------------------------------------------------------
| Booking payments (server-side endpoints)
|  - Deposit / Balance charges
|--------------------------------------------------------------------------
*/
Route::post('/bookings/{booking}/deposit', [PaymentController::class, 'deposit'])
    ->name('booking.deposit');

Route::post('/bookings/{booking}/balance', [PaymentController::class, 'balance'])
    ->name('booking.balance');

/*
|--------------------------------------------------------------------------
| Card deposit "hold" (authorize) and subsequent capture/void
|--------------------------------------------------------------------------
*/
Route::post('/bookings/{booking}/hold', [DepositController::class, 'authorise'])
    ->name('booking.hold');

Route::post('/deposits/{deposit}/capture', [DepositController::class, 'capture'])
    ->name('deposit.capture');

Route::post('/deposits/{deposit}/void', [DepositController::class, 'void'])
    ->name('deposit.void');

/*
|--------------------------------------------------------------------------
| Stripe Webhooks (CSRF-exempt)
|  - Keep existing /webhooks/stripe
|  - Add alias /stripe/webhook (some Stripe UI examples use this path)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [WebhookController::class, 'handle'])
    ->name('webhooks.stripe')
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::post('/stripe/webhook', [WebhookController::class, 'handle'])
    ->name('stripe.webhook')
    ->withoutMiddleware([VerifyCsrfToken::class]);

/*
|--------------------------------------------------------------------------
| VEVS Webhook (server->server, CSRF-exempt)
|  - Separate controller for clarity: VevsWebhookController@handle
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/vevs', [VevsWebhookController::class, 'handle'])
    ->name('webhooks.vevs')
    ->withoutMiddleware([VerifyCsrfToken::class]);

/*
|--------------------------------------------------------------------------
| Optional: simple healthcheck (useful for uptime monitors)
|--------------------------------------------------------------------------
*/
Route::get('/_up', fn () => response()->json(['ok' => true]))->name('health');

/*
|--------------------------------------------------------------------------
| Fallback 404 (tidy if someone hits a bad URL)
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
