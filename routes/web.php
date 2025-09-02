<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/* --------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------*/
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\VevsWebhookController;
use App\Http\Controllers\CustomerPortalController;
use App\Http\Controllers\CustomerAuthController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\PortalAuthController;
use App\Http\Middleware\VerifyCsrfToken;

// API v1 Triggers
use App\Http\Controllers\Api\TriggerController;

/* --------------------------------------------------------------------------
| Jobs (DreamDrives)
|--------------------------------------------------------------------------*/
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
Route::pattern('token',    '[A-Za-z0-9_-]{16,64}');
Route::pattern('booking',  '[0-9]+');
Route::pattern('deposit',  '[0-9]+');
Route::pattern('customer', '[0-9]+');
Route::pattern('payment',  '[0-9]+');

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

/** Finalize checkout (server-side bookkeeping, e.g., flag booking paid) */
Route::post('/p/pay/{booking}/finalize', [PaymentController::class, 'finalizeCheckout'])
    ->whereNumber('booking')
    ->name('portal.pay.finalize');

/** Completion return URL for balance/deposit (pay.blade return_url) */
Route::get('/p/pay/{booking}/complete', [PaymentController::class, 'portalComplete'])
    ->whereNumber('booking')
    ->name('portal.pay.complete');

/** HOLD (manual capture) from the portal (used by pay.blade) */
Route::post('/p/hold/{booking}/intent',  [PaymentController::class, 'portalCreateHoldIntent'])
    ->whereNumber('booking')
    ->name('portal.hold.intent');
Route::get('/p/hold/{booking}/complete', [PaymentController::class, 'portalCompleteHold'])
    ->whereNumber('booking')
    ->name('portal.hold.complete');

/** Post-hire off-session confirmation (SCA fallback) */
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
| Tokenized hosted payment pages (/p/pay/{token}) – public entrypoint
|--------------------------------------------------------------------------
*/
Route::get('/p/pay/{token}',          [PaymentController::class, 'showPortalPay'])
    ->name('portal.pay.token');
Route::post('/p/pay/{token}/intent',  [PaymentController::class, 'createOrReuseBalanceIntent'])
    ->name('portal.pay.intent.token');
Route::post('/p/pay/{token}/confirm', [PaymentController::class, 'markPaid'])
    ->name('portal.pay.confirm');

/*
|--------------------------------------------------------------------------
| Generic token landing under /p/{token} (non-payment)
| - Uses strict token pattern to avoid conflicts with /p/pay/* and /p/t
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
| Route aliases (for convenience)
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
    // Tokenised public HPP/landing (legacy/alternative)
    Route::get('/{token}',          [PortalController::class, 'show'])->name('portal.token.show');
    Route::post('/{token}/intent',  [PortalController::class, 'upsertIntent'])->name('portal.intent');
    Route::get('/{token}/complete', [PortalController::class, 'complete'])->name('portal.complete');

    // Authenticated area
    Route::middleware('auth:customer')->group(function () {
        Route::get('/',        [CustomerPortalController::class, 'dashboard'])->name('portal.magic.home');
        Route::post('/logout', [CustomerPortalController::class, 'logout'])->name('portal.magic.logout');

        // Booking-scoped payment intents (optional extra entrypoints)
        Route::prefix('bookings/{booking}')->whereNumber('booking')->group(function () {
            Route::post('/deposit-intent',  [PaymentController::class, 'portalCreateDepositIntent'])->name('portal.booking.deposit.create');
            Route::get('/deposit-complete', [PaymentController::class, 'portalCompleteDeposit'])->name('portal.booking.deposit.complete');

            Route::post('/balance-intent',  [PaymentController::class, 'portalCreateIntent'])->name('portal.booking.balance.create');
            Route::get('/complete',         [PaymentController::class, 'portalComplete'])->name('portal.booking.balance.complete');

            Route::post('/hold-intent',     [PaymentController::class, 'portalCreateHoldIntent'])->name('portal.booking.hold.create');
            Route::get('/hold-complete',    [PaymentController::class, 'portalCompleteHold'])->name('portal.booking.hold.complete');
        });
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
| Staff account area (Laravel default auth)
|--------------------------------------------------------------------------
*/
Route::get('/customer/dashboard', fn () => view('customer.dashboard'))
    ->middleware(['auth'])
    ->name('customer.dashboard');

/*
|--------------------------------------------------------------------------
| Booking payments (server-side endpoints)
|--------------------------------------------------------------------------
*/
Route::post('/bookings/{booking}/deposit', [PaymentController::class, 'deposit'])
    ->whereNumber('booking')->name('booking.deposit');

Route::post('/bookings/{booking}/balance', [PaymentController::class, 'balance'])
    ->whereNumber('booking')->name('booking.balance');

/*
|--------------------------------------------------------------------------
| Card deposit "hold" (DepositsController) and capture/void
|--------------------------------------------------------------------------
*/
Route::post('/bookings/{booking}/hold',    [DepositController::class, 'authorise'])
    ->whereNumber('booking')->name('booking.hold');
Route::post('/deposits/{deposit}/capture', [DepositController::class, 'capture'])
    ->whereNumber('deposit')->name('deposit.capture');
Route::post('/deposits/{deposit}/void',    [DepositController::class, 'void'])
    ->whereNumber('deposit')->name('deposit.void');

/*
|--------------------------------------------------------------------------
| Admin / Staff payments (extra charges, holds management)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Post-hire charge against a booking (off-session)
    Route::post('/admin/bookings/{booking}/post-charge', [PaymentController::class, 'postHireCharge'])
        ->whereNumber('booking')
        ->name('admin.bookings.post_charge');

    // Capture/release existing manual-capture hold
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
| Admin: DreamDrives integration (Sync)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    // Generic (UI can always hit this one)
    Route::post('/admin/integrations/dreamdrives/sync', function (Request $request) {
        $mode = $request->string('mode')->toString() ?: 'week_made';
        if ($mode === 'week_pickup') {
            dispatch(new SyncDreamDrivesWeekPickup);
            return back()->with('status', 'DreamDrives sync (week pickup) queued');
        }
        dispatch(new SyncDreamDrivesWeekMade);
        return back()->with('status', 'DreamDrives sync (week made) queued');
    })->middleware('throttle:3,1')->name('admin.integrations.dreamdrives.sync');

    // Explicit helpers (separate buttons)
    Route::post('/admin/integrations/dreamdrives/sync/week-made', function () {
        dispatch(new SyncDreamDrivesWeekMade);
        return back()->with('status', 'DreamDrives sync (week made) queued');
    })->middleware('throttle:3,1')->name('admin.integrations.dreamdrives.sync.week_made');

    Route::post('/admin/integrations/dreamdrives/sync/week-pickup', function () {
        dispatch(new SyncDreamDrivesWeekPickup);
        return back()->with('status', 'DreamDrives sync (week pickup) queued');
    })->middleware('throttle:3,1')->name('admin.integrations.dreamdrives.sync.week_pickup');
});

/*
|--------------------------------------------------------------------------
| Public API v1 (Triggers) – CSRF-exempt + throttled
|--------------------------------------------------------------------------
*/
Route::prefix('v1')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:10,1') // 10 requests / min per IP
    ->group(function () {
        Route::post('/triggers/payment-request', [TriggerController::class, 'paymentRequest'])
            ->name('api.triggers.payment_request');
    });

/*
|--------------------------------------------------------------------------
| Webhooks (CSRF-exempt)
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [WebhookController::class, 'handle'])
    ->name('webhooks.stripe')
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::post('/stripe/webhook', [WebhookController::class, 'handle'])
    ->name('stripe.webhook')
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::post('/webhooks/vevs', [VevsWebhookController::class, 'handle'])
    ->name('webhooks.vevs')
    ->withoutMiddleware([VerifyCsrfToken::class]);

/*
|--------------------------------------------------------------------------
| Static policy pages
|--------------------------------------------------------------------------
*/
Route::view('/portal/terms', 'portal.terms')->name('portal.terms');
Route::view('/portal/bond-policy', 'portal.bond-policy')->name('portal.bond.policy');

/*
|--------------------------------------------------------------------------
| Admin helper to get a shareable link (optional)
|--------------------------------------------------------------------------
*/
Route::get('/admin/bookings/{booking}/payment-link', [PaymentController::class, 'paymentLink'])
    ->middleware(['auth'])
    ->whereNumber('booking')
    ->name('admin.booking.payment-link');

/*
|--------------------------------------------------------------------------
| Support contact
|--------------------------------------------------------------------------
*/
Route::get('/support/contact', [SupportController::class, 'show'])->name('support.contact');
Route::post('/support/contact', [SupportController::class, 'send'])->name('support.contact.send');

/*
|--------------------------------------------------------------------------
| Healthcheck
|--------------------------------------------------------------------------
*/
Route::get('/_up', fn () => response()->json(['ok' => true]))->name('health');

/*
|--------------------------------------------------------------------------
| Fallback 404
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
    return response()->view('errors.404', [], 404);
});
