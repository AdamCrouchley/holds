<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use App\Mail\PaymentReceipt;
use App\Notifications\DepositReceipt;
use Stripe\Webhook as StripeWebhook;

/**
 * Unified Stripe webhook controller.
 *
 * - Verifies signature using services.stripe.webhook_secret
 * - Updates Payment / Deposit models based on event types
 * - Saves default payment method to Customer
 * - Sends notifications / receipts
 */
class WebhookController extends Controller
{
    /**
     * Stripe webhook entrypoint.
     */
    public function handle(Request $request)
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret    = (string) config('services.stripe.webhook_secret', '');

        try {
            $event = StripeWebhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\Throwable $e) {
            Log::warning('[stripe] invalid webhook: ' . $e->getMessage());
            return response('Invalid signature', 400);
        }

        try {
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->onPaymentIntentSucceeded($event->data->object);
                    break;

                case 'payment_intent.payment_failed':
                    $this->onPaymentIntentFailed($event->data->object);
                    break;

                case 'payment_intent.canceled':
                    $this->onPaymentIntentCanceled($event->data->object);
                    break;

                // For manual-capture flows (holds → captured later)
                case 'charge.captured':
                    $this->onChargeCaptured($event->data->object);
                    break;

                case 'setup_intent.succeeded':
                    $this->onSetupIntentSucceeded($event->data->object);
                    break;

                default:
                    // Ignore other event types for now.
                    Log::info('[stripe] webhook ignored type: ' . $event->type);
                    break;
            }
        } catch (\Throwable $e) {
            // Log and still return 200 so Stripe doesn't endlessly retry due to our bug.
            Log::error('[stripe] webhook handler error: ' . $e->getMessage(), [
                'type' => $event->type ?? 'unknown',
            ]);
        }

        return response('OK', 200);
    }

    /* ==============================
     * Handlers
     * ============================== */

    /**
     * Handle successful PaymentIntent events.
     *
     * @param  \Stripe\PaymentIntent  $pi
     */
    protected function onPaymentIntentSucceeded($pi): void
    {
        $piId = (string) ($pi->id ?? '');
        $type = (string) ($pi->metadata->type ?? '');
        $latestChargeId = is_object($pi->latest_charge) ? ($pi->latest_charge->id ?? null) : ($pi->latest_charge ?? null);

        // 1) Mark any matching Payment record as succeeded
        if ($piId !== '') {
            Payment::where('stripe_payment_intent_id', $piId)->update([
                'status'           => 'succeeded',
                'stripe_charge_id' => $latestChargeId,
            ]);
        }

        // 2) Persist successful PM to our Customer for future off-session use
        $this->saveDefaultPaymentMethodFromPI($pi);

        // 3) If this PI was a HOLD (manual capture), ensure a Deposit record is "authorised"
        if ($type === 'deposit_hold') {
            $bookingId = (int) ($pi->metadata->booking_id ?? 0);
            $booking   = $bookingId ? Booking::find($bookingId) : null;

            if ($booking) {
                Deposit::firstOrCreate(
                    ['stripe_payment_intent_id' => $piId],
                    [
                        'booking_id'    => $booking->id,
                        'customer_id'   => $booking->customer_id,
                        'amount'        => (int) $pi->amount, // cents
                        'currency'      => strtoupper((string) ($pi->currency ?? $booking->currency ?? 'NZD')),
                        'status'        => 'authorised',
                        'authorised_at' => now(),
                        // Stripe holds typically expire ~7 days (card/region dependent) — track nominal expiry
                        'expires_at'    => now()->addDays(7),
                    ]
                );
            }
        }

        // 4) If this PI was a BOOKING DEPOSIT, send the deposit receipt notification
        if ($type === 'booking_deposit') {
            $payment = Payment::where('stripe_payment_intent_id', $piId)->first();
            if ($payment) {
                $booking = Booking::find($payment->booking_id);
                if ($booking && $booking->customer && $booking->customer->email) {
                    Notification::route('mail', $booking->customer->email)
                        ->notify(new DepositReceipt($booking, $payment));
                }
            }
        }

        // 5) General receipt & bookkeeping for any successful PI tied to a booking
        //    (merges the add-on snippet: update last_payment_at and email PaymentReceipt)
        $bookingIdMeta = $pi->metadata['booking_id'] ?? ($pi->metadata->booking_id ?? null);

        /** @var Booking|null $b */
        $b = $bookingIdMeta ? Booking::with('customer')->find((int) $bookingIdMeta) : null;
        if (!$b && isset($payment) && $payment?->booking_id) {
            $b = Booking::with('customer')->find($payment->booking_id);
        }

        if ($b) {
            // amount_received is present for succeeded intents; fallback to amount if needed
            $amountReceived = (int) ($pi->amount_received ?? $pi->amount ?? 0);
            if ($amountReceived > 0) {
                $b->forceFill(['last_payment_at' => now()])->save();

                if ($b->customer?->email) {
                    try {
                        Mail::to($b->customer->email)->send(new PaymentReceipt($b, $amountReceived));
                    } catch (\Throwable $mailErr) {
                        Log::warning('[stripe] receipt mail failed: ' . $mailErr->getMessage(), [
                            'booking' => $b->id,
                            'email'   => $b->customer->email,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Handle failed PaymentIntent events.
     *
     * @param  \Stripe\PaymentIntent  $pi
     */
    protected function onPaymentIntentFailed($pi): void
    {
        $piId = (string) ($pi->id ?? '');
        if ($piId === '') return;

        Payment::where('stripe_payment_intent_id', $piId)
            ->update(['status' => 'failed']);

        // If this was a hold that failed, no Deposit is created.
    }

    /**
     * Handle canceled PaymentIntent events.
     *
     * @param  \Stripe\PaymentIntent  $pi
     */
    protected function onPaymentIntentCanceled($pi): void
    {
        $piId = (string) ($pi->id ?? '');
        if ($piId === '') return;

        $type = (string) ($pi->metadata->type ?? '');

        // Deposits: a manual-capture PI canceled == hold voided
        if ($type === 'deposit_hold') {
            Deposit::where('stripe_payment_intent_id', $piId)
                ->update(['status' => 'voided']);
        }

        Payment::where('stripe_payment_intent_id', $piId)
            ->update(['status' => 'canceled']);
    }

    /**
     * Handle charge captured events (for manual-capture deposits).
     *
     * @param  \Stripe\Charge  $charge
     */
    protected function onChargeCaptured($charge): void
    {
        // When you capture a manual-capture PI, Stripe emits charge.captured.
        $piId = (string) ($charge->payment_intent ?? '');
        if ($piId === '') return;

        Deposit::where('stripe_payment_intent_id', $piId)->update([
            'status' => 'captured',
        ]);

        Payment::where('stripe_payment_intent_id', $piId)->update([
            'stripe_charge_id' => (string) ($charge->id ?? ''),
            'status'           => 'succeeded',
        ]);
    }

    /**
     * Handle successful SetupIntent (e.g., customer updated card).
     *
     * @param  \Stripe\SetupIntent  $si
     */
    protected function onSetupIntentSucceeded($si): void
    {
        $stripeCustomerId = (string) ($si->customer ?? '');
        $pmId             = (string) ($si->payment_method ?? '');

        if ($stripeCustomerId === '' || $pmId === '') return;

        $customer = Customer::where('stripe_customer_id', $stripeCustomerId)->first();
        if ($customer) {
            $customer->update(['default_payment_method_id' => $pmId]);
        }
    }

    /* ==============================
     * Helpers
     * ============================== */

    /**
     * Save the successful payment method id from a PI to our Customer record,
     * so we can reuse it for balances/holds off-session.
     *
     * @param  \Stripe\PaymentIntent  $pi
     */
    protected function saveDefaultPaymentMethodFromPI($pi): void
    {
        $pmId             = (string) ($pi->payment_method ?? '');
        $stripeCustomerId = (string) ($pi->customer ?? '');

        if ($pmId === '') {
            return;
        }

        // Preferred lookup: by Stripe customer id
        if ($stripeCustomerId !== '') {
            $customer = Customer::where('stripe_customer_id', $stripeCustomerId)->first();
            if ($customer) {
                $customer->update(['default_payment_method_id' => $pmId]);
                return;
            }
        }

        // Fallback: if we only have booking_id metadata, find via booking
        $bookingId = (int) ($pi->metadata->booking_id ?? 0);
        if ($bookingId) {
            $booking = Booking::with('customer')->find($bookingId);
            if ($booking && $booking->customer) {
                $booking->customer->update(['default_payment_method_id' => $pmId]);
            }
        }
    }
}
