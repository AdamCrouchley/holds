<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Stripe\StripeClient;
use Throwable;

class PayController extends Controller
{
    /* =========================================================================
     | Stripe
     * ========================================================================= */
    /** Stripe SDK client */
    protected function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    /* =========================================================================
     | Helpers
     * ========================================================================= */
    /** Currency priority: Job -> Flow -> NZD */
    protected function currencyFor(Job $job): string
    {
        return strtolower($job->currency ?? optional($job->flow)->currency ?? 'NZD');
    }

    /** Compute remaining balance (authoritative, server-side) */
    protected function remainingCents(Job $job): int
    {
        $total = (int) ($job->charge_amount ?? $job->amount_due_cents ?? 0);
        $paid  = (int) ($job->paid_amount_cents ?? $job->paid_cents ?? 0);

        // Fallback to payments() if relation exists and no cached total found
        if ($paid === 0 && method_exists($job, 'payments')) {
            try {
                $paid = (int) $job->payments()
                    ->whereIn('status', ['succeeded', 'captured'])
                    ->sum('amount_cents');
            } catch (Throwable $e) {
                Log::warning('Failed summing payments()', ['job_id' => $job->id, 'err' => $e->getMessage()]);
            }
        }

        return max(0, $total - $paid);
    }

    /** Flow-driven hold amount in cents (column: hold_amount_cents) */
    protected function flowHoldCents(Job $job): int
    {
        return (int) (optional($job->flow)->hold_amount_cents ?? 0);
    }

    /* =========================================================================
     | Views / Links
     * ========================================================================= */

    /**
     * GET /p/job/{job}/pay
     * Render the payment page.
     * If you require signed URLs, uncomment the signature check below.
     */
    public function show(Request $request, Job $job)
    {
        // if (! $request->hasValidSignature()) abort(401);
        return view('portal.pay', ['job' => $job]);
    }

    /**
     * GET shareable signed URL (used by "Copy secure payment link" in the Blade).
     */
    public function url(Job $job): JsonResponse
    {
        $url = URL::signedRoute('portal.pay.show.job', ['job' => $job->getKey()]);
        return response()->json(['url' => $url]);
    }

    /* =========================================================================
     | Bundle: create charge + hold PaymentIntents
     * ========================================================================= */

    /**
     * POST /p/{type}/{id}/bundle
     *
     * Create a "bundle" of two PaymentIntents:
     *  - Charge PI for the remaining balance (automatic capture)
     *  - Hold  PI for the security deposit (manual capture)
     *
     * Both client_secrets are returned for the client to confirm in one flow.
     */
    public function bundle(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless($type === 'job', 404);

        /** @var Job $job */
        $job = Job::findOrFail($id);

        $currency       = $this->currencyFor($job);
        $remainingCents = $this->remainingCents($job);
        $flowHold       = $this->flowHoldCents($job);

        abort_if($remainingCents <= 0 && $flowHold <= 0, 422, 'Nothing to charge or hold.');

        $stripe = $this->stripe();

        try {
            $chargePI = null;
            if ($remainingCents > 0) {
                $chargePI = $stripe->paymentIntents->create([
                    'amount'              => $remainingCents,
                    'currency'            => $currency,
                    'confirmation_method' => 'automatic',
                    'capture_method'      => 'automatic',
                    'metadata'            => [
                        'type'     => 'balance',
                        'job_id'   => (string) $job->getKey(),
                        'external' => (string) ($job->external_reference ?? ''),
                    ],
                    // Keep card for legitimate future off-session usage if needed
                    'setup_future_usage'  => 'off_session',
                ], [
                    'idempotency_key' => "bundle_charge_job_{$job->id}_" . uniqid('', true),
                ]);
            }

            $holdPI = null;
            if ($flowHold > 0) {
                $holdPI = $stripe->paymentIntents->create([
                    'amount'                 => $flowHold,
                    'currency'               => $currency,
                    'confirmation_method'    => 'automatic',
                    'capture_method'         => 'manual', // authorization only (hold)
                    'metadata'               => [
                        'type'     => 'hold',
                        'job_id'   => (string) $job->getKey(),
                        'external' => (string) ($job->external_reference ?? ''),
                    ],
                    'payment_method_options' => [
                        'card' => ['request_three_d_secure' => 'automatic'],
                    ],
                ], [
                    'idempotency_key' => "bundle_hold_job_{$job->id}_" . uniqid('', true),
                ]);
            }

            return response()->json([
                'ok'                   => true,
                'charge_client_secret' => $chargePI?->client_secret,
                'hold_client_secret'   => $holdPI?->client_secret,
            ]);
        } catch (Throwable $e) {
            Log::error('Bundle create failed', [
                'job_id' => $job->id,
                'err'    => $e->getMessage(),
            ]);

            return response()->json([
                'ok'      => false,
                'message' => 'Could not prepare payment bundle.',
            ], 422);
        }
    }

    /* =========================================================================
     | Record "paid" after client confirmation
     * ========================================================================= */

    /**
     * POST /p/job/{job}/paid
     * Records a succeeded PaymentIntent against the Job and stores a Payment row.
     */
    public function recordPaid(Request $request, Job $job)
    {
        $piId = (string) $request->input('payment_intent');
        abort_unless($piId, 422, 'payment_intent is required');

        $stripe = $this->stripe();

        try {
            $pi = $stripe->paymentIntents->retrieve($piId, []);
        } catch (Throwable $e) {
            Log::warning('Stripe PI retrieve failed', ['job_id' => $job->id, 'pi' => $piId, 'err' => $e->getMessage()]);
            abort(422, 'Could not retrieve PaymentIntent.');
        }

        abort_unless($pi && $pi->status === 'succeeded', 422, 'PaymentIntent not succeeded');

        // Optional safety: ensure the PI belongs to this job
        if (isset($pi->metadata['job_id']) && (string) $pi->metadata['job_id'] !== (string) $job->getKey()) {
            abort(422, 'Payment belongs to a different job.');
        }

        $chargeId = $pi->latest_charge ?? null;
        $charge   = null;

        if ($chargeId) {
            try {
                $charge = $stripe->charges->retrieve($chargeId, []);
            } catch (Throwable $e) {
                Log::warning('Stripe charge retrieve failed', ['job_id' => $job->id, 'charge_id' => $chargeId, 'err' => $e->getMessage()]);
            }
        }

        // Upsert a Payment row so it appears in Filament / admin
        $payment = Payment::firstOrCreate(
            ['provider' => 'stripe', 'provider_id' => (string) $pi->id],
            [
                'job_id'       => $job->getKey(),
                'amount_cents' => (int) ($pi->amount_received ?? $pi->amount ?? 0),
                'currency'     => strtoupper($pi->currency ?? 'nzd'),
                'status'       => 'succeeded',
                'method'       => $charge->payment_method_details->type
                    ?? ($pi->payment_method_types[0] ?? null),
                'reference'    => $chargeId ?: (string) $pi->id,
                'captured_at'  => now(),
            ]
        );

        // Update Job totals if those columns exist
        try {
            if (isset($job->paid_cents)) {
                $job->paid_cents = (int) ($job->paid_cents ?? 0) + (int) ($pi->amount_received ?? 0);
            }
            if (isset($job->amount_due_cents)) {
                $job->amount_due_cents = max(0, (int) $job->amount_due_cents - (int) ($pi->amount_received ?? 0));
            }
            if (method_exists($job, 'save')) {
                $job->save();
            }
        } catch (Throwable $e) {
            Log::warning('Job totals update failed after payment', ['job_id' => $job->id, 'err' => $e->getMessage()]);
        }

        // Optional: email a receipt (uses your existing Mailable)
        try {
            if ($job->customer_email ?? null) {
                Mail::to($job->customer_email)->send(new \App\Mail\PaymentReceiptMail($job, $payment, $job->brand ?? null));
            }
        } catch (Throwable $e) {
            Log::warning('Payment receipt mail failed', ['job_id' => $job->id, 'err' => $e->getMessage()]);
        }

        return response()->noContent();
    }
}
