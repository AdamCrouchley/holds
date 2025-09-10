<?php

namespace App\Http\Controllers;

use App\Models\Job;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Stripe\Stripe;
use Stripe\Webhook as StripeWebhook;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = (string) config('services.stripe.webhook_secret');
        if ($secret === '') {
            // If you prefer, abort(500) here; logging keeps prod resilient.
            Log::warning('Stripe webhook secret missing. Skipping signature verification.');
        }

        // --- Verify signature (when secret present) ---
        try {
            $payload = $request->getContent();
            $sig     = $request->header('Stripe-Signature');

            if ($secret) {
                $event = StripeWebhook::constructEvent($payload, $sig, $secret);
            } else {
                // Fallback for local/dev without signing
                $event = json_decode($payload, false, 512, JSON_THROW_ON_ERROR);
            }
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook signature verification failed', ['err' => $e->getMessage()]);
            return response()->json(['ok' => false], 400);
        }

        $type = $event->type ?? $event->type ?? null; // supports both object/array-ish
        $obj  = $event->data->object ?? null;

        try {
            return $this->handleEvent($type, $obj);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook handler error', ['type' => $type, 'err' => $e->getMessage()]);
            return response()->json(['ok' => false], 500);
        }
    }

    protected function handleEvent(?string $type, $object)
    {
        switch ($type) {
            // ---- Main success path for card payments (including APM that map to PIs) ----
            case 'payment_intent.succeeded':
                $this->upsertPaymentFromIntent($object, 'succeeded');
                break;

            // ---- We also track when an auth/hold becomes capturable (manual capture flow) ----
            case 'payment_intent.amount_capturable_updated':
                // This is when a manual-capture PI reached `requires_capture` (authorized).
                $this->upsertPaymentFromIntent($object, 'authorized');
                break;

            // ---- After capture, Stripe emits charge.succeeded with captured=true ----
            case 'charge.succeeded':
                $this->markChargeCaptured($object);
                break;

            // ---- Refunds / partial refunds ----
            case 'charge.refunded':
                $this->markRefunded($object);
                break;

            // ---- Failure bookkeeping (optional) ----
            case 'payment_intent.payment_failed':
                $this->upsertPaymentFromIntent($object, 'failed');
                break;

            default:
                // ignore noisy events
                break;
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Create or update our Payment row from a PaymentIntent.
     * Also updates the Job’s paid_amount_cents cache if the column exists.
     */
    protected function upsertPaymentFromIntent($pi, string $status)
    {
        // Determine amounts & job id
        $amount   = (int) ($pi->amount_received ?? $pi->amount ?? 0);
        $currency = strtolower((string) ($pi->currency ?? 'nzd'));
        $jobId    = (int) ($pi->metadata->job_id ?? 0);

        // Determine type by capture method
        $type = ($pi->capture_method ?? null) === 'manual'
            ? ($status === 'authorized' ? 'hold' : 'balance')
            : 'balance';

        // Build base attributes
        $attrs = [
            'job_id'       => $jobId ?: null,
            'amount_cents' => $amount,
            'currency'     => $currency,
            'status'       => $status, // 'succeeded' | 'authorized' | 'failed'
            'type'         => $type,   // 'balance' | 'hold'
            'provider'     => 'stripe',
            'provider_id'  => (string) $pi->id, // we key by PaymentIntent ID in our app
            'reference'    => optional($pi->charges->data[0] ?? null)->id,
            'notes'        => 'Recorded via webhook '.$status,
        ];

        $this->persistPayment($attrs);

        if ($jobId) {
            $this->refreshJobPaidTotals($jobId);
        }
    }

    /**
     * Mark a charge as captured/succeeded. Useful for manual-capture holds.
     * We link back to the PI payment row and flip status to 'captured'.
     */
    protected function markChargeCaptured($charge)
    {
        // Only adjust if this is a capture (either direct capture or auth+capture)
        $captured = (bool) ($charge->captured ?? false);
        if (!$captured) return;

        $piId = (string) ($charge->payment_intent ?? '');
        if ($piId === '') return;

        /** @var \App\Models\Payment|null $payment */
        $payment = Payment::where('provider', 'stripe')->where('provider_id', $piId)->first();
        if (!$payment) {
            // create if missing (idempotent)
            $attrs = [
                'job_id'       => $this->jobIdFromCharge($charge),
                'amount_cents' => (int) ($charge->amount_captured ?? $charge->amount ?? 0),
                'currency'     => strtolower((string) $charge->currency),
                'status'       => 'captured',
                'type'         => 'hold',
                'provider'     => 'stripe',
                'provider_id'  => $piId,
                'reference'    => (string) $charge->id,
                'notes'        => 'Recorded via webhook charge.succeeded',
            ];
            $payment = $this->persistPayment($attrs);
        } else {
            $payment->status       = 'captured';
            $payment->amount_cents = (int) ($charge->amount_captured ?? $charge->amount ?? $payment->amount_cents);
            $payment->reference    = (string) $charge->id;
            $payment->save();
        }

        if ($payment && $payment->job_id) {
            $this->refreshJobPaidTotals($payment->job_id);
        }
    }

    protected function markRefunded($charge)
    {
        $piId = (string) ($charge->payment_intent ?? '');
        if ($piId === '') return;

        $payment = Payment::where('provider', 'stripe')->where('provider_id', $piId)->first();
        if ($payment) {
            $payment->status = 'refunded';
            $payment->save();

            if ($payment->job_id) {
                $this->refreshJobPaidTotals($payment->job_id);
            }
        }
    }

    protected function persistPayment(array $candidate): Payment
    {
        // Keep only columns that exist in your DB schema (defensive)
        $cols = Schema::getColumnListing('payments');
        $data = Arr::only($candidate, $cols);

        $lookup = Schema::hasColumn('payments', 'provider')
            ? ['provider' => 'stripe', 'provider_id' => $candidate['provider_id']]
            : ['provider_id' => $candidate['provider_id']];

        return Payment::updateOrCreate($lookup, $data);
    }

    protected function refreshJobPaidTotals(int $jobId): void
    {
        /** @var Job|null $job */
        $job = Job::find($jobId);
        if (!$job) return;

        // Sum successful money that actually reduces balance
        $sum = $job->payments()
            ->whereIn('status', ['succeeded', 'captured'])
            ->sum('amount_cents');

        if (Schema::hasColumn('jobs', 'paid_amount_cents')) {
            $job->paid_amount_cents = (int) $sum;
            $job->save();
        }
    }

    protected function jobIdFromCharge($charge): ?int
    {
        // try metadata on charge, else on PI (best-effort)
        if (!empty($charge->metadata->job_id)) {
            return (int) $charge->metadata->job_id;
        }
        return null;
    }
}
