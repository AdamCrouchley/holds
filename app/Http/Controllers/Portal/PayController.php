<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Job;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Stripe\StripeClient;
use Throwable;

class PayController extends Controller
{
    /** Stripe SDK client */
    protected function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    /** Currency priority: Job -> Flow -> NZD */
    protected function currencyFor(Job $job): string
    {
        return strtolower($job->currency ?? optional($job->flow)->currency ?? 'NZD');
    }

    /** Compute remaining balance (authoritative, server-side) */
    protected function remainingCents(Job $job): int
    {
        $total = (int) ($job->charge_amount ?? 0);
        $paid  = (int) ($job->paid_amount_cents ?? 0);

        // Fallback to payments() if you have that relation and no cached paid_amount_cents
        if ($paid === 0 && method_exists($job, 'payments')) {
            try {
                $paid = (int) $job->payments()
                    ->whereIn('status', ['succeeded', 'captured'])
                    ->sum('amount_cents');
            } catch (Throwable $e) {
                // ignore
            }
        }

        return max(0, $total - $paid);
    }

    /** Flow-driven hold amount in cents (column: hold_amount_cents) */
    protected function flowHoldCents(Job $job): int
    {
        return (int) (optional($job->flow)->hold_amount_cents ?? 0);
    }

    /**
     * GET /p/job/{job}/pay
     * Render the payment page.
     * If you want to force signed URLs only, uncomment the signature check.
     */
    public function show(Request $request, Job $job)
    {
        // Uncomment if this route is signed and you want to enforce it
        // if (!$request->hasValidSignature()) abort(401);

        return view('portal.pay', ['job' => $job]);
    }

    /**
     * GET shareable signed URL (used by "Copy secure payment link" in the Blade).
     */
    public function url(Job $job)
    {
        $url = URL::signedRoute('portal.pay.show.job', ['job' => $job->id]);
        return response()->json(['url' => $url]);
    }

    /**
     * POST /p/{type}/{id}/bundle
     *
     * Create a "bundle" of two PaymentIntents:
     *  - Charge PI for the remaining balance (automatic capture)
     *  - Hold  PI for the security deposit (manual capture)
     *
     * Both are returned to the client to confirm with the SAME card in one flow.
     */
    public function bundle(Request $request, string $type, int $id)
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
            // Create the charge PI (balance) – not confirmed here
            $chargePI = null;
            if ($remainingCents > 0) {
                $chargePI = $stripe->paymentIntents->create([
                    'amount'               => $remainingCents,
                    'currency'             => $currency,
                    'confirmation_method'  => 'automatic',
                    'capture_method'       => 'automatic',
                    'metadata'             => [
                        'type'     => 'balance',
                        'job_id'   => (string) $job->id,
                        'external' => (string) ($job->external_reference ?? ''),
                    ],
                    // Optional: keep card for later legitimate off-session use
                    'setup_future_usage'   => 'off_session',
                ], [
                    // idempotency per job+operation
                    'idempotency_key' => "bundle_charge_job_{$job->id}_" . uniqid('', true),
                ]);
            }

            // Create the hold PI – manual capture (authorization)
            $holdPI = null;
            if ($flowHold > 0) {
                $holdPI = $stripe->paymentIntents->create([
                    'amount'               => $flowHold,
                    'currency'             => $currency,
                    'confirmation_method'  => 'automatic',
                    'capture_method'       => 'manual', // <-- makes it a hold
                    'metadata'             => [
                        'type'     => 'hold',
                        'job_id'   => (string) $job->id,
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
                'ok'                    => true,
                'charge_client_secret'  => $chargePI?->client_secret,
                'hold_client_secret'    => $holdPI?->client_secret,
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
}
