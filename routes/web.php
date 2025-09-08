<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\VevsWebhookController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Middleware\VerifyCsrfToken;

// API v1 Triggers (web-exposed)
use App\Http\Controllers\Api\TriggerController;

// Portal Pay (job/booking payment links)
use App\Http\Controllers\Portal\PayController;

// Admin jobs
use App\Jobs\SyncDreamDrivesWeekMade;
use App\Jobs\SyncDreamDrivesWeekPickup;

/*
|--------------------------------------------------------------------------
| Public home
|--------------------------------------------------------------------------
*/
Route::get('/', fn () => view('welcome'))->name('home');

/*
|--------------------------------------------------------------------------
| Global route parameter patterns
|--------------------------------------------------------------------------
*/
Route::pattern('token',    '[A-Za-z0-9\-_]{16,64}');
Route::pattern('booking',  '[0-9]+');
Route::pattern('deposit',  '[0-9]+');
Route::pattern('customer', '[0-9]+');
Route::pattern('payment',  '[0-9]+');
Route::pattern('job',      '[0-9]+');

/*
|--------------------------------------------------------------------------
| Portal Pay (used by pay.blade)
|--------------------------------------------------------------------------
| GET display + POST endpoints consumed by fetch() in the Blade.
| Supports both {job} and {booking} paths.
*/
Route::middleware('web')->group(function () {
    // Show page (GET)
    Route::get('/p/job/{job}/pay', [PayController::class, 'show'])
        ->middleware('signed') // keep if you generate signed URLs
        ->name('portal.pay.show.job');

    Route::get('/p/booking/{booking}/pay', [PayController::class, 'showBooking'])
        ->name('portal.pay.show.booking'); // optional if you support bookings

    // Create payment/hold intents (POST)
    Route::post('/p/intent/{type}/{id}', [PayController::class, 'intent'])
        ->whereIn('type', ['job', 'booking'])
        ->name('portal.intent.store');

    // Notify server that a hold PI succeeded (POST)
    Route::post('/p/pay/{type}/{id}/hold-recorded', [PayController::class, 'holdRecorded'])
        ->whereIn('type', ['job', 'booking'])
        ->name('portal.pay.hold-recorded.store');
});

/*
|--------------------------------------------------------------------------
| VEVS landing (redirect from VEVS after booking)
|--------------------------------------------------------------------------
*/
Route::get('/vevs/landing', [PortalController::class, 'vevsLanding'])->name('vevs.landing');

/*
|--------------------------------------------------------------------------
| Portal routes (/p/*) – customer area
|--------------------------------------------------------------------------
*/
Route::get('/p',           [PortalController::class, 'home'])->name('portal.home');
Route::get('/p/dashboard', fn () => redirect()->route('portal.home'))->name('portal.dashboard');

/** Booking-aware payment page (authenticated) */
Route::get('/p/pay/{booking}', [PortalController::class, 'pay'])
    ->whereNumber('booking')
    ->name('portal.pay');

/** Payment Element – create/reuse Balance PaymentIntent used by pay.blade */
Route::post('/p/pay/{booking}/intent', [PaymentController::class, 'createOrReuseBalanceIntent'])
    ->whereNumber('booking')
    ->name('portal.pay.intent');

/** SetupIntent (store card for off-session) */
Route::post('/p/pay/{booking}/setup', [PaymentController::class, 'createSetupIntent'])
    ->whereNumber('booking')
    ->name('portal.pay.setup');

/** Finalize checkout */
Route::post('/p/pay/{booking}/finalize', [PaymentController::class, 'finalizeCheckout'])
    ->whereNumber('booking')
    ->name('portal.pay.finalize');

/** Completion return URL */
Route::get('/p/pay/{booking}/complete', [PaymentController::class, 'portalComplete'])
    ->whereNumber('booking')
    ->name('portal.pay.complete');

/** HOLD (manual capture) */
Route::post('/p/hold/{booking}/intent',  [PaymentController::class, 'portalCreateHoldIntent'])
    ->whereNumber('booking')
    ->name('portal.hold.intent');

Route::get('/p/hold/{booking}/complete', [PaymentController::class, 'portalCompleteHold'])
    ->whereNumber('booking')
    ->name('portal.hold.complete');

/** Post-hire off-session confirmation */
Route::get('/p/post-charge/confirm', function (Request $request) {
    return view('portal.post-charge-confirm', [
        'clientSecret' => $request->query('payment_intent_client_secret'),
        'bookingId'    => $request->query('booking'),
    ]);
})->name('portal.post_charge.confirm');

/** Portal claim + logout */
Route::post('/p/claim',  [PortalController::class, 'claim'])->name('portal.claim');
Route::post('/p/logout', [PortalController::class, 'logout'])->name('portal.logout');

/** Short magic link trigger */
Route::get('/p/t', [PortalController::class, 'magicLink'])->name('portal.magic');

/*
|--------------------------------------------------------------------------
| Tokenized hosted payment pages (/p/pay/{token})
|--------------------------------------------------------------------------
*/
Route::get('/p/pay/{token}',          [PaymentController::class, 'showPortalPay'])->name('portal.pay.token');
Route::post('/p/pay/{token}/intent',  [PaymentController::class, 'createOrReuseBalanceIntent'])->name('portal.pay.intent.token');
Route::post('/p/pay/{token}/confirm', [PaymentController::class, 'markPaid'])->name('portal.pay.confirm');

/*
|--------------------------------------------------------------------------
| Generic token landing under /p/{token} (non-payment)
|--------------------------------------------------------------------------
*/
Route::get('/p/{token}', [PortalController::class, 'show'])->name('portal.show');

/*
|--------------------------------------------------------------------------
| Portal login (magic link) – /p/login/*
|--------------------------------------------------------------------------
*/
Route::prefix('p')->group(function () {
    Route::get('/login',         [PortalController::class, 'showLogin'])->name('portal.login');
    Route::post('/login',        [PortalController::class, 'sendLoginLink'])->name('portal.login.send');
    Route::get('/login/consume', [PortalController::class, 'consume'])->name('portal.login.consume');
});

/*
|--------------------------------------------------------------------------
| Route aliases
|--------------------------------------------------------------------------
*/
Route::get('/login',          fn () => redirect()->route('portal.login'))->name('login');
Route::get('/customer/login', fn () => redirect()->route('portal.login'))->name('customer.login');

/*
|--------------------------------------------------------------------------
| Optional: Secondary credential-based flow (/p/login-classic)
|--------------------------------------------------------------------------
*/
Route::get('/p/login-classic',  [PortalAuthController::class, 'show'])->name('portal.login.classic');
Route::post('/p/login-classic', [PortalAuthController::class, 'attempt'])->name('portal.login.classic.attempt');

/*
|--------------------------------------------------------------------------
| Customer Portal (separate magic-link flow) under /portal/*
|--------------------------------------------------------------------------
*/
Route::prefix('portal')->group(function () {
    // Tokenised public HPP/landing
    Route::get('/{token}',          [PortalController::class, 'show'])->name('portal.token.show');
    Route::post('/{token}/intent',  [PortalController::class, 'upsertIntent'])->name('portal.intent');
    Route::get('/{token}/complete', [PortalController::class, 'complete'])->name('portal.complete');

    // Authenticated area
    Route::middleware('auth:customer')->group(function () {
        Route::get('/',        [CustomerPortalController::class, 'dashboard'])->name('portal.magic.home');
        Route::post('/logout', [CustomerPortalController::class, 'logout'])->name('portal.magic.logout');
    });
});

/*
|--------------------------------------------------------------------------
| Legacy username/password Customer Auth
|--------------------------------------------------------------------------
*/
Route::middleware('web')->group(function () {
    Route::post('/customer/login',  [CustomerAuthController::class, 'login'])->name('customer.login.attempt');
    Route::post('/customer/logout', [CustomerAuthController::class, 'logout'])->name('customer.logout');
    Route::get('/customer/bookings', [CustomerAuthController::class, 'bookings'])
        ->middleware('auth:customer')
        ->name('customer.bookings');
});

/*
|--------------------------------------------------------------------------
| Staff account area
|--------------------------------------------------------------------------
*/
Route::get('/customer/dashboard', fn () => view('customer.dashboard'))
    ->middleware(['auth'])
    ->name('customer.dashboard');

/*
|--------------------------------------------------------------------------
| Booking payments
|--------------------------------------------------------------------------
*/
Route::post('/bookings/{booking}/deposit', [PaymentController::class, 'deposit'])
    ->whereNumber('booking')
    ->name('booking.deposit');

Route::post('/bookings/{booking}/balance', [PaymentController::class, 'balance'])
    ->whereNumber('booking')
    ->name('booking.balance');

/*
|--------------------------------------------------------------------------
| Card deposit "hold" and capture/void
|--------------------------------------------------------------------------
*/
Route::post('/bookings/{booking}/hold',    [DepositController::class, 'authorise'])
    ->whereNumber('booking')
    ->name('booking.hold');

Route::post('/deposits/{deposit}/capture', [DepositController::class, 'capture'])
    ->whereNumber('deposit')
    ->name('deposit.capture');

Route::post('/deposits/{deposit}/void',    [DepositController::class, 'void'])
    ->whereNumber('deposit')
    ->name('deposit.void');

/*
|--------------------------------------------------------------------------
| Admin / Staff payments
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::post('/admin/bookings/{booking}/post-charge', [PaymentController::class, 'postHireCharge'])
        ->whereNumber('booking')
        ->name('admin.bookings.post_charge');

    Route::post('/admin/hold/{payment}/capture', [PaymentController::class, 'captureHold'])
        ->whereNumber('payment')
        ->name('admin.hold.capture');

    Route::post('/admin/hold/{payment}/release', [PaymentController::class, 'releaseHold'])
        ->whereNumber('payment')
        ->name('admin.hold.release');
});

/*
|--------------------------------------------------------------------------
| Admin: Customer Payment Methods
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::get('/admin/customers/{customer}/payment-method',          [PaymentMethodController::class, 'show'])->name('customers.pm.add');
    Route::post('/admin/customers/{customer}/payment-method/intent',  [PaymentMethodController::class, 'createSetupIntent'])->name('customers.pm.intent');
    Route::post('/admin/customers/{customer}/payment-method/default', [PaymentMethodController::class, 'setDefault'])->name('customers.pm.default');
});

/*
|--------------------------------------------------------------------------
| Admin: DreamDrives integration
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::post('/admin/integrations/dreamdrives/sync', function (Request $request) {
        $mode = $request->string('mode')->toString() ?: 'week_made';
        if ($mode === 'week_pickup') {
            dispatch(new SyncDreamDrivesWeekPickup);
            return back()->with('status', 'DreamDrives sync (week pickup) queued');
        }
        dispatch(new SyncDreamDrivesWeekMade);
        return back()->with('status', 'DreamDrives sync (week made) queued');
    })->middleware('throttle:3,1')->name('admin.integrations.dreamdrives.sync');
});

/*
|--------------------------------------------------------------------------
| Alias: /portal/pay/job/{job} (redirect to canonical)
|--------------------------------------------------------------------------
*/
Route::get('/portal/pay/job/{job}', fn (int $job) => redirect()->route('portal.pay.show.job', ['job' => $job]))
    ->middleware('signed')
    ->name('portal.pay.job');

/*
|--------------------------------------------------------------------------
| Sync by reference (optional)
|--------------------------------------------------------------------------
| POST /sync/by-reference            with JSON body { reference: "..." }
| POST /sync/by-reference/ABC123     with path param
*/
Route::post('/sync/by-reference/{reference?}', [\App\Http\Controllers\SyncController::class, 'byReference'])
    ->name('sync.byReference');

/*
|--------------------------------------------------------------------------
| Public API v1 (web-exposed)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:10,1')
    ->group(function () {
        Route::post('/triggers/payment-request', [TriggerController::class, 'paymentRequest'])
            ->name('api.triggers.payment_request');
    });

/*
|--------------------------------------------------------------------------
| Webhooks (web side)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [WebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('webhooks.stripe.web');

Route::post('/webhooks/vevs', [VevsWebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('webhooks.vevs');

/*
|--------------------------------------------------------------------------
| Static policy pages
|--------------------------------------------------------------------------
*/
Route::view('/portal/terms', 'portal.terms')->name('portal.terms');
Route::view('/portal/bond-policy', 'portal.bond-policy')->name('portal.bond.policy');

/*
|--------------------------------------------------------------------------
| Admin helper
|--------------------------------------------------------------------------
*/
Route::get('/admin/bookings/{booking}/payment-link', [PaymentController::class, 'paymentLink'])
    ->middleware(['auth'])
    ->whereNumber('booking')
    ->name('admin.booking.payment-link');

/*
|--------------------------------------------------------------------------
| Support contact (optional)
|--------------------------------------------------------------------------
*/
if (class_exists(\App\Http\Controllers\SupportController::class)) {
    Route::get('/support/contact', [\App\Http\Controllers\SupportController::class, 'show'])->name('support.contact');
    Route::post('/support/contact', [\App\Http\Controllers\SupportController::class, 'send'])->name('support.contact.send');
}

/*
|--------------------------------------------------------------------------
| Healthcheck
|--------------------------------------------------------------------------
*/
Route::get('/_up', fn () => response()->json(['ok' => true]))->name('health');

/*
|--------------------------------------------------------------------------
| One-off debug: DB check (web vs tinker)
|--------------------------------------------------------------------------
*/
Route::get('/_db-check', function () {
    return response()->json([
        'env'        => app()->environment(),
        'default'    => config('database.default'),
        'sqlite_db'  => config('database.connections.sqlite.database'),
        'columns'    => \Schema::getColumnListing('bookings'),
    ]);
});

/*
|--------------------------------------------------------------------------
| Fallback 404
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
