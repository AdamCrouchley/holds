<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Stripe\StripeClient;
use Throwable;

/**
 * PortalController
 *
 * Views expected:
 *  - resources/views/portal/login.blade.php
 *  - resources/views/portal/login-sent.blade.php
 *  - resources/views/portal/home.blade.php
 *  - resources/views/portal/dashboard.blade.php
 *  - resources/views/portal/pay.blade.php
 *  - resources/views/portal/claim.blade.php
 *  - resources/views/portal/missing-token.blade.php (optional)
 *
 * Suggested routes:
 *  GET  /p/login                     -> showLogin                  (name: portal.login)
 *  POST /p/login                     -> sendLoginLink              (name: portal.login.send)
 *  GET  /p/login/consume             -> magicLoginQS               (name: portal.login.consume)
 *  POST /p/logout                    -> logout                     (name: portal.logout)
 *  GET  /p                           -> home                       (name: portal.home)
 *  GET  /portal                      -> dashboard                  (name: portal.dashboard)
 *
 *  // VEVS fast path (no token; ref/amount/email in QS):
 *  GET  /pay                         -> vevsDirectPay              (name: portal.pay.direct)
 *
 *  // Logged-in pay (choose by id/reference):
 *  GET  /p/pay                       -> pay                        (name: portal.pay)
 *
 *  // Tokenized direct pay (no login):
 *  GET  /p/{token}                   -> show                       (name: portal.pay.token)
 *
 *  // Claim booking to logged-in portal customer:
 *  GET  /p/claim                     -> claimForm                  (name: portal.claim.form)
 *  POST /p/claim                     -> claim                      (name: portal.claim)
 *
 *  // VEVS landing helper:
 *  GET  /vevs/landing                -> vevsLanding                (name: vevs.landing)
 *
 *  // Signed “magic pay” link (no login):
 *  GET  /p/t                         -> magicLink                  (name: portal.magic)
 *      QS: ref, amount (nullable "500.00"), type=balance|hold|checkout, exp, sig
 *
 *  // Ajax from /portal/pay page:
 *  POST /p/{booking}/intent          -> portalCreateIntent         (name: portal.intent)
 *  POST /p/{booking}/pay-intent      -> createPayIntent            (name: portal.intent.simple)
 *  POST /p/{booking}/hold-recorded   -> holdRecorded               (name: portal.hold.recorded)
 *
 *  // Admin post-hire/bond operations:
 *  POST /admin/bookings/{booking}/post-charge  -> postHireCharge   (name: admin.bookings.post_charge)
 *  POST /admin/bookings/{booking}/bond/capture -> adminCaptureBond (name: admin.bookings.bond.capture)
 *  POST /admin/bookings/{booking}/bond/void    -> adminVoidBond    (name: admin.bookings.bond.void)
 */
class PortalController extends Controller
{
    /* =========================================================================
     | Stripe
     * ========================================================================= */
    private function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret') ?: env('STRIPE_SECRET'));
    }

    private function ensureStripeCustomer(Customer $customer): ?string
    {
        $id = trim((string) ($customer->stripe_customer_id ?? '')) ?: null;
        if ($id) return $id;

        try {
            $sc = $this->stripe()->customers->create([
                'email'    => $customer->email ?? null,
                'name'     => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: null,
                'metadata' => ['customer_id' => (string) $customer->getKey()],
            ]);
            $id = $sc->id;
            $customer->forceFill(['stripe_customer_id' => $id])->save();
            return $id;
        } catch (Throwable $e) {
            Log::error('[portal] ensureStripeCustomer failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /* =========================================================================
     | PUBLIC TOKEN ENTRY — immediate pay page (no login needed)
     * ========================================================================= */

    /**
     * GET /p/{token}
     * Optional QS:
     *   - amount=12345 (override amount in cents)
     *   - currency=NZD (default: booking.currency or NZD)
     *   - purpose=deposit|balance|custom (default: auto/balance)
     */
    public function show(Request $request, string $token)
    {
        /** @var Booking $booking */
        $booking = Booking::where('portal_token', $token)->firstOrFail();
        $booking->loadMissing('payments', 'customer');

        $paid    = $this->sumPaid($booking);
        $total   = (int) ($booking->total_amount ?? 0);
        $balance = max(0, $total - $paid);

        // Decide what to charge
        $amountOverride = (int) $request->query('amount', 0);
        $purposeParam   = (string) $request->query('purpose', 'auto'); // auto|deposit|balance|custom
        $currencyUpper  = strtoupper((string) ($request->query('currency', $booking->currency ?: 'NZD')));

        $amount = $amountOverride > 0
            ? $amountOverride
            : ($purposeParam === 'deposit'
                ? (int) ($booking->deposit_amount ?? 0)
                : $balance);

        if ($amount <= 0) {
            return view('portal.pay', [
                'booking'        => $booking,
                'user'           => $booking->customer,
                'paid'           => $paid,
                'total'          => $total,
                'balance'        => $balance,
                'clientSecret'   => null,
                'publishableKey' => config('services.stripe.key') ?: config('services.stripe.publishable_key'),
                'nothingToPay'   => true,
            ]);
        }

        // Canonical internal purpose
        $purpose = match ($purposeParam) {
            'deposit' => 'booking_deposit',
            'balance' => 'booking_balance',
            'custom'  => 'custom',
            default   => ($amountOverride > 0 ? 'custom' : 'booking_balance'),
        };

        // Create / refresh Payment + PaymentIntent
        [$payment, $pi] = $this->buildOrUpdateIntent(
            booking:        $booking,
            purpose:        $purpose,
            amount:         $amount,
            currency:       $currencyUpper,
            idempotencyKey: 'token.pay.' . $booking->getKey() . '.' . $amount
        );

        return view('portal.pay', [
            'booking'        => $booking,
            'user'           => $booking->customer,
            'paid'           => $paid,
            'total'          => $total,
            'balance'        => $balance,
            'clientSecret'   => $pi?->client_secret ?? null,
            'publishableKey' => config('services.stripe.key') ?: config('services.stripe.publishable_key'),
            'payment'        => $payment,
        ]);
    }

    /* =========================================================================
     | LOGIN (passwordless magic link)
     * ========================================================================= */

    /** GET /p/login */
    public function showLogin(Request $request)
    {
        if ($this->portalUser($request)) {
            return redirect()->route('portal.home');
        }

        if ($intended = (string) $request->query('intended', '')) {
            $request->session()->put('portal.intended', $intended);
        }

        return view('portal.login');
    }

    /** POST /p/login */
    public function sendLoginLink(Request $request)
    {
        $data  = $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($data['email']));

        /** @var Customer|null $customer */
        $customer = Customer::whereRaw('LOWER(email) = ?', [$email])->first();

        // Always behave as if success for privacy
        if ($customer) {
            $plain = Str::random(64);
            $customer->login_token_hash       = hash('sha256', $plain);
            $customer->login_token_expires_at = now()->addMinutes(30);
            $customer->save();

            $params = ['email' => $customer->email, 'token' => $plain];

            if ($request->session()->has('portal.intended')) {
                $params['intended'] = $request->session()->get('portal.intended');
            }

            $loginUrl = URL::temporarySignedRoute(
                'portal.login.consume',
                now()->addMinutes(30),
                $params
            );

            Log::info('[portal/login] magic link created', ['email' => $email, 'url' => $loginUrl]);
            // TODO: send email with $loginUrl
        }

        return view('portal.login-sent', ['email' => $email]);
    }

    /** GET /p/login/consume?token=...&email=...&intended=... */
    public function magicLoginQS(Request $request)
    {
        abort_if(!$request->hasValidSignature(), 403, 'Invalid or expired link.');

        $token = (string) $request->query('token', '');
        $email = strtolower(trim((string) $request->query('email', '')));
        abort_if($token === '' || $email === '', 403, 'Invalid login link.');

        /** @var Customer|null $customer */
        $customer = Customer::whereRaw('LOWER(email) = ?', [$email])->first();

        if (
            !$customer ||
            empty($customer->login_token_hash) ||
            empty($customer->login_token_expires_at) ||
            now()->greaterThan(Carbon::parse($customer->login_token_expires_at)) ||
            !hash_equals($customer->login_token_hash, hash('sha256', $token))
        ) {
            abort(403, 'Invalid login token.');
        }

        // Log in & invalidate token
        $request->session()->regenerate();
        Auth::guard('customer')->login($customer, remember: true);
        $request->session()->put('portal_customer_id', $customer->getKey());

        $customer->forceFill([
            'login_token_hash'       => null,
            'login_token_expires_at' => null,
        ])->save();

        $intended = (string) $request->query('intended', $request->session()->pull('portal.intended', ''));
        if ($intended !== '' && str_starts_with($intended, '/')) {
            return redirect()->to($intended);
        }

        return redirect()->route('portal.home');
    }

    /** Back-compat alias */
    public function consume(Request $request)
    {
        return $this->magicLoginQS($request);
    }

    /** POST /p/logout */
    public function logout(Request $request)
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    /* =========================================================================
     | PORTAL PAGES
     * ========================================================================= */

    /** GET /p */
    public function home(Request $request)
    {
        $user = $this->portalUser($request);
        if (!$user) return redirect()->route('portal.login');

        $userId    = $user->getKey();
        $userEmail = strtolower(trim((string) ($user->email ?? '')));

        $bookings = Booking::query()
            ->where(function ($q) use ($userId, $userEmail) {
                if (Schema::hasColumn('bookings', 'customer_id')) {
                    $q->orWhere('customer_id', $userId);
                }
                if ($userEmail && Schema::hasColumn('bookings', 'customer_email')) {
                    $q->orWhereRaw('LOWER(customer_email) = ?', [$userEmail]);
                }
                $q->orWhereHas('customer', fn ($cq) => $cq->whereRaw('LOWER(email) = ?', [$userEmail]));
            })
            ->orderByDesc(Schema::hasColumn('bookings', 'start_at') ? 'start_at' : 'created_at')
            ->get();

        return view('portal.home', [
            'customer' => $user,
            'bookings' => $bookings,
        ]);
    }

    /** GET /portal */
    public function dashboard(Request $request)
    {
        $customer = $this->currentCustomerOrRedirect($request);

        $bookings = Booking::with('customer')
            ->when(Schema::hasColumn('bookings', 'customer_id'), fn ($q) => $q->where('customer_id', $customer->getKey()))
            ->latest(Schema::hasColumn('bookings', 'start_at') ? 'start_at' : 'created_at')
            ->take(25)
            ->get();

        return view('portal.dashboard', compact('customer', 'bookings'));
    }

    /**
     * GET /p/pay
     * Accepts route-model binding or ?booking={id} or ?reference={ref}
     * (Requires portal login; for public token flow use show()).
     */
    public function pay(Request $request, ?Booking $booking = null)
    {
        $user = $this->portalUser($request);
        if (!$user) return redirect()->route('portal.login');

        if (!$booking) {
            $id  = (int) $request->query('booking', 0);
            $ref = (string) $request->query('reference', '');
            $booking = $id ? Booking::find($id)
                           : ($ref ? Booking::where('reference', $ref)->first() : null);
        }

        if (!$booking) {
            return redirect()->route('portal.home')->with('claim_error', 'Booking not found.');
        }

        // Authorize relationship
        $sameUser = false;
        if (Schema::hasColumn('bookings', 'customer_id') && $booking->customer_id) {
            $sameUser = $sameUser || ((int) $booking->customer_id === (int) $user->getKey());
        }
        if (Schema::hasColumn('bookings', 'customer_email') && $booking->customer_email && $user->email) {
            $sameUser = $sameUser || (strtolower($booking->customer_email) === strtolower($user->email));
        }
        if ($booking->relationLoaded('customer') || method_exists($booking, 'customer')) {
            $sameUser = $sameUser || (
                $booking->customer?->email && strtolower($booking->customer->email) === strtolower((string) $user->email)
            );
        }
        abort_if(!$sameUser, 403, 'This booking belongs to a different account.');

        $paid    = $this->sumPaid($booking);
        $total   = (int) ($booking->total_amount ?? 0);
        $balance = max(0, $total - $paid);

        return view('portal.pay', [
            'booking'        => $booking->loadMissing('payments'),
            'user'           => $user,
            'paid'           => $paid,
            'total'          => $total,
            'balance'        => $balance,
            'clientSecret'   => null, // your JS can call POST /p/{booking}/intent to create
            'publishableKey' => config('services.stripe.key') ?: config('services.stripe.publishable_key'),
        ]);
    }

    /* =========================================================================
     | CLAIM BOOKING
     * ========================================================================= */

    /** GET /p/claim[?reference=ABC123] */
    public function claimForm(Request $request)
    {
        $this->currentCustomerOrRedirect($request);
        return view('portal.claim', [
            'reference' => (string) $request->query('reference', ''),
            'email'     => (string) $request->query('email', ''),
        ]);
    }

    /** POST /p/claim */
    public function claim(Request $request)
    {
        $customer = $this->currentCustomerOrRedirect($request);

        $data = $request->validate([
            'reference' => 'required|string|max:64',
            'email'     => 'nullable|email',
        ]);

        $reference = strtoupper(trim($data['reference']));
        $email     = strtolower(trim((string) ($data['email'] ?? '')));

        /** @var Booking|null $booking */
        $booking = Booking::whereRaw('UPPER(reference) = ?', [$reference])->first();

        if (!$booking) {
            return back()->withErrors(['reference' => 'We couldn’t find that booking reference.'])->withInput();
        }

        if ((int) ($booking->customer_id ?? 0) === (int) $customer->getKey()) {
            return redirect()->route('portal.dashboard')->with('status', 'Booking already linked to your account.');
        }

        if ($booking->customer_id && (int) $booking->customer_id !== (int) $customer->getKey()) {
            $bookingEmail = strtolower(trim((string) ($booking->customer?->email ?? '')));
            $match = $bookingEmail && $bookingEmail === strtolower((string) $customer->email);

            if ($email !== '') {
                $match = $match || ($email === strtolower((string) $customer->email)) || ($email === $bookingEmail);
            }

            if (!$match) {
                return back()->withErrors([
                    'reference' => 'This booking is already linked to another account. Enter the booking email or contact support.',
                ])->withInput();
            }
        }

        $booking->customer()->associate($customer);
        $booking->save();

        Log::info('[portal/claim] booking linked', [
            'reference'   => $booking->reference,
            'customer_id' => $customer->getKey(),
        ]);

        return redirect()->route('portal.dashboard')->with('status', 'Booking has been linked to your account.');
    }

    /* =========================================================================
     | VEVS FAST PATH — /pay?ref=&amount=&email=[&currency=]
     * ========================================================================= */

    public function vevsDirectPay(Request $request)
    {
        $ref       = strtoupper(trim((string) $request->query('ref', '')));
        $amount    = (int) $request->query('amount', 0);
        $emailRaw  = strtolower(trim((string) $request->query('email', '')));
        $currency  = strtoupper(trim((string) $request->query('currency', 'NZD')));

        abort_if($ref === '', 422, 'Missing booking reference (?ref).');
        abort_if($amount <= 0, 422, 'Invalid amount (?amount must be integer cents).');
        abort_if(strlen($currency) !== 3, 422, 'Invalid currency code.');

        // Ensure/attach customer
        $customer = null;
        if ($emailRaw !== '') {
            $customer = Customer::updateOrCreate(
                ['email' => $emailRaw],
                [
                    'first_name' => $request->query('first_name', ''),
                    'last_name'  => $request->query('last_name', ''),
                ]
            );
        }

        /** @var Booking $booking */
        $booking = Booking::updateOrCreate(
            ['reference' => $ref],
            array_filter([
                'customer_id'    => $customer?->getKey(),
                'status'         => 'pending',
                'currency'       => $currency,
                'deposit_amount' => $amount,
            ], fn ($v) => $v !== null)
        );

        if ($customer && !$booking->customer_id) {
            $booking->forceFill(['customer_id' => $customer->getKey()])->save();
        }

        // PI for deposit amount (normal capture)
        [$payment, $pi] = $this->buildOrUpdateIntent(
            booking:  $booking->loadMissing('customer'),
            purpose:  'booking_deposit',
            amount:   $amount,
            currency: $currency,
            idempotencyKey: 'vevs.deposit.' . $booking->getKey() . '.' . $amount
        );

        return view('portal.pay', [
            'booking'        => $booking,
            'user'           => $booking->customer,
            'paid'           => $this->sumPaid($booking),
            'total'          => (int) ($booking->total_amount ?? 0),
            'balance'        => max(0, (int) ($booking->total_amount ?? 0) - $this->sumPaid($booking)),
            'clientSecret'   => $pi?->client_secret ?? null,
            'publishableKey' => config('services.stripe.key') ?: config('services.stripe.publishable_key'),
            'payment'        => $payment,
        ]);
    }

    /** GET /vevs/landing — forwards to /pay if params present, else to login */
    public function vevsLanding(Request $request)
    {
        $ref      = (string) $request->query('ref', '');
        $amount   = (string) $request->query('amount', '');
        $email    = (string) $request->query('email', '');
        $currency = (string) $request->query('currency', 'NZD');

        if ($ref !== '' && $amount !== '' && $email !== '') {
            $qs = http_build_query(compact('ref', 'amount', 'email', 'currency'));
            return redirect()->to('/pay?' . $qs);
        }

        if ($ref !== '') {
            return redirect()->route('portal.login', ['intended' => '/p?claim=' . urlencode($ref)]);
        }

        return redirect()->route('portal.login');
    }

    /* =========================================================================
     | SIGNED MAGIC LINK — /p/t?ref=&amount=&type=&exp=&sig=
     * ========================================================================= */

    /**
     * GET /p/t?ref=ABC123&amount=500.00&type=balance&exp=...&sig=...
     * Named route: portal.magic
     *
     * Accepts a pre-signed link and forwards the customer to the normal pay screen.
     * The signature covers: ref, amount (nullable), type, exp.
     */
    public function magicLink(Request $request)
    {
        $ref    = (string) $request->query('ref');
        $amount = $request->query('amount'); // "500.00" or null
        $type   = (string) ($request->query('type') ?? 'balance');
        $exp    = (int) $request->query('exp');
        $sig    = (string) $request->query('sig');

        // Recompute signature exactly as generated
        $baseQ = array_filter(
            ['ref' => $ref, 'amount' => $amount, 'type' => $type, 'exp' => $exp],
            fn ($v) => $v !== null
        );
        $base = http_build_query($baseQ);
        $calc = hash_hmac('sha256', $base, config('services.magic_links.secret', env('MAGIC_LINK_SECRET')));

        abort_unless(hash_equals($calc, $sig), 403, 'Invalid link');
        abort_if($exp < now()->timestamp, 410, 'Link expired');

        // Find booking by reference
        $booking = Booking::where('reference', $ref)->firstOrFail();

        // Stash intent in session so your pay page can adjust behavior if you want
        $request->session()->put('portal.magic', [
            'booking_id' => $booking->id,
            'type'       => $type,   // e.g. 'balance', 'hold', 'checkout'
            'amount'     => $amount, // nullable string like "500.00"
            'exp'        => $exp,
        ]);

        // Redirect to the normal pay screen for this booking
        return redirect()->route('portal.pay', ['booking' => $booking->id]);
    }

    /* =========================================================================
     | PORTAL AJAX: Create PaymentIntent(s) for balance and optional bond
     * ========================================================================= */

    /**
     * POST /p/{booking}/intent
     * Body: includeHold:bool, saveForLater:bool, split:boolean (default false)
     *
     * If includeHold=true and split=true:
     *  - Creates 2 intents: balance (charge) + bond (manual-capture auth)
     * Else:
     *  - Creates a single intent for balance (+bond added to amount if includeHold=true)
     */
    public function portalCreateIntent(Request $request, Booking $booking)
    {
        $customer      = $this->portalUser($request) ?: $booking->customer;
        $includeHold   = $request->boolean('includeHold');
        $saveForLater  = $request->boolean('saveForLater');
        $splitBond     = $request->boolean('split', false);

        $paid     = $this->sumPaid($booking);
        $total    = (int) ($booking->total_amount ?? 0);
        $balance  = max(0, $total - $paid);
        $bond     = (int) ($booking->hold_amount ?? 0);
        $currency = strtoupper((string) ($booking->currency ?? 'NZD'));

        if ($balance <= 0 && (!$includeHold || $bond <= 0)) {
            return response()->json(['error' => 'There is nothing to pay.'], 422);
        }

        // Attach a Stripe customer if saving for later
        $attach = $saveForLater && $customer ? $customer : null;

        if ($includeHold && $splitBond && $bond > 0) {
            // Create separate intents: balance (capture) + bond (authorization only)
            $response = [];

            if ($balance > 0) {
                [$payPayment, $payPI] = $this->buildOrUpdateIntent(
                    booking:        $booking->loadMissing('customer'),
                    purpose:        'booking_balance',
                    amount:         $balance,
                    currency:       $currency,
                    idempotencyKey: 'portal.balance.' . $booking->getKey() . '.' . $balance,
                    attachCustomer: $attach
                );
                $response['balance'] = [
                    'payment_id'   => $payPayment?->getKey(),
                    'clientSecret' => $payPI?->client_secret,
                ];
            }

            [$bondPayment, $bondPI] = $this->buildOrUpdateBondIntent(
                booking:        $booking,
                amount:         $bond,
                currency:       $currency,
                idempotencyKey: 'portal.bond.' . $booking->getKey() . '.' . $bond,
                attachCustomer: $attach
            );

            $response['bond'] = [
                'payment_id'   => $bondPayment?->getKey(),
                'clientSecret' => $bondPI?->client_secret,
            ];

            return response()->json($response);
        }

        // Single intent path (balance + optional bond added to amount)
        $amount = $balance + ($includeHold ? $bond : 0);

        [$payment, $pi] = $this->buildOrUpdateIntent(
            booking:        $booking->loadMissing('customer'),
            purpose:        $includeHold ? 'balance_plus_bond' : 'balance',
            amount:         $amount,
            currency:       $currency,
            idempotencyKey: 'portal.single.' . $booking->getKey() . '.' . $amount,
            attachCustomer: $attach
        );

        return response()->json([
            'clientSecret' => $pi?->client_secret ?? null,
            'payment_id'   => $payment?->getKey(),
        ]);
    }

    /**
     * POST /p/{booking}/pay-intent
     * Simple variant (reuses/creates an intent and returns client_secret).
     */
    public function createPayIntent(Request $request, Booking $booking)
    {
        // Basic validation
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:50'], // cents; min ~$0.50
            'email'  => ['nullable', 'email'],
        ]);

        // Optional cap to current balance
        $total   = (int) ($booking->total_amount ?? 0);
        $paid    = (int) ($booking->amount_paid  ?? 0);
        $balance = max(0, $total - $paid);
        if ($balance > 0 && $data['amount'] > $balance) {
            $data['amount'] = $balance;
        }

        $currency = strtolower($booking->currency ?? 'nzd');
        $stripe   = $this->stripe();

        // Reuse intent via booking->meta
        $meta     = (array) ($booking->meta ?? []);
        $intentId = $meta['portal_intent_id'] ?? null;

        try {
            if ($intentId) {
                $intent = $stripe->paymentIntents->retrieve($intentId);
                if (in_array($intent->status, ['requires_payment_method','requires_confirmation','requires_action'], true)) {
                    $intent = $stripe->paymentIntents->update($intent->id, [
                        'amount'                    => $data['amount'],
                        'currency'                  => $currency,
                        'receipt_email'             => $data['email'] ?? ($booking->customer->email ?? null),
                        'automatic_payment_methods' => ['enabled' => true],
                        'metadata'                  => [
                            'booking_id'    => (string)$booking->id,
                            'reference'     => (string)($booking->reference ?? ''),
                            'brand'         => (string)($booking->brand ?? ''),
                            'portal'        => 'pay',
                        ],
                    ]);
                } else {
                    $intent = null; // create new below
                }
            } else {
                $intent = null;
            }

            if (!$intent) {
                $intent = $stripe->paymentIntents->create([
                    'amount'                    => $data['amount'],
                    'currency'                  => $currency,
                    'confirmation_method'       => 'automatic',
                    'automatic_payment_methods' => ['enabled' => true],
                    'receipt_email'             => $data['email'] ?? ($booking->customer->email ?? null),
                    'metadata'                  => [
                        'booking_id'    => (string)$booking->id,
                        'reference'     => (string)($booking->reference ?? ''),
                        'brand'         => (string)($booking->brand ?? ''),
                        'portal'        => 'pay',
                    ],
                ]);
                $meta['portal_intent_id'] = $intent->id;
                $booking->meta = $meta;
                $booking->save();
            }

            return response()->json(['clientSecret' => $intent->client_secret]);
        } catch (Throwable $e) {
            report($e);
            return response('Could not create payment. '.$e->getMessage(), 422);
        }
    }

    /**
     * POST /p/{booking}/hold-recorded
     * Body: pi_id (required), payment_id (optional int)
     * When the frontend confirms a bond authorization, call this to persist linkage/status.
     */
    public function holdRecorded(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'pi_id'      => ['required','string'],
            'payment_id' => ['nullable','integer'],
        ]);

        $piId   = (string) $data['pi_id'];
        $payId  = $data['payment_id'] ?? null;
        $stripe = $this->stripe();

        try {
            $pi = $stripe->paymentIntents->retrieve($piId);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['ok' => false, 'error' => 'Unable to retrieve PaymentIntent.'], 422);
        }

        // If we were given a Payment row, update it; otherwise create a lightweight record.
        $payment = $payId ? Payment::find($payId) : null;

        if (!$payment) {
            // Create a minimal record with purpose/type mapped to 'bond_hold'
            $payment = new Payment();
            if (Schema::hasColumn('payments', 'booking_id'))  $payment->booking_id  = $booking->getKey();
            if (Schema::hasColumn('payments', 'customer_id')) $payment->customer_id = optional($booking->customer)->getKey();
            if (Schema::hasColumn('payments', 'booking_reference') && isset($booking->reference)) {
                $payment->booking_reference = (string) $booking->reference;
            }
            $purposeCol = Schema::hasColumn('payments', 'purpose')
                ? 'purpose'
                : (Schema::hasColumn('payments', 'type') ? 'type' : null);
            if ($purposeCol) {
                $payment->{$purposeCol} = $this->normalizePurposeForSchema('bond_hold', $purposeCol);
            }
        }

        if (Schema::hasColumn('payments', 'stripe_payment_intent_id')) {
            $payment->stripe_payment_intent_id = $pi->id;
        }
        if (Schema::hasColumn('payments', 'stripe_payment_method_id') && ($pi->payment_method ?? null)) {
            $payment->stripe_payment_method_id = $pi->payment_method;
        }
        if (Schema::hasColumn('payments', 'amount')) {
            $payment->amount = (int) ($pi->amount ?? 0);
        }
        if (Schema::hasColumn('payments', 'currency') && ($pi->currency ?? null)) {
            $payment->currency = strtoupper((string) $pi->currency);
        }
        if (Schema::hasColumn('payments', 'status')) {
            // For holds, Stripe will be "requires_capture" after successful auth
            $payment->status = (string) ($pi->status ?? 'pending');
        }

        $payment->save();

        return response()->json(['ok' => true, 'payment_id' => $payment->getKey(), 'status' => $payment->status ?? null]);
    }

    /* =========================================================================
     | BOND HELPERS (manual-capture auth)
     * ========================================================================= */

    /** Create/refresh a manual-capture PaymentIntent for the bond. */
    private function buildOrUpdateBondIntent(
        Booking $booking,
        int $amount,
        string $currency,
        string $idempotencyKey,
        Customer|bool|null $attachCustomer = null
    ): array {
        $currencyLower = strtolower($currency);

        // Upsert Payment model with purpose/type mapping
        $purposeCol = Schema::hasColumn('payments', 'purpose') ? 'purpose' : (Schema::hasColumn('payments', 'type') ? 'type' : null);
        $purposeValue = $purposeCol ? $this->normalizePurposeForSchema('bond_hold', $purposeCol) : 'bond_hold';

        $payment = Payment::firstOrCreate(
            array_filter([
                'booking_id' => Schema::hasColumn('payments', 'booking_id') ? $booking->getKey() : null,
                $purposeCol  => $purposeValue,
            ], fn ($v) => $v !== null),
            array_filter([
                'customer_id' => Schema::hasColumn('payments', 'customer_id') ? ($booking->customer?->getKey()) : null,
                'amount'      => $amount,
                'currency'    => Schema::hasColumn('payments', 'currency') ? strtoupper($currency) : null,
                'status'      => Schema::hasColumn('payments', 'status') ? 'pending' : null,
            ], fn ($v) => $v !== null)
        );

        $dirty = false;
        if ($payment->amount !== $amount) { $payment->amount = $amount; $dirty = true; }
        if (Schema::hasColumn('payments', 'currency') && strtoupper((string) $payment->currency) !== strtoupper($currency)) {
            $payment->currency = strtoupper($currency); $dirty = true;
        }
        if ($dirty) $payment->save();

        $stripeCustomerId = null;
        $customerToAttach = $attachCustomer instanceof Customer
            ? $attachCustomer
            : ($attachCustomer === true ? $booking->customer : null);

        if ($customerToAttach) {
            $stripeCustomerId = $this->ensureStripeCustomer($customerToAttach) ?: null;
        } elseif ($booking->customer) {
            $stripeCustomerId = $booking->customer->stripe_customer_id ?: null;
        }

        $pi = null;

        try {
            if (!empty($payment->stripe_payment_intent_id)) {
                $pi = $this->stripe()->paymentIntents->retrieve($payment->stripe_payment_intent_id);
                // Only update if still modifiable
                if (in_array($pi->status, ['requires_payment_method','requires_confirmation','requires_action'], true)) {
                    $pi = $this->stripe()->paymentIntents->update($pi->id, [
                        'amount'   => $amount,
                        'currency' => $currencyLower,
                        'metadata' => [
                            'booking_id'  => (string) $booking->getKey(),
                            'booking_ref' => (string) $booking->reference,
                            'payment_id'  => (string) $payment->getKey(),
                            'purpose'     => 'bond_hold',
                        ],
                    ]);
                }
            } else {
                $create = [
                    'amount'                    => $amount,
                    'currency'                  => $currencyLower,
                    'capture_method'            => 'manual', // authorization only
                    'confirmation_method'       => 'automatic',
                    'automatic_payment_methods' => ['enabled' => true],
                    'description'               => "Bond authorization for {$booking->reference}",
                    'metadata'                  => [
                        'booking_id'  => (string) $booking->getKey(),
                        'booking_ref' => (string) $booking->reference,
                        'payment_id'  => '',
                        'purpose'     => 'bond_hold',
                    ],
                ];

                if ($stripeCustomerId) {
                    $create['customer'] = $stripeCustomerId;
                    $create['setup_future_usage'] = 'off_session'; // keep card on file
                }

                $pi = $this->stripe()->paymentIntents->create($create, ['idempotency_key' => $idempotencyKey]);

                if (($pi->metadata['payment_id'] ?? '') === '') {
                    $this->stripe()->paymentIntents->update($pi->id, [
                        'metadata' => array_merge($pi->metadata->toArray(), [
                            'payment_id' => (string) $payment->getKey(),
                        ]),
                    ]);
                }

                if (Schema::hasColumn('payments', 'stripe_payment_intent_id')) {
                    $payment->stripe_payment_intent_id = $pi->id;
                }
                if (Schema::hasColumn('payments', 'status')) {
                    $payment->status = 'pending';
                }
                $payment->save();
            }
        } catch (Throwable $e) {
            Log::error('[portal] Stripe Bond PI error', [
                'booking_id' => $booking->getKey(),
                'payment_id' => $payment->getKey(),
                'error'      => $e->getMessage(),
            ]);
            return [$payment, null];
        }

        // Track PM id if present
        $pmId = $pi->payment_method ?? null;
        if ($pmId && Schema::hasColumn('payments', 'stripe_payment_method_id')) {
            $payment->stripe_payment_method_id = $pmId;
            $payment->save();
        }

        return [$payment, $pi];
    }

    /* =========================================================================
     | ADMIN — Post-hire charge off-session (card on file)
     * ========================================================================= */
    public function postHireCharge(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'amount_nzd' => 'required|numeric|min:0.50',
            'reason'     => 'required|string|max:200',
        ]);

        $amount   = (int) round($data['amount_nzd'] * 100);
        $currency = strtolower((string) ($booking->currency ?? 'nzd'));
        $customer = $booking->customer;

        if (!$customer || empty($customer->stripe_customer_id)) {
            return back()->with('claim_error', 'No saved card for this customer.');
        }

        $stripe = $this->stripe();

        // Pick a PM: default on customer or last used on a payment
        $pmId = null;
        try {
            $cust = $stripe->customers->retrieve($customer->stripe_customer_id);
            $pmId = $cust->invoice_settings->default_payment_method ?? null;
        } catch (\Exception $e) {}

        if (!$pmId && Schema::hasColumn('payments', 'stripe_payment_method_id')) {
            $pmId = optional(
                $booking->payments()->whereNotNull('stripe_payment_method_id')->latest()->first()
            )->stripe_payment_method_id;
        }

        if (!$pmId) {
            return back()->with('claim_error', 'No card on file. Ask the customer to complete a payment and tick “save card”.');
        }

        try {
            $pi = $stripe->paymentIntents->create([
                'amount'         => $amount,
                'currency'       => $currency,
                'customer'       => $customer->stripe_customer_id,
                'payment_method' => $pmId,
                'off_session'    => true,
                'confirm'        => true,
                'description'    => "Post-hire charge for booking {$booking->reference}: {$data['reason']}",
                'metadata'       => [
                    'booking_id' => (string) $booking->getKey(),
                    'reference'  => (string) $booking->reference,
                    'purpose'    => 'post_charge',
                    'reason'     => $data['reason'],
                ],
                'automatic_payment_methods' => ['enabled' => true],
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            $pi = $e->getError()->payment_intent ?? null;

            if ($pi && $pi->status === 'requires_action') {
                $this->storePaymentModel(
                    booking:   $booking,
                    customer:  $customer,
                    purpose:   'post_charge',
                    amount:    $amount,
                    status:    'requires_action',
                    currency:  strtoupper($currency),
                    piId:      $pi->id,
                    pmId:      $pmId
                );

                return back()->with('claim_error', 'Card requires authentication. Send the customer a link to confirm.');
            }

            throw $e;
        }

        $this->storePaymentModel(
            booking:   $booking,
            customer:  $customer,
            purpose:   'post_charge',
            amount:    (int) ($pi->amount_received ?? $pi->amount ?? $amount),
            status:    $pi->status,
            currency:  strtoupper($currency),
            piId:      $pi->id,
            pmId:      $pmId
        );

        return back()->with('claim_ok', 'Post-hire charge succeeded.');
    }

    /**
     * POST /admin/bookings/{booking}/bond/capture
     * Capture a previously authorized bond (partial or full).
     * Body: amount_nzd (optional; default = full capturable)
     */
    public function adminCaptureBond(Request $request, Booking $booking)
    {
        $data = $request->validate([
            'amount_nzd' => 'nullable|numeric|min:0.50',
        ]);

        // Find the latest bond Payment with a PI
        $bond = $booking->payments()
            ->where(function ($q) {
                if (Schema::hasColumn('payments', 'purpose')) {
                    $q->where('purpose', 'bond_hold');
                } elseif (Schema::hasColumn('payments', 'type')) {
                    $q->where('type', 'hold');
                }
            })
            ->whereNotNull('stripe_payment_intent_id')
            ->latest()
            ->first();

        if (!$bond) return back()->with('claim_error', 'No bond authorization found.');

        $stripe = $this->stripe();
        $pi = $stripe->paymentIntents->retrieve($bond->stripe_payment_intent_id);

        $amountToCapture = isset($data['amount_nzd'])
            ? (int) round($data['amount_nzd'] * 100)
            : ($pi->amount_capturable ?? $pi->amount);

        $pi = $stripe->paymentIntents->capture($pi->id, [
            'amount_to_capture' => $amountToCapture,
        ]);

        // Store capture row
        $this->storePaymentModel(
            booking:   $booking,
            customer:  $booking->customer,
            purpose:   'bond_capture',
            amount:    (int) ($pi->amount_received ?? $amountToCapture),
            status:    $pi->status,
            currency:  strtoupper($pi->currency ?? ($booking->currency ?? 'NZD')),
            piId:      $pi->id,
            pmId:      $pi->payment_method ?? null
        );

        return back()->with('claim_ok', 'Bond captured.');
    }

    /**
     * POST /admin/bookings/{booking}/bond/void
     * Cancel a bond authorization (releases funds).
     */
    public function adminVoidBond(Request $request, Booking $booking)
    {
        $bond = $booking->payments()
            ->where(function ($q) {
                if (Schema::hasColumn('payments', 'purpose')) {
                    $q->where('purpose', 'bond_hold');
                } elseif (Schema::hasColumn('payments', 'type')) {
                    $q->where('type', 'hold');
                }
            })
            ->whereNotNull('stripe_payment_intent_id')
            ->latest()
            ->first();

        if (!$bond) return back()->with('claim_error', 'No bond authorization found.');

        $stripe = $this->stripe();
        $pi = $stripe->paymentIntents->cancel($bond->stripe_payment_intent_id);

        // Store void row
        $this->storePaymentModel(
            booking:   $booking,
            customer:  $booking->customer,
            purpose:   'bond_void',
            amount:    0,
            status:    $pi->status,
            currency:  strtoupper($pi->currency ?? ($booking->currency ?? 'NZD')),
            piId:      $pi->id,
            pmId:      $pi->payment_method ?? null
        );

        return back()->with('claim_ok', 'Bond authorization voided.');
    }

    /* =========================================================================
     | Helpers
     * ========================================================================= */

    /** Sum successful amounts (int, cents). */
    private function sumPaid(Booking $booking): int
    {
        $statuses = ['succeeded', 'paid', 'captured', 'completed'];
        return (int) ($booking->payments?->whereIn('status', $statuses)->sum('amount') ?? 0);
    }

    /** Get portal user from guard or session (Customer or null). */
    protected function portalUser(Request $request): ?Customer
    {
        if (Auth::guard('customer')->check()) {
            /** @var Customer $c */
            $c = Auth::guard('customer')->user();
            return $c;
        }

        $id = (int) $request->session()->get('portal_customer_id', 0);
        return $id > 0 ? Customer::find($id) : null;
    }

    protected function currentCustomerOrRedirect(Request $request): Customer
    {
        if ($c = $this->portalUser($request)) {
            return $c;
        }
        redirect()->route('portal.login')->send();
        exit; // static analysers
    }

    /**
     * Create or update a Payment + Stripe PaymentIntent for a booking (normal charge).
     * Returns [Payment $payment, \Stripe\PaymentIntent|null]
     */
    private function buildOrUpdateIntent(
        Booking $booking,
        string $purpose,
        int $amount,
        string $currency,
        string $idempotencyKey,
        Customer|bool|null $attachCustomer = null
    ): array {
        $currencyLower = strtolower($currency);

        // Upsert Payment model (normalize purpose for legacy `type`)
        $purposeCol  = Schema::hasColumn('payments', 'purpose') ? 'purpose' : (Schema::hasColumn('payments', 'type') ? 'type' : null);
        $purposeVal  = $purposeCol ? $this->normalizePurposeForSchema($purpose, $purposeCol) : $purpose;

        $payment = Payment::firstOrCreate(
            array_filter([
                'booking_id' => Schema::hasColumn('payments', 'booking_id') ? $booking->getKey() : null,
                $purposeCol  => $purposeVal,
            ], fn ($v) => $v !== null),
            array_filter([
                'customer_id' => Schema::hasColumn('payments', 'customer_id') ? ($booking->customer?->getKey()) : null,
                'amount'      => $amount,
                'currency'    => Schema::hasColumn('payments', 'currency') ? strtoupper($currency) : null,
                'status'      => Schema::hasColumn('payments', 'status') ? 'pending' : null,
            ], fn ($v) => $v !== null)
        );

        $dirty = false;
        if ($payment->amount !== $amount) { $payment->amount = $amount; $dirty = true; }
        if (Schema::hasColumn('payments', 'currency') && strtoupper((string) $payment->currency) !== strtoupper($currency)) {
            $payment->currency = strtoupper($currency); $dirty = true;
        }
        if ($dirty) $payment->save();

        // Stripe customer attach?
        $stripeCustomerId = null;
        $customerToAttach = $attachCustomer instanceof Customer
            ? $attachCustomer
            : ($attachCustomer === true ? $booking->customer : null);

        if ($customerToAttach) {
            $stripeCustomerId = $this->ensureStripeCustomer($customerToAttach) ?: null;
        } elseif ($booking->customer) {
            $stripeCustomerId = $booking->customer->stripe_customer_id ?: null;
        }

        // Create/update PI
        $pi = null;

        try {
            if (!empty($payment->stripe_payment_intent_id)) {
                $pi = $this->stripe()->paymentIntents->retrieve($payment->stripe_payment_intent_id);
                if ((int) $pi->amount !== $amount || strtolower($pi->currency) !== $currencyLower) {
                    $pi = $this->stripe()->paymentIntents->update($pi->id, [
                        'amount'   => $amount,
                        'currency' => $currencyLower,
                        'metadata' => [
                            'booking_id'  => (string) $booking->getKey(),
                            'booking_ref' => (string) $booking->reference,
                            'payment_id'  => (string) $payment->getKey(),
                            'purpose'     => $purpose,
                        ],
                        'automatic_payment_methods' => ['enabled' => true],
                    ]);
                }
            } else {
                $create = [
                    'amount'                    => $amount,
                    'currency'                  => $currencyLower,
                    'automatic_payment_methods' => ['enabled' => true],
                    'description'               => ucfirst(str_replace('_', ' ', $purpose)) . " for {$booking->reference}",
                    'metadata'                  => [
                        'booking_id'  => (string) $booking->getKey(),
                        'booking_ref' => (string) $booking->reference,
                        'payment_id'  => '',
                        'purpose'     => $purpose,
                    ],
                ];

                if ($stripeCustomerId) {
                    $create['customer'] = $stripeCustomerId;
                    $create['setup_future_usage'] = 'off_session';
                }

                $pi = $this->stripe()->paymentIntents->create($create, ['idempotency_key' => $idempotencyKey]);

                if (($pi->metadata['payment_id'] ?? '') === '') {
                    $this->stripe()->paymentIntents->update($pi->id, [
                        'metadata' => array_merge($pi->metadata->toArray(), [
                            'payment_id' => (string) $payment->getKey(),
                        ]),
                    ]);
                }

                if (Schema::hasColumn('payments', 'stripe_payment_intent_id')) {
                    $payment->stripe_payment_intent_id = $pi->id;
                }
                if (Schema::hasColumn('payments', 'status')) {
                    $payment->status = 'pending';
                }
                $payment->save();
            }
        } catch (Throwable $e) {
            Log::error('[portal] Stripe PI error', [
                'booking_id' => $booking->getKey(),
                'payment_id' => $payment->getKey(),
                'error'      => $e->getMessage(),
            ]);
            return [$payment, null];
        }

        // If Stripe added a PM, store it
        $pmId = $pi->payment_method ?? null;
        if ($pmId && Schema::hasColumn('payments', 'stripe_payment_method_id')) {
            $payment->stripe_payment_method_id = $pmId;
            $payment->save();
        }

        return [$payment, $pi];
    }

    /** Store a Payment row across differing schemas. */
    private function storePaymentModel(
        Booking $booking,
        Customer $customer,
        string $purpose,
        int $amount,
        string $status,
        string $currency,
        ?string $piId = null,
        ?string $pmId = null
    ): Payment {
        $payment = new Payment();

        if (Schema::hasColumn('payments', 'booking_id'))  $payment->booking_id  = $booking->getKey();
        if (Schema::hasColumn('payments', 'customer_id')) $payment->customer_id = $customer->getKey();
        if (Schema::hasColumn('payments', 'booking_reference') && isset($booking->reference)) {
            $payment->booking_reference = (string) $booking->reference;
        }

        $purposeCol = Schema::hasColumn('payments', 'purpose')
            ? 'purpose'
            : (Schema::hasColumn('payments', 'type') ? 'type' : null);

        if ($purposeCol) {
            $payment->{$purposeCol} = $this->normalizePurposeForSchema($purpose, $purposeCol);
        }

        $payment->amount = $amount;
        if (Schema::hasColumn('payments', 'status'))   $payment->status  = $status;
        if (Schema::hasColumn('payments', 'currency')) $payment->currency = strtoupper($currency);

        if ($piId && Schema::hasColumn('payments', 'stripe_payment_intent_id')) {
            $payment->stripe_payment_intent_id = $piId;
        }
        if ($pmId && Schema::hasColumn('payments', 'stripe_payment_method_id')) {
            $payment->stripe_payment_method_id = $pmId;
        }

        $payment->save();

        return $payment;
    }

    /** Map new-style purposes to legacy `type` enum when needed. */
    private function normalizePurposeForSchema(string $purpose, string $column): string
    {
        if ($column === 'purpose') return $purpose;

        $map = [
            'booking_deposit'  => 'deposit',
            'deposit'          => 'deposit',
            'booking_balance'  => 'balance',
            'balance'          => 'balance',
            'bond_hold'        => 'hold',
            'hold'             => 'hold',
            'bond_void'        => 'refund',
            'refund'           => 'refund',
            'bond_capture'     => 'post_hire_charge',
            'post_hire_charge' => 'post_hire_charge',
            'balance_plus_bond'=> 'balance',
            'custom'           => 'balance',
            'post_charge'      => 'post_hire_charge',
        ];

        return $map[$purpose] ?? $purpose;
    }
}
