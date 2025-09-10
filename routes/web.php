<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
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


Route::post('/p/job/{job}/paid', [PayController::class, 'recordJobPaid'])
    ->whereNumber('job')
    ->name('portal.pay.recordPaid');   // ← legacy name expected by Blade


// Legacy alias so the Blade helper works:
// POST /p/job/{job}/intent  → PayController@intent('job', {job})
Route::post('/p/job/{job}/intent', function (Request $request, int $job) {
    return app(PayController::class)->intent($request, 'job', $job);
})
    ->whereNumber('job')
    ->withoutMiddleware([VerifyCsrfToken::class])   // public portal form posts
    ->name('portal.pay.intent.job');



/*
|--------------------------------------------------------------------------
| Public: Home
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
| Debug / SMTP smoke tests (throttled)
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
})->middleware('throttle:3,1')->name('debug.mail_test');

Route::get('/test-mail', function () {
    try {
        Mail::raw('Test from Holds app route', function ($msg) {
            $msg->to('apcrouchley@gmail.com')->subject('Holds Route Test');
        });
        return 'Mail dispatched — check inbox (or logs if using mailer=log).';
    } catch (\Throwable $e) {
        Log::error('Mail test failed: ' . $e->getMessage());
        return 'Mail failed: ' . $e->getMessage();
    }
})->middleware('throttle:3,1');

/*
|--------------------------------------------------------------------------
| VEVS landing (redirect from VEVS after booking)
|--------------------------------------------------------------------------
*/
Route::get('/vevs/landing', [PortalController::class, 'vevsLanding'])->name('vevs.landing');

/*
|--------------------------------------------------------------------------
| Portal routes (/p/*) – customer area (Booking-aware)
|--------------------------------------------------------------------------
*/
Route::get('/p', [PortalController::class, 'home'])->name('portal.home');
Route::get('/p/dashboard', fn () => redirect()->route('portal.home'))->name('portal.dashboard');

/** Booking-aware payment page (authenticated) */
Route::get('/p/pay/{booking}', [PortalController::class, 'pay'])
    ->whereNumber('booking')
    ->name('portal.pay');

/** Payment Element – create/reuse Balance PaymentIntent (booking-aware) */
Route::post('/p/pay/{booking}/intent', [PaymentController::class, 'portalCreateIntent'])
    ->whereNumber('booking')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.intent.booking');

/** (Optional) SetupIntent to store card for off-session */
Route::post('/p/pay/{booking}/setup', [PaymentController::class, 'createSetupIntent'])
    ->whereNumber('booking')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.setup');

/** Finalize checkout (POST form fallback) */
Route::post('/p/pay/{booking}/finalize', [PaymentController::class, 'finalizeCheckout'])
    ->whereNumber('booking')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.finalize');

/** Completion return URL (GET) */
Route::get('/p/pay/{booking}/complete', [PaymentController::class, 'portalComplete'])
    ->whereNumber('booking')
    ->name('portal.pay.complete');

/** HOLD (manual capture) */
Route::post('/p/hold/{booking}/intent', [PaymentController::class, 'portalCreateHoldIntent'])
    ->whereNumber('booking')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.hold.intent');

Route::get('/p/hold/{booking}/complete', [PaymentController::class, 'portalCompleteHold'])
    ->whereNumber('booking')
    ->name('portal.hold.complete');

/** Post-hire off-session confirmation (Stripe redirect return) */
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
| Canonical Job-based customer payment flow
| -> Namespaced to portal.pay.job.* to avoid collisions with booking routes
|--------------------------------------------------------------------------
*/
Route::prefix('portal/jobs/{job}/pay')->name('portal.pay.job.')->group(function () {
    Route::get('/',         [PayController::class, 'show'])      ->whereNumber('job')->name('show');
    Route::post('/intent',  [PayController::class, 'intent'])    ->whereNumber('job')->name('intent');
    Route::post('/bundle',  [PayController::class, 'bundle'])    ->whereNumber('job')->name('bundle');
    Route::post('/paid',    [PayController::class, 'recordPaid'])->whereNumber('job')->name('recordPaid');
    Route::get('/complete', [PayController::class, 'complete'])  ->whereNumber('job')->name('complete');
});

/*
|--------------------------------------------------------------------------
| Back-compat helpers (legacy routes still in the wild)
|--------------------------------------------------------------------------
*/
Route::get('/p/job/{job}/pay', [PayController::class, 'show'])
    ->whereNumber('job')
    ->name('portal.pay.show.job');

Route::get('/p/pay/t/{token}', [PayController::class, 'showByToken'])
    ->name('portal.pay.show.token');

Route::get('/p/job/{job}/pay/url', [PayController::class, 'url'])
    ->whereNumber('job')
    ->name('portal.pay.url');

Route::post('/p/job/{job}/bundle', [PayController::class, 'bundle'])
    ->whereNumber('job')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.bundle.job');

Route::get('/p/job/{job}/complete', [PayController::class, 'complete'])
    ->whereNumber('job')
    ->name('portal.pay.job.complete.legacy');

/** Generic helpers retained (some older JS may call these) */
Route::post('/p/{type}/{id}/intent', [PayController::class, 'intent'])
    ->where('type', '(job|booking)')
    ->whereNumber('id')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.intent');

Route::post('/p/{type}/{id}/bundle', [PayController::class, 'bundle'])
    ->where('type', '(job|booking)')
    ->whereNumber('id')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.bundle');

Route::post('/p/{type}/{id}/hold-recorded', [PayController::class, 'holdRecorded'])
    ->where('type', '(job|booking)')
    ->whereNumber('id')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.hold-recorded');

/*
|--------------------------------------------------------------------------
| Tokenized hosted payment pages (/p/pay/{token}) – legacy token flow
|--------------------------------------------------------------------------
*/
Route::get('/p/pay/{token}',          [PaymentController::class, 'showPortalPay'])
    ->name('portal.pay.token');

Route::post('/p/pay/{token}/intent',  [PaymentController::class, 'createOrReuseBalanceIntent'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.intent.token');

Route::post('/p/pay/{token}/confirm', [PaymentController::class, 'markPaid'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('portal.pay.confirm');

/*
|--------------------------------------------------------------------------
| Generic token landing under /p/{token} (non-payment) — keep LAST
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
    Route::post('/{token}/intent',  [PortalController::class, 'upsertIntent'])
        ->withoutMiddleware([VerifyCsrfToken::class])
        ->name('portal.intent');
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
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('booking.deposit');

Route::post('/bookings/{booking}/balance', [PaymentController::class, 'balance'])
    ->whereNumber('booking')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('booking.balance');

/*
|--------------------------------------------------------------------------
| Card deposit "hold" and capture/void (legacy DepositController)
|--------------------------------------------------------------------------
*/
Route::post('/bookings/{booking}/hold', [DepositController::class, 'authorise'])
    ->whereNumber('booking')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('booking.hold');

Route::post('/deposits/{deposit}/capture', [DepositController::class, 'capture'])
    ->whereNumber('deposit')
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('deposit.capture');

Route::post('/deposits/{deposit}/void', [DepositController::class, 'void'])
    ->whereNumber('deposit')
    ->withoutMiddleware([VerifyCsrfToken::class])
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

    // Holds capture/release (new PaymentController paths)
    Route::post('/admin/hold/{payment}/capture', [PaymentController::class, 'captureHold'])
        ->whereNumber('payment')
        ->name('admin.hold.capture');

    Route::post('/admin/hold/{payment}/release', [PaymentController::class, 'releaseHold'])
        ->whereNumber('payment')
        ->name('admin.hold.release');

    // Deposits / holds index (uses deposits table + view)
    Route::get('admin/holds', [PaymentController::class, 'holdsIndex'])
    ->name('admin.holds.index');
});

/*
|--------------------------------------------------------------------------
| Stripe webhooks
|--------------------------------------------------------------------------
*/
Route::post('/webhooks/stripe', [WebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('webhooks.stripe.web');
