<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Job;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Stripe\StripeClient;
use Throwable;

class PaymentController extends Controller
{
    /* =========================================================================
     | Stripe client
     * ========================================================================= */
    private function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    /* =========================================================================
     | ------------------------------- ADMIN -----------------------------------
     | Immediate admin-side charges: Deposit, Balance, Post-hire.
     * ========================================================================= */

    /** Admin: take a DEPOSIT now */
    public function deposit(Request $request, Booking $booking)
    {
        $customer = $booking->customer;
        abort_unless($customer, 422, 'Customer not found for booking.');

        $fallback = (int) ($booking->deposit_amount ?? round(($booking->total_amount ?? 0) * 0.5));
        $amount   = (int) $request->integer('amount_cents', $fallback);
        abort_if($amount <= 0, 422, 'Invalid deposit amount.');

        $currency = strtoupper($booking->currency ?? 'NZD');
        $pmId     = trim((string) $request->input('payment_method', ''));

        $payment = $this->firstOrCreatePayment($booking, $customer, 'booking_deposit', $amount, $currency, 'card');

        $stripe  = $this->stripe();
        $idemKey = "deposit:{$booking->id}:{$amount}";

        try {
            $params = [
                'amount'   => $amount,
                'currency' => strtolower($currency),
                'metadata' => [
                    'booking_id' => (string) $booking->id,
                    'reference'  => (string) $booking->reference,
                    'purpose'    => 'booking_deposit',
                    'payment_id' => (string) $payment->id,
                ],
                'automatic_payment_methods' => ['enabled' => true],
            ];
            if ($customer->stripe_customer_id) $params['customer'] = $customer->stripe_customer_id;
            if ($pmId !== '') {
                $params['payment_method'] = $pmId;
                $params['confirm']        = true;
                $params['off_session']    = true;
            }

            $pi = $stripe->paymentIntents->create($params, ['idempotency_key' => $idemKey]);

            $this->syncPaymentFromPI($payment, $pi, savePm: true);
            $this->maybeSetDefaultPm($pi);

            return back()->with('status', "Deposit payment created (PI {$pi->id}).");
        } catch (Throwable $e) {
            Log::error('[payment.deposit] error', ['booking' => $booking->id, 'amount' => $amount, 'error' => $e->getMessage()]);
            $payment->update(['status' => 'failed']);
            return back()->withErrors('Could not create deposit: ' . $e->getMessage());
        }
    }

    /** Admin: take BALANCE now */
    public function balance(Request $request, Booking $booking)
    {
        $customer = $booking->customer;
        abort_unless($customer, 422, 'Customer not found for booking.');

        [$computed, $currency] = $this->calcBalance($booking);
        $amount = (int) $request->integer('amount_cents', $computed);
        abort_if($amount <= 0, 422, 'No balance due.');

        $pmId = trim((string) $request->input('payment_method', ''));

        $payment = $this->firstOrCreatePayment($booking, $customer, 'booking_balance', $amount, $currency, 'card');

        $stripe  = $this->stripe();
        $idemKey = "balance:{$booking->id}:{$amount}";

        try {
            $params = [
                'amount'   => $amount,
                'currency' => strtolower($currency),
                'metadata' => [
                    'booking_id' => (string) $booking->id,
                    'reference'  => (string) $booking->reference,
                    'purpose'    => 'booking_balance',
                    'payment_id' => (string) $payment->id,
                ],
                'automatic_payment_methods' => ['enabled' => true],
            ];
            if ($customer->stripe_customer_id) $params['customer'] = $customer->stripe_customer_id;
            if ($pmId !== '') {
                $params['payment_method'] = $pmId;
                $params['confirm']        = true;
                $params['off_session']    = true;
            }

            $pi = $stripe->paymentIntents->create($params, ['idempotency_key' => $idemKey]);

            $this->syncPaymentFromPI($payment, $pi, savePm: true);
            $this->maybeSetDefaultPm($pi);

            return back()->with('status', "Balance payment created (PI {$pi->id}).");
        } catch (Throwable $e) {
            Log::error('[payment.balance] error', ['booking' => $booking->id, 'amount' => $amount, 'error' => $e->getMessage()]);
            $payment->update(['status' => 'failed']);
            return back()->withErrors('Could not create balance: ' . $e->getMessage());
        }
    }

    /** Admin: POST-HIRE CHARGE */
    public function postHireCharge(Booking $booking, Request $req)
    {
        $amount   = (int) $req->integer('amount_cents');
        $desc     = (string) $req->string('description', 'Post-hire charge');
        $customer = $booking->customer;

        abort_unless($customer, 400, 'No customer on this booking.');
        abort_if($amount <= 0, 400, 'Amount must be > 0.');

        $stripeCustomerId = $this->ensureStripeCustomer($customer);
        $pmId = $this->pickPaymentMethodId($stripeCustomerId, $booking);
        if (!$pmId) {
            return back()->with('claim_error', 'No saved card for this customer.');
        }

        $stripe = $this->stripe();
        try {
            $pi = $stripe->paymentIntents->create([
                'amount'         => $amount,
                'currency'       => strtolower($booking->currency ?? 'nzd'),
                'customer'       => $stripeCustomerId,
                'payment_method' => $pmId,
                'off_session'    => true,
                'confirm'        => true,
                'description'    => "Post-hire charge for booking {$booking->reference}: {$desc}",
                'metadata'       => [
                    'booking_id' => (string) $booking->id,
                    'purpose'    => 'post_charge',
                    'reason'     => $desc,
                ],
            ]);
        } catch (\Stripe\Exception\CardException $e) {
            $pi = $e->getError()->payment_intent ?? null;
            if ($pi && $pi->status === 'requires_action') {
                $payment = $this->firstOrCreatePayment($booking, $customer, 'post_charge', $amount, strtoupper($booking->currency ?? 'NZD'), 'card');
                $payment->update([
                    'status'                    => 'requires_action',
                    'stripe_payment_intent_id'  => $pi->id,
                    'stripe_payment_method_id'  => $pmId,
                ]);
                return back()->with('claim_error', 'Card needs authentication.');
            }
            throw $e;
        }

        $payment = $this->firstOrCreatePayment($booking, $customer, 'post_charge', (int) ($pi->amount_received ?? $pi->amount ?? $amount), strtoupper($booking->currency ?? 'NZD'), 'card');
        $payment->update([
            'status'                   => $pi->status,
            'stripe_payment_intent_id' => $pi->id,
            'stripe_payment_method_id' => $pmId,
        ]);

        $this->maybeSetDefaultPm($pi);
        return back()->with('claim_ok', 'Post-hire charge succeeded.');
    }

    /* =========================================================================
     | ------------------------ ADMIN: holds (bond) ----------------------------
     * ========================================================================= */

    public function captureHold(Request $request, Payment $payment)
    {
        $request->validate(['amount' => 'nullable|integer|min:1']);
        $piId = $payment->stripe_payment_intent_id ?? null;
        abort_if(!$piId, 400, 'This payment has no Stripe PI id.');

        $args = $request->filled('amount') ? ['amount_to_capture' => (int) $request->integer('amount')] : [];
        $pi = $this->stripe()->paymentIntents->capture($piId, $args);

        $payment->status = $pi->status;
        $payment->amount = (int) ($pi->amount_received ?? $payment->amount);
        $payment->save();

        return back()->with('status', 'Hold captured.');
    }

    public function releaseHold(Payment $payment)
    {
        $piId = $payment->stripe_payment_intent_id ?? null;
        abort_if(!$piId, 400, 'This payment has no Stripe PI id.');

        $pi = $this->stripe()->paymentIntents->cancel($piId);
        $payment->status = $pi->status; // 'canceled'
        $payment->save();

        return back()->with('status', 'Hold released.');
    }

    /* =========================================================================
     | ------------------------------ PORTAL -----------------------------------
     | JSON endpoints for Stripe.js views (deposit, balance, hold)
     * ========================================================================= */

    /** PORTAL: Create PI for 50% DEPOSIT (fallback if deposit_amount not set) */
    public function portalCreateDepositIntent(Request $request, Booking $booking)
    {
        [$amount, $currency] = $this->calcDeposit($booking);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'No deposit configured for this booking.']);
        }

        $custId  = $this->ensureStripeCustomer($booking->customer ?: $this->portalCustomer());
        $payment = $this->firstOrCreatePayment($booking, $booking->customer, 'booking_deposit', $amount, $currency, mechanism: 'card');

        try {
            $pi = $this->upsertPI(
                payment: $payment,
                amount: $amount,
                currency: $currency,
                customerId: $custId,
                description: "Booking deposit {$booking->reference}",
                metadata: [
                    'booking_id'  => (string) $booking->id,
                    'booking_ref' => (string) ($booking->reference ?? ''),
                    'payment_id'  => (string) $payment->id,
                    'purpose'     => 'booking_deposit',
                ],
                idempotencyKey: "portal_deposit_{$booking->id}_{$amount}"
            );

            $this->syncPaymentFromPI($payment, $pi, savePm: true);
        } catch (Throwable $e) {
            Log::error('[portal.deposit-intent] Stripe error', ['booking' => $booking->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['stripe' => 'Unable to set up deposit. Please try again.']);
        }

        return response()->json([
            'ok'           => true,
            'clientSecret' => $pi->client_secret,
            'intentId'     => $pi->id,
            'paymentId'    => $payment->id,
            'amount'       => $amount,
            'currency'     => strtoupper($currency),
            'status'       => $payment->status,
        ]);
    }

    /** PORTAL: After redirect, finalize/record the DEPOSIT */
    public function portalCompleteDeposit(Request $request, Booking $booking)
    {
        return $this->portalCompleteGeneric($request, $booking, 'booking_deposit');
    }

    /** PORTAL: Create PI for BALANCE (remainder) */
    public function portalCreateIntent(Request $request, Booking $booking)
    {
        [$amount, $currency] = $this->calcBalance($booking);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Nothing left to pay.']);
        }

        $custId  = $this->ensureStripeCustomer($booking->customer ?: $this->portalCustomer());
        $payment = $this->firstOrCreatePayment($booking, $booking->customer, 'booking_balance', $amount, $currency, mechanism: 'card');

        try {
            $pi = $this->upsertPI(
                payment: $payment,
                amount: $amount,
                currency: $currency,
                customerId: $custId,
                description: "Booking balance {$booking->reference}",
                metadata: [
                    'booking_id'  => (string) $booking->id,
                    'booking_ref' => (string) ($booking->reference ?? ''),
                    'payment_id'  => (string) $payment->id,
                    'purpose'     => 'booking_balance',
                ],
                idempotencyKey: "portal_balance_{$booking->id}_{$amount}"
            );

            $this->syncPaymentFromPI($payment, $pi, savePm: true);
        } catch (Throwable $e) {
            Log::error('[portal.balance-intent] Stripe error', ['booking' => $booking->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['stripe' => 'Unable to set up balance payment. Please try again.']);
        }

        return response()->json([
            'ok'           => true,
            'clientSecret' => $pi->client_secret,
            'intentId'     => $pi->id,
            'paymentId'    => $payment->id,
            'amount'       => $amount,
            'currency'     => strtoupper($currency),
            'status'       => $payment->status,
        ]);
    }

    /** PORTAL: After redirect, finalize/record the BALANCE */
    public function portalComplete(Request $request, Booking $booking)
    {
        return $this->portalCompleteGeneric($request, $booking, 'booking_balance');
    }

    /** PORTAL: Create PI for a HOLD (bond pre-auth) */
    public function portalCreateHoldIntent(Request $request, Booking $booking)
    {
        $amount = (int) ($booking->hold_amount ?? 0);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['hold' => 'No hold amount configured for this booking.']);
        }

        $currency = strtolower($booking->currency ?? 'nzd');
        $custId   = $this->ensureStripeCustomer($booking->customer ?: $this->portalCustomer());

        $payment = $this->firstOrCreatePayment($booking, $booking->customer, 'hold', $amount, $currency, mechanism: 'hold');

        try {
            $pi = $this->upsertPI(
                payment: $payment,
                amount: $amount,
                currency: $currency,
                customerId: $custId,
                description: "Security hold {$booking->reference}",
                metadata: [
                    'booking_id'  => (string) $booking->id,
                    'booking_ref' => (string) ($booking->reference ?? ''),
                    'payment_id'  => (string) $payment->id,
                    'purpose'     => 'hold',
                ],
                idempotencyKey: "portal_hold_{$booking->id}_{$amount}",
                manualCapture: true
            );

            $this->syncPaymentFromPI($payment, $pi, savePm: true);
        } catch (Throwable $e) {
            Log::error('[portal.hold-intent] Stripe error', ['booking' => $booking->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages(['stripe' => 'Unable to set up the security hold. Please try again.']);
        }

        return response()->json([
            'ok'           => true,
            'clientSecret' => $pi->client_secret,
            'intentId'     => $pi->id,
            'paymentId'    => $payment->id,
            'amount'       => $amount,
            'currency'     => strtoupper($currency),
            'status'       => $payment->status, // expect requires_capture after auth
        ]);
    }

    /** PORTAL: After redirect, record the HOLD */
    public function portalCompleteHold(Request $request, Booking $booking)
    {
        $piId = $request->query('payment_intent');
        abort_if(!$piId, 400, 'Missing payment_intent');

        $stripe = $this->stripe();
        try {
            $pi = $stripe->paymentIntents->retrieve($piId);
        } catch (Throwable $e) {
            return redirect()->route('portal.pay', ['booking' => $booking->id])
                ->with('claim_error', 'Hold could not be confirmed. Please try again.');
        }

        if (!in_array($pi->status, ['requires_capture','processing','succeeded'], true)) {
            return redirect()->route('portal.pay', ['booking' => $booking->id])
                ->with('claim_error', 'Hold not placed. Status: ' . $pi->status);
        }

        $payment = Schema::hasColumn('payments','stripe_payment_intent_id')
            ? Payment::firstOrNew(['stripe_payment_intent_id' => $pi->id])
            : new Payment();

        if (Schema::hasColumn('payments','booking_id'))  $payment->booking_id  = $booking->id;
        if (Schema::hasColumn('payments','customer_id')) $payment->customer_id = $booking->customer?->id;
        $this->assignPurposeAndType($payment, 'hold', 'hold');

        $this->syncPaymentFromPI($payment, $pi, savePm: true);

        return redirect()->route('portal.pay', ['booking' => $booking->id])
            ->with('claim_ok', 'Bond hold placed successfully.');
    }

    /* =========================================================================
     | ------------------------------ LEGACY TOKEN FLOW ------------------------
     * ========================================================================= */

    public function showPortalPay(string $token)
    {
        $booking = Booking::query()
            ->where('portal_token', $token)
            ->with(['customer','payments'])
            ->firstOrFail();

        $secrets     = $this->ensurePortalIntents($booking);
        $outstanding = $this->computeBalanceCents($booking);

        $holdCents = (int) ($booking->hold_amount ?? 0);
        if ($holdCents > 0 && !$this->hasActiveHold($booking)) {
            session()->flash('needs_hold', true);
        }

        return view('portal.pay', [
            'booking'             => $booking,
            'user'                => $booking->customer,
            'stripeKey'           => config('services.stripe.key'),
            'outstanding'         => $outstanding,
            'balanceClientSecret' => $secrets['balance'],
            'bondClientSecret'    => $secrets['bond'],
            'needsHold'           => session('needs_hold', false),
        ]);
    }

    /** Legacy: create/reuse a balance PI via token */
    public function createOrReuseBalanceIntent(Request $request, string $token)
    {
        $booking = Booking::with(['customer','payments'])->where('portal_token', $token)->firstOrFail();

        $outstanding = $this->computeBalanceCents($booking);
        if ($outstanding <= 0) {
            return response()->json(['ok' => true, 'nothingToPay' => true]);
        }

        $stripe = $this->stripe();
        $pi     = null;

        if (Schema::hasColumn('bookings','stripe_balance_pi_id') && !empty($booking->stripe_balance_pi_id)) {
            try {
                $existing = $stripe->paymentIntents->retrieve($booking->stripe_balance_pi_id);
                if (in_array($existing->status, ['requires_payment_method','requires_confirmation','requires_action'], true)) {
                    $pi = $existing;
                }
            } catch (Throwable $e) {
                // ignore, create new below
            }
        }

        if (!$pi) {
            $pi = $stripe->paymentIntents->create([
                'amount'                    => $outstanding,
                'currency'                  => strtolower($booking->currency ?? 'nzd'),
                'customer'                  => $booking->customer?->stripe_customer_id,
                'automatic_payment_methods' => ['enabled' => true],
                'setup_future_usage'        => 'off_session',
                'metadata'                  => [
                    'purpose'    => 'booking_balance',
                    'booking_id' => $booking->id,
                    'reference'  => (string) $booking->reference,
                ],
            ]);

            if (Schema::hasColumn('bookings','stripe_balance_pi_id')) {
                $booking->forceFill(['stripe_balance_pi_id' => $pi->id])->save();
            }
        }

        return response()->json(['clientSecret' => $pi->client_secret]);
    }

    /** Legacy: mark paid flag via token */
    public function markPaid(Request $request, string $token)
    {
        $booking = Booking::where('portal_token', $token)->firstOrFail();

        if (Schema::hasColumn('bookings','last_payment_at')) {
            $booking->forceFill(['last_payment_at' => now()])->save();
        }

        return response()->json(['ok' => true]);
    }

    /* =========================================================================
     | ------------------------------ PUBLIC JSON ------------------------------
     | Backwards-compatible endpoint example for balance PI
     * ========================================================================= */

    public function createBalancePI(Request $request, Booking $booking)
    {
        $customer = $booking->customer;
        abort_unless($customer, 422, 'Customer not found.');

        [$amount, $currency] = $this->calcBalance($booking);
        abort_if($amount <= 0, 422, 'Nothing to pay.');

        $stripe = $this->stripe();
        $custId = $this->ensureStripeCustomer($customer);

        $payment = $this->firstOrCreatePayment(
            booking:  $booking,
            customer: $customer,
            purpose:  'booking_balance',
            amount:   $amount,
            currency: $currency,
            mechanism:'card'
        );

        $pi = $stripe->paymentIntents->create([
            'amount'                    => $amount,
            'currency'                  => strtolower($currency),
            'customer'                  => $custId,
            'automatic_payment_methods' => ['enabled' => true],
            'setup_future_usage'        => 'off_session',
            'description'               => "Booking balance {$booking->reference}",
            'metadata'                  => [
                'booking_id' => (string) $booking->id,
                'payment_id' => (string) $payment->id,
                'purpose'    => 'booking_balance',
            ],
        ]);

        if (Schema::hasColumn('payments','stripe_payment_intent_id')) {
            $payment->stripe_payment_intent_id = $pi->id;
        } elseif (Schema::hasColumn('payments','stripe_pi_id')) {
            $payment->stripe_pi_id = $pi->id;
        }
        $payment->save();

        return response()->json(['client_secret' => $pi->client_secret]);
    }

    /* =========================================================================
     | ------------------------------ TOKEN HELPERS ----------------------------
     * ========================================================================= */

    /** Create/get a token link for emailing */
    public function paymentLink(Booking $booking)
    {
        $token = $this->ensurePortalToken($booking);
        return response()->json([
            'url' => route('portal.pay.token', ['token' => $token]),
        ]);
    }

    protected function ensurePortalToken(Booking $booking): string
    {
        $tok = trim((string) ($booking->portal_token ?? ''));
        if ($tok !== '') return $tok;

        $tok = Str::random(40);
        $booking->forceFill(['portal_token' => $tok])->save();
        return $tok;
    }

    public function portalCompleteByToken(Request $request, string $token)
    {
        $booking = Booking::where('portal_token', $token)->firstOrFail();
        return $this->portalCompleteGeneric($request, $booking, 'booking_balance');
    }

    public function portalCreateHoldIntentByToken(Request $request, string $token)
    {
        $booking = Booking::with('customer')->where('portal_token', $token)->firstOrFail();
        return $this->portalCreateHoldIntent($request, $booking);
    }

    public function portalCompleteHoldByToken(Request $request, string $token)
    {
        $booking = Booking::where('portal_token', $token)->firstOrFail();
        return $this->portalCompleteHold($request, $booking);
    }

    /* =========================================================================
     | ------------------------------ CORE HELPERS -----------------------------
     * ========================================================================= */

    protected function assignPurposeAndType(Payment $payment, string $purpose, ?string $mechanism = null): void
    {
        if (Schema::hasColumn('payments','purpose')) $payment->purpose = $purpose;
        if ($mechanism && Schema::hasColumn('payments','mechanism')) $payment->mechanism = $mechanism;
    }

    protected function firstOrCreatePayment(
        Booking $booking,
        ?Customer $customer,
        string $purpose,
        int $amount,
        string $currency,
        ?string $mechanism = 'card'
    ): Payment {
        $attrs = ['booking_id' => $booking->id];
        if (Schema::hasColumn('payments','purpose')) $attrs['purpose'] = $purpose;

        $defaults = [
            'customer_id' => $customer?->id,
            'amount'      => $amount,
            'currency'    => strtoupper($currency),
            'status'      => 'pending',
        ];
        if (Schema::hasColumn('payments','mechanism')) $defaults['mechanism'] = $mechanism;

        $payment = Payment::firstOrCreate($attrs, $defaults);

        if ($mechanism && Schema::hasColumn('payments','mechanism') && $payment->mechanism !== $mechanism) {
            $payment->mechanism = $mechanism;
        }
        if ($payment->amount !== $amount || strtoupper($payment->currency) !== strtoupper($currency)) {
            $payment->amount   = $amount;
            $payment->currency = strtoupper($currency);
        }
        if (Schema::hasColumn('payments','purpose') && $payment->purpose !== $purpose) {
            $payment->purpose = $purpose;
        }

        $payment->save();
        return $payment;
    }

    protected function upsertPI(
        Payment $payment,
        int $amount,
        string $currency,
        string $customerId,
        string $description,
        array $metadata,
        string $idempotencyKey,
        bool $manualCapture = false
    ) {
        $stripe = $this->stripe();
        $currencyLower = strtolower($currency);

        if (!empty($payment->stripe_payment_intent_id)) {
            $pi = $stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);
            $update = [
                'amount'      => $amount,
                'currency'    => $currencyLower,
                'customer'    => $customerId,
                'description' => $description,
                'metadata'    => $metadata,
            ];
            if ($manualCapture) {
                $update['payment_method_types'] = ['card'];
                $update['capture_method']       = 'manual';
                unset($update['automatic_payment_methods']);
            } else {
                $update['automatic_payment_methods'] = ['enabled' => true];
                unset($update['payment_method_types'], $update['capture_method']);
            }
            return $stripe->paymentIntents->update($pi->id, $update);
        }

        $create = [
            'amount'      => $amount,
            'currency'    => $currencyLower,
            'customer'    => $customerId,
            'description' => $description,
            'metadata'    => $metadata,
            'setup_future_usage' => 'off_session',
        ];
        if ($manualCapture) {
            $create['payment_method_types'] = ['card'];
            $create['capture_method']       = 'manual';
        } else {
            $create['automatic_payment_methods'] = ['enabled' => true];
        }

        $pi = $stripe->paymentIntents->create($create, ['idempotency_key' => $idempotencyKey]);

        if (Schema::hasColumn('payments','stripe_payment_intent_id')) {
            $payment->stripe_payment_intent_id = $pi->id;
        }
        if (Schema::hasColumn('payments','status')) {
            $payment->status = 'pending';
        }
        $payment->save();

        return $pi;
    }

    protected function syncPaymentFromPI(Payment $payment, $pi, bool $savePm = false): void
    {
        $status = (string) $pi->status;
        $mapped = match ($status) {
            'requires_payment_method' => 'requires_payment_method',
            'requires_confirmation'   => 'requires_confirmation',
            'requires_action'         => 'requires_action',
            'processing'              => 'processing',
            'requires_capture'        => 'requires_capture',
            'canceled'                => 'canceled',
            'succeeded'               => 'succeeded',
            default                   => $status,
        };

        if (Schema::hasColumn('payments','status'))   $payment->status   = $mapped;
        if (Schema::hasColumn('payments','currency')) $payment->currency = strtoupper($pi->currency ?? $payment->currency);
        $payment->amount = (int) ($pi->amount_received ?? $pi->amount ?? $payment->amount);

        if ($savePm && Schema::hasColumn('payments','stripe_payment_method_id')) {
            if (!empty($pi->payment_method)) {
                $payment->stripe_payment_method_id = $pi->payment_method;
            } elseif (!empty($pi->latest_charge?->payment_method)) {
                $payment->stripe_payment_method_id = $pi->latest_charge->payment_method;
            }
        }

        $payment->save();
    }

    protected function calcDeposit(Booking $booking): array
    {
        $currency = strtoupper($booking->currency ?? 'NZD');
        $amount = (int) ($booking->deposit_amount ?? 0);
        if ($amount <= 0) {
            $total = (int) ($booking->total_amount ?? 0);
            $amount = (int) round($total * 0.5);
        }
        return [$amount, $currency];
    }

    protected function calcBalance(Booking $booking): array
    {
        $currency = strtoupper($booking->currency ?? 'NZD');
        $balance  = $this->computeBalanceCents($booking);
        return [$balance, $currency];
    }

    protected function portalCompleteGeneric(Request $request, Booking $booking, string $purpose)
    {
        $piId = $request->query('payment_intent') ?: $request->input('payment_intent');
        abort_if(!$piId, 400, 'Missing payment_intent');

        $stripe = $this->stripe();

        try {
            $pi = $stripe->paymentIntents->retrieve($piId);

            if ($pi->status === 'requires_confirmation') {
                $pi = $stripe->paymentIntents->confirm($pi->id);
            }
            if ($pi->status === 'requires_capture' && $purpose !== 'hold') {
                $pi = $stripe->paymentIntents->capture($pi->id);
            }
            if ($pi->status === 'requires_action') {
                return redirect()
                    ->route('portal.pay', ['booking' => $booking->id])
                    ->with('claim_error', 'Payment needs authentication. Please try again.');
            }
            if (!in_array($pi->status, ['succeeded','requires_capture','processing'], true)) {
                return redirect()
                    ->route('portal.pay', ['booking' => $booking->id])
                    ->with('claim_error', 'Payment not completed. Status: ' . $pi->status);
            }
        } catch (Throwable $e) {
            Log::error('[portalComplete] Stripe error', ['booking_id' => $booking->id, 'pi' => $piId, 'error' => $e->getMessage()]);
            return redirect()->route('portal.pay', ['booking' => $booking->id])
                ->with('claim_error', 'Payment could not be confirmed. Please try again.');
        }

        $this->maybeSetDefaultPm($pi);

        $payment = Schema::hasColumn('payments','stripe_payment_intent_id')
            ? Payment::firstOrNew(['stripe_payment_intent_id' => $pi->id])
            : new Payment();

        if (Schema::hasColumn('payments','booking_id'))  $payment->booking_id  = $booking->id;
        if (Schema::hasColumn('payments','customer_id')) $payment->customer_id = $booking->customer?->id;
        $this->assignPurposeAndType($payment, $purpose, $purpose === 'hold' ? 'hold' : 'card');

        $this->syncPaymentFromPI($payment, $pi, savePm: true);

        return redirect()->route('portal.pay', ['booking' => $booking->id])
            ->with('claim_ok', 'Payment received. Thank you!');
    }

    protected function maybeSetDefaultPm(object $pi): void
    {
        $pmId = null;
        if (!empty($pi->payment_method)) {
            $pmId = $pi->payment_method;
        } elseif (!empty($pi->latest_charge?->payment_method)) {
            $pmId = $pi->latest_charge->payment_method;
        }

        if (!empty($pi->customer) && $pmId) {
            try {
                $this->stripe()->customers->update($pi->customer, [
                    'invoice_settings' => ['default_payment_method' => $pmId],
                ]);
            } catch (Throwable $e) {
                Log::warning('Stripe: could not set default PM', ['err' => $e->getMessage()]);
            }
        }
    }

    /** Logged-in portal customer (if you expose a customer guard) */
    protected function portalCustomer(): Customer
    {
        if (Auth::guard('customer')->check()) {
            /** @var Customer $c */
            $c = Auth::guard('customer')->user();
            return $c;
        }
        $id = (int) request()->session()->get('portal_customer_id', 0);
        if ($id > 0) {
            /** @var Customer $c */
            $c = Customer::findOrFail($id);
            return $c;
        }
        abort(401, 'Please log in to continue.');
    }

    /** Ensure or create a Stripe Customer and return its ID (supports multiple column shapes) */
    protected function ensureStripeCustomer(Customer $customer): string
    {
        $existing = trim((string) ($customer->stripe_customer_id ?? $customer->stripe_id ?? ''));
        if ($existing !== '') return $existing;

        $sc = $this->stripe()->customers->create([
            'email'    => $customer->email,
            'name'     => trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: ($customer->company ?? $customer->name ?? null),
            'metadata' => ['customer_id' => (string) $customer->id],
        ]);

        $data = [];
        if (Schema::hasColumn($customer->getTable(), 'stripe_customer_id')) $data['stripe_customer_id'] = $sc->id;
        if (Schema::hasColumn($customer->getTable(), 'stripe_id'))         $data['stripe_id']         = $sc->id;
        if ($data) $customer->forceFill($data)->save();

        return $sc->id;
    }

    /** Pick a usable PaymentMethod id */
    protected function pickPaymentMethodId(string $stripeCustomerId, Booking $booking): ?string
    {
        $pmId = null;
        try {
            $cust = $this->stripe()->customers->retrieve($stripeCustomerId);
            $pmId = $cust->invoice_settings->default_payment_method ?? null;
        } catch (Throwable $e) { /* ignore */ }

        if (!$pmId) {
            $pmId = optional(
                $booking->payments()
                    ->whereNotNull('stripe_payment_method_id')
                    ->whereIn('status', ['succeeded','paid','captured','completed'])
                    ->latest('id')
                    ->first()
            )->stripe_payment_method_id;
        }

        return $pmId ?: null;
    }

    /* =========================================================================
     | ------------------------------ NEW HELPERS ------------------------------
     * ========================================================================= */

    private function ensurePortalIntents(Booking $booking): array
    {
        $stripe   = $this->stripe();
        $currency = strtolower($booking->currency ?? 'nzd');

        // Ensure Stripe customer
        $customer = $booking->customer;
        if (!$customer instanceof Customer) {
            throw ValidationException::withMessages(['customer' => 'No customer associated with this booking.']);
        }
        $stripeCustomerId = $this->ensureStripeCustomer($customer);

        $secrets = [
            'balance' => null,
            'bond'    => null,
        ];

        // BALANCE
        $balanceCents = $this->computeBalanceCents($booking);
        if ($balanceCents > 0) {
            $balanceCol = Schema::hasColumn('bookings', 'stripe_balance_pi_id') ? 'stripe_balance_pi_id' : null;

            $pi = null;
            if ($balanceCol && !empty($booking->{$balanceCol})) {
                try {
                    $existing = $stripe->paymentIntents->retrieve($booking->{$balanceCol});
                    $pi = $existing;

                    if ($pi->status === 'canceled') {
                        $pi = null;
                    } elseif ((int) $pi->amount !== (int) $balanceCents) {
                        $pi = $stripe->paymentIntents->update($pi->id, ['amount' => $balanceCents]);
                    }
                } catch (Throwable $e) {
                    $pi = null;
                }
            }

            if (!$pi) {
                $pi = $stripe->paymentIntents->create([
                    'amount'                    => $balanceCents,
                    'currency'                  => $currency,
                    'customer'                  => $stripeCustomerId,
                    'description'               => 'Booking balance: ' . ($booking->reference ?? $booking->id),
                    'metadata'                  => [
                        'booking_id' => (string) $booking->id,
                        'purpose'    => 'booking_balance',
                    ],
                    'automatic_payment_methods' => ['enabled' => true],
                    'setup_future_usage'        => 'off_session',
                ]);
                if ($balanceCol) {
                    $booking->forceFill([$balanceCol => $pi->id])->save();
                }
            }

            $pi = $stripe->paymentIntents->retrieve($pi->id);
            $secrets['balance'] = $pi->client_secret ?? null;
        }

        // BOND HOLD (manual capture)
        $holdCents = (int) ($booking->hold_amount ?? 0);
        $bondCol   = Schema::hasColumn('bookings', 'stripe_bond_pi_id') ? 'stripe_bond_pi_id' : null;
        $bondAlreadyFinalized = !empty($booking->bond_released_at) || !empty($booking->bond_captured_at);

        if ($holdCents > 0 && !$bondAlreadyFinalized) {
            $bondPi = null;

            if ($bondCol && !empty($booking->{$bondCol})) {
                try {
                    $existing = $stripe->paymentIntents->retrieve($booking->{$bondCol});
                    $bondPi = $existing;

                    if ($bondPi->status === 'canceled') {
                        $bondPi = null;
                    } elseif ((int) $bondPi->amount !== (int) $holdCents) {
                        $bondPi = $stripe->paymentIntents->update($bondPi->id, ['amount' => $holdCents]);
                    }
                } catch (Throwable $e) {
                    $bondPi = null;
                }
            }

            if (!$bondPi) {
                $bondPi = $stripe->paymentIntents->create([
                    'amount'                    => $holdCents,
                    'currency'                  => $currency,
                    'customer'                  => $stripeCustomerId,
                    'capture_method'            => 'manual',
                    'description'               => 'Bond hold: ' . ($booking->reference ?? $booking->id),
                    'metadata'                  => [
                        'booking_id' => (string) $booking->id,
                        'purpose'    => 'bond_hold',
                    ],
                    'automatic_payment_methods' => ['enabled' => true],
                ]);
                if ($bondCol) {
                    $booking->forceFill([$bondCol => $bondPi->id])->save();
                }
            }

            $bondPi = $stripe->paymentIntents->retrieve($bondPi->id);
            $secrets['bond'] = $bondPi->client_secret ?? null;
        }

        return $secrets;
    }

    private function computeBalanceCents(Booking $booking): int
    {
        if (isset($booking->balance_due) && $booking->balance_due !== null) {
            return max(0, (int) $booking->balance_due);
        }

        $total = (int) ($booking->total_amount ?? 0);

        $paid = (int) $booking->payments()
            ->whereIn('status', ['succeeded','paid','captured','completed'])
            ->where(fn($q) => $q->whereNull('purpose')->orWhere('purpose','!=','hold'))
            ->where(fn($q) => $q->whereNull('type')->orWhere('type','!=','hold'))
            ->sum('amount');

        return max(0, $total - $paid);
    }

    private function hasActiveHold(Booking $booking): bool
    {
        if (!empty($booking->bond_captured_at) || !empty($booking->bond_released_at)) {
            return false;
        }

        $q = $booking->payments()
            ->where(function ($q) {
                $q->where('purpose', 'hold')
                  ->orWhere('type', 'hold')
                  ->orWhere('mechanism', 'hold');
            })
            ->whereIn('status', ['requires_capture','processing','succeeded'])
            ->whereNotNull('stripe_payment_intent_id');

        return $q->exists();
    }

    /* =========================================================================
     | ------------------------------ ADMIN UI: Holds index (DEPOSITS table) ---
     * ========================================================================= */

    public function holdsIndex(Request $request): View
    {
        // Avoid 500s if migration hasn’t been run yet
        if (!Schema::hasTable('deposits')) {
            abort(503, 'The deposits table does not exist. Run the deposits migration.');
        }

        $validated = $request->validate([
            'q'       => ['nullable', 'string', 'max:200'],
            'status'  => ['nullable', 'in:authorized,captured,released,canceled,failed'],
            'perPage' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $perPage = (int) ($validated['perPage'] ?? 25);

        $q      = $validated['q'] ?? null;
        $status = $validated['status'] ?? null;

        $query = Deposit::query()
            ->with(['booking', 'customer'])
            ->orderByDesc('id');

        if ($status) {
            $query->where('status', $status);
        }

        if ($q) {
            // escape \, %, _
            $raw  = str_replace('\\', '\\\\', (string) $q);
            $like = '%' . str_replace(['%','_'], ['\%','\_'], $raw) . '%';

            $query->where(function ($w) use ($like, $q) {
                $w->where('stripe_payment_intent_id', 'like', $like)
                  ->orWhere('stripe_payment_intent', 'like', $like)
                  ->orWhere('stripe_payment_method_id', 'like', $like)
                  ->orWhere('stripe_payment_method', 'like', $like)
                  ->orWhere('last4', 'like', $like)
                  ->orWhere('currency', 'like', $like)
                  ->orWhere('failure_code', 'like', $like)
                  ->orWhere('failure_message', 'like', $like)
                  ->orWhereHas('booking', fn($b) => $b->where('id', (int) $q))
                  ->orWhereHas('customer', function ($c) use ($like) {
                      $c->where('email', 'like', $like)
                        ->orWhere('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like);
                  });
            });
        }

        $deposits = $query->paginate($perPage)->withQueryString();

        return view('admin.deposits.index', [
            'deposits' => $deposits,
            'filters'  => [
                'q'      => $q,
                'status' => $status,
                'perPage'=> $perPage,
            ],
        ]);
    }

    /* =========================================================================
     | ------------------------------ COMPAT SHIMS -----------------------------
     | Added so all existing routes have matching methods.
     * ========================================================================= */

    /** Create a SetupIntent to store a card for off-session use */
    public function createSetupIntent(Request $request, Booking $booking)
    {
        $customer = $booking->customer ?: $this->portalCustomer();
        $stripeCustomerId = $this->ensureStripeCustomer($customer);

        $si = $this->stripe()->setupIntents->create([
            'customer' => $stripeCustomerId,
            'usage'    => 'off_session',
            'payment_method_types' => ['card'],
        ]);

        return response()->json([
            'ok'           => true,
            'clientSecret' => $si->client_secret,
            'setupIntent'  => $si->id,
        ]);
    }

    /**
     * Finalize checkout (POST) – accepts payment_intent in body and runs the same
     * completion logic as the GET /complete route.
     */
    public function finalizeCheckout(Request $request, Booking $booking)
    {
        // Delegate to the generic path with purpose "booking_balance"
        return $this->portalCompleteGeneric($request, $booking, 'booking_balance');
    }
}
