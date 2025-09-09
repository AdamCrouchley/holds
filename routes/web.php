<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Http\Request;
use App\Http\Middleware\VerifyCsrfToken;

/*
|--------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\TriggerController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\Portal\PayController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\VevsWebhookController;
use App\Http\Controllers\WebhookController;

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
| SMTP smoke test
|--------------------------------------------------------------------------
*/
Route::get('/mail-test', function () {
    try {
        Mail::raw('Hello from Holds app!', function ($m) {
            $m->to('your-test@email.com')->subject('SMTP test');
        });
        return '✅ Mail sent';
    } catch (\Throwable $e) {
        return '❌ Mail failed: ' . $e->getMessage();
    }
})->name('debug.mail_test');

/*
|--------------------------------------------------------------------------
| Portal Pay (used by pay.blade and share links)
|--------------------------------------------------------------------------
|
| - GET  /p/job/{job}/pay              -> PayController@show
| - GET  /p/pay/t/{token}              -> PayController@showByToken
| - GET  /p/job/{job}/pay/url          -> PayController@url
| - POST /p/{type}/{id}/intent         -> PayController@intent
| - POST /p/{type}/{id}/hold-recorded  -> PayController@holdRecorded
| - POST /p/{type}/{id}/bundle         -> PayController@bundle   <-- NEW
|
*/
Route::middleware('web')->group(function () {
    // Pay page by Job id
    Route::get('/p/job/{job}/pay', [PayController::class, 'show'])
        ->name('portal.pay.show.job');

    // Pay page by token
    Route::get('/p/pay/t/{token}', [PayController::class, 'showByToken'])
        ->name('portal.pay.show.token');

    // JSON helper to fetch a shareable URL
    Route::get('/p/job/{job}/pay/url', [PayController::class, 'url'])
        ->name('portal.pay.url');

    // Optional stubs used by the front-end
    Route::post('/p/{type}/{id}/intent', [PayController::class, 'intent'])
        ->where('type', 'job|booking')
        ->whereNumber('id')
        ->name('portal.pay.intent');

    Route::post('/p/{type}/{id}/hold-recorded', [PayController::class, 'holdRecorded'])
        ->where('type', 'job|booking')
        ->whereNumber('id')
        ->name('portal.pay.hold-recorded');

    // NEW: Bundle endpoint (e.g., to create PI + SI together)
    Route::post('/p/{type}/{id}/bundle', [PayController::class, 'bundle'])
        ->where('type', 'job|booking')
        ->whereNumber('id')
        ->name('portal.pay.bundle');
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

/** Payment Element – create/reuse Balance PaymentIntent */
Route::post('/p/pay/{booking}/intent', [PaymentController::class, 'createOrReuseBalanceIntent'])
    ->whereNumber('booking')
    ->name('portal.pay.intent.booking');

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
| Tokenized hosted payment pages (/p/pay/{token}) – legacy
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
| Optional: classic login
|--------------------------------------------------------------------------
*/
Route::get('/p/login-classic',  [PortalAuthController::class, 'show'])->name('portal.login.classic');
Route::post('/p/login-classic', [PortalAuthController::class, 'attempt'])->name('portal.login.classic.attempt');

/*
|--------------------------------------------------------------------------
| Customer Portal (magic-link flow) under /portal/*
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
| Legacy Customer Auth
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
| Booking payments (legacy)
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
| Admin / Staff
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Post-hire capture
    Route::post('/admin/bookings/{booking}/post-charge', [PaymentController::class, 'postHireCharge'])
        ->whereNumber('booking')
        ->name('admin.bookings.post_charge');

    // Holds capture/release
    Route::post('/admin/hold/{payment}/capture', [PaymentController::class, 'captureHold'])
        ->whereNumber('payment')
        ->name('admin.hold.capture');

    Route::post('/admin/hold/{payment}/release', [PaymentController::class, 'releaseHold'])
        ->whereNumber('payment')
        ->name('admin.hold.release');

    // Admin: Customer payment methods
    Route::get('/admin/customers/{customer}/payment-method',          [PaymentMethodController::class, 'show'])->name('customers.pm.add');
    Route::post('/admin/customers/{customer}/payment-method/intent',  [PaymentMethodController::class, 'createSetupIntent'])->name('customers.pm.intent');
    Route::post('/admin/customers/{customer}/payment-method/default', [PaymentMethodController::class, 'setDefault'])->name('customers.pm.default');

    // Admin: DreamDrives integration
    Route::post('/admin/integrations/dreamdrives/sync', function (Request $request) {
        $mode = $request->string('mode')->toString() ?: 'week_made';
        if ($mode === 'week_pickup') {
            dispatch(new \App\Jobs\SyncDreamDrivesWeekPickup);
            return back()->with('status', 'DreamDrives sync (week pickup) queued');
        }
        dispatch(new \App\Jobs\SyncDreamDrivesWeekMade);
        return back()->with('status', 'DreamDrives sync (week made) queued');
    })->middleware('throttle:3,1')->name('admin.integrations.dreamdrives.sync');

    // Admin: Email payment request from Job
    Route::post('/admin/jobs/{job}/email-payment-request', [JobController::class, 'emailPaymentRequest'])
        ->whereNumber('job')
        ->name('admin.jobs.email-payment-request');
});

/*
|--------------------------------------------------------------------------
| Alias: /portal/pay/job/{job} → canonical job pay route
|--------------------------------------------------------------------------
*/
Route::get('/portal/pay/job/{job}', fn (int $job) =>
    redirect()->route('portal.pay.show.job', ['job' => $job])
)->name('portal.pay.job');

/*
|--------------------------------------------------------------------------
| Sync by reference
|--------------------------------------------------------------------------
*/
Route::post('/sync/by-reference/{reference?}', [SyncController::class, 'byReference'])
    ->name('sync.byReference');

/*
|--------------------------------------------------------------------------
| Public API v1
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
| Webhooks
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
| Healthcheck & DB check
|--------------------------------------------------------------------------
*/
Route::get('/_up', fn () => response()->json(['ok' => true]))->name('health');

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
Route::fallback(fn () => response()->view('errors.404', [], 404));
