<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class DepositController extends Controller
{
    /**
     * Authorise a HOLD (manual capture PaymentIntent).
     * POST: amount (cents), payment_method
     */
    public function authorise(Request $request, Booking $booking)
    {
        $customer = $booking->customer;
        abort_unless($customer, 422, 'Customer not found for booking.');

        $amount = (int) $request->integer('amount', (int) ($booking->deposit_amount ?? 0));
        abort_if($amount <= 0, 422, 'Invalid hold amount.');

        $paymentMethodId = $request->input('payment_method')
            ?: ($customer->default_payment_method_id ?? null);
        abort_unless($paymentMethodId, 422, 'No payment method available.');

        Stripe::setApiKey(config('services.stripe.secret'));

        $idempotencyKey = 'hold_'.$booking->id.'_'.$amount;

        try {
            $params = [
                'amount'         => $amount,
                'currency'       => 'nzd',
                'capture_method' => 'manual',
                'customer'       => $customer->stripe_customer_id,
                'payment_method' => $paymentMethodId,
                'confirm'        => true,
                'off_session'    => true,
                'metadata'       => [
                    'booking_id' => (string) $booking->id,
                    'type'       => 'deposit_hold',
                ],
            ];

            $pi = PaymentIntent::create($params, ['idempotency_key' => $idempotencyKey]);

            // Create local deposit record
            $deposit = Deposit::firstOrCreate(
                ['stripe_payment_intent_id' => $pi->id],
                [
                    'booking_id'  => $booking->id,
                    'customer_id' => $customer->id,
                    'amount'      => $amount,
                    'currency'    => 'NZD',
                    'status'      => 'authorised', // allowed enum
                ]
            );

            Log::info('[holds] hold authorised', [
                'booking' => $booking->id,
                'deposit' => $deposit->id,
                'pi'      => $pi->id,
                'amount'  => $amount,
            ]);

            return response()->json([
                'status'   => 'authorised',
                'deposit'  => $deposit->id,
                'pi'       => $pi->id,
                'amount'   => $amount,
            ]);
        } catch (\Throwable $e) {
            Log::error('[holds] authorise error: '.$e->getMessage(), [
                'booking' => $booking->id,
                'amount'  => $amount,
                'key'     => $idempotencyKey,
            ]);

            return response()->json([
                'error' => 'Could not authorise hold: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Capture a previously authorised HOLD.
     * POST: amount (cents) optional, defaults to full.
     */
    public function capture(Request $request, Deposit $deposit)
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $piId = $deposit->stripe_payment_intent_id;
        abort_unless($piId, 422, 'Deposit missing PaymentIntent.');

        $amount = (int) $request->integer('amount', (int) $deposit->amount);
        abort_if($amount <= 0, 422, 'Invalid capture amount.');

        $idempotencyKey = 'capture_'.$deposit->id.'_'.$amount;

        try {
            $params = [];
            if ($amount > 0 && $amount !== (int) $deposit->amount) {
                $params['amount_to_capture'] = $amount;
            }

            $pi = PaymentIntent::capture($piId, $params, ['idempotency_key' => $idempotencyKey]);

            $deposit->update(['status' => 'captured']);

            Log::info('[holds] captured', [
                'deposit' => $deposit->id,
                'pi'      => $piId,
                'amount'  => $amount,
            ]);

            return response()->json([
                'status'  => 'captured',
                'deposit' => $deposit->id,
                'pi'      => $piId,
                'amount'  => $amount,
            ]);
        } catch (\Throwable $e) {
            Log::error('[holds] capture error: '.$e->getMessage(), [
                'deposit' => $deposit->id,
                'pi'      => $piId,
                'key'     => $idempotencyKey,
            ]);

            return response()->json([
                'error' => 'Could not capture hold: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Void (cancel) a previously authorised HOLD.
     */
    public function void(Deposit $deposit)
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $piId = $deposit->stripe_payment_intent_id;
        abort_unless($piId, 422, 'Deposit missing PaymentIntent.');

        $idempotencyKey = 'void_'.$deposit->id;

        try {
            $pi = PaymentIntent::cancel($piId, [], ['idempotency_key' => $idempotencyKey]);

            $deposit->update(['status' => 'voided']);

            Log::info('[holds] voided', [
                'deposit' => $deposit->id,
                'pi'      => $piId,
            ]);

            return response()->json([
                'status'  => 'voided',
                'deposit' => $deposit->id,
                'pi'      => $piId,
            ]);
        } catch (\Throwable $e) {
            Log::error('[holds] void error: '.$e->getMessage(), [
                'deposit' => $deposit->id,
                'pi'      => $piId,
                'key'     => $idempotencyKey,
            ]);

            return response()->json([
                'error' => 'Could not void hold: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Capture a previously authorised BOND (security hold) PaymentIntent
     * stored directly on the Booking (stripe_bond_pi_id).
     *
     * POST: amount_cents (optional, defaults to booking->hold_amount)
     *
     * NOTE: This is separate from the Deposit model flow above — it operates
     * on a PaymentIntent id saved on the booking itself.
     */
    public function captureBond(Booking $booking, Request $request)
    {
        $amount = (int) $request->integer('amount_cents', (int) ($booking->hold_amount ?? 0));
        abort_unless($booking->stripe_bond_pi_id && $amount > 0, 400, 'Missing bond intent or invalid amount.');

        $stripe = new StripeClient(config('services.stripe.secret'));

        try {
            $stripe->paymentIntents->capture($booking->stripe_bond_pi_id, [
                'amount_to_capture' => $amount,
            ]);

            $booking->forceFill(['bond_captured_at' => now()])->save();

            Log::info('[holds] bond captured', [
                'booking' => $booking->id,
                'pi'      => $booking->stripe_bond_pi_id,
                'amount'  => $amount,
            ]);

            return back()->with('status', 'Bond captured');
        } catch (\Throwable $e) {
            Log::error('[holds] bond capture error: '.$e->getMessage(), [
                'booking' => $booking->id,
                'pi'      => $booking->stripe_bond_pi_id,
            ]);

            return back()->withErrors('Could not capture bond: '.$e->getMessage());
        }
    }
}
