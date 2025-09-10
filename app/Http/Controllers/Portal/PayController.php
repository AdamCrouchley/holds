<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Mail\PaymentReceiptMail;
use App\Models\Deposit;
use App\Models\Job;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Stripe\StripeClient;
use Throwable;

class PayController extends Controller
{
    /* =========================================================================
     | Stripe
     * ========================================================================= */

    protected function stripe(): StripeClient
    {
        return new StripeClient(config('services.stripe.secret'));
    }

    /* =========================================================================
     | Helpers
     * ========================================================================= */

    /** NZD fallback; lowercase for Stripe */
    protected function currencyFor(Job $job): string
    {
        $cur = $job->currency ?? optional($job->flow)->currency ?? 'NZD';
        return strtolower($cur);
    }

    /** Remaining (authoritative, server-side) */
    protected function remainingCents(Job $job): int
    {
        $total = (int) ($job->charge_amount_cents ?? $job->amount_due_cents ?? $job->charge_amount ?? 0);
        $paid  = (int) ($job->amount_paid_cents  ?? $job->paid_amount_cents  ?? $job->paid_cents   ?? 0);

        if ($paid === 0 && method_exists($job, 'payments')) {
            try {
                $paid = (int) $job->payments()
                    ->whereIn('status', ['succeeded', 'captured', 'authorized'])
                    ->sum('amount_cents');
            } catch (Throwable $e) {
                Log::warning('remainingCents: sum payments failed', [
                    'job_id' => $job->id ?? null,
                    'err'    => $e->getMessage(),
                ]);
            }
        }

        return max(0, $total - $paid);
    }

    protected function flowHoldCents(Job $job): int
    {
        return (int) (optional($job->flow)->hold_amount_cents ?? $job->hold_amount_cents ?? 0);
    }

    protected function validEmailOrNull(?string $email): ?string
    {
        $email = trim((string) $email);
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    protected function validCustomerEmail(Job $job): ?string
    {
        $to = $job->customer_email ?: optional($job->customer)->email;
        return $this->validEmailOrNull($to);
    }

    /** Payment Element params (APM on). DO NOT add confirmation_method/payment_method_types. */
    protected function buildApmPiParams(Job $job, ?string $receiptEmail = null): array
    {
        $params = [
            'amount'   => $this->remainingCents($job),
            'currency' => $this->currencyFor($job),
            'metadata' => [
                'job_id' => (string) $job->id,
                'env'    => app()->environment(),
            ],
            'automatic_payment_methods' => ['enabled' => true],
        ];
        if ($receiptEmail) {
            $params['receipt_email'] = $receiptEmail;
        }
        return $params;
    }

    /** Safely pull card + receipt details from a PaymentIntent */
    protected function extractPiChargeDetails(object $pi): array
    {
        $charge = $pi->charges->data[0] ?? null;
        $pmd    = $charge->payment_method_details->card ?? null;

        return [
            'card_brand'  => $pmd->brand  ?? null,
            'card_last4'  => $pmd->last4  ?? null,
            'receipt_url' => $charge->receipt_url ?? null,
            'reference'   => $charge->id ?? null,
        ];
    }

    /* =========================================================================
     | Views
     * ========================================================================= */

    public function show(Request $request, Job $job)
    {
        return view('portal.pay', [
            'job'            => $job,
            'currency'       => strtoupper($this->currencyFor($job)),
            'remainingCents' => $this->remainingCents($job),
            'holdCents'      => $this->flowHoldCents($job),
            'pk'             => config('services.stripe.key'),
        ]);
    }

    public function complete(Request $request, Job $job)
    {
        $clientSecret = $request->query('payment_intent_client_secret');
        $setupSecret  = $request->query('setup_intent_client_secret');

        return view('portal.pay-complete-job', [
            'job'          => $job,
            'clientSecret' => $clientSecret,
            'setupSecret'  => $setupSecret,
            'status'       => $request->query('redirect_status'),
        ]);
    }

    /* =========================================================================
     | Intents
     * ========================================================================= */

    /** Create/update a single “pay now” PI (APM on). */
    public function intent(Request $request, Job $job): JsonResponse
    {
        try {
            $stripe  = $this->stripe();
            $amount  = $this->remainingCents($job);
            if ($amount < 50) {
                return response()->json(['ok' => false, 'message' => 'Nothing to charge.'], 422);
            }

            $existingPi = $request->string('payment_intent')->toString() ?: null;
            $receipt    = $this->validEmailOrNull($request->input('receipt_email'))
                        ?? $this->validCustomerEmail($job);

            $params = $this->buildApmPiParams($job, $receipt);
            $params['amount']      = $amount;
            $params['description'] = "Job #{$job->id} payment";

            $pi = $existingPi
                ? $stripe->paymentIntents->update($existingPi, Arr::only($params, ['amount','metadata','description','receipt_email']))
                : $stripe->paymentIntents->create($params, ['idempotency_key' => "intent_job_{$job->id}_" . uniqid('', true)]);

            return response()->json(['ok' => true, 'client_secret' => $pi->client_secret, 'id' => $pi->id]);
        } catch (Throwable $e) {
            Log::error('PayController@intent failed', ['job_id' => $job->id ?? null, 'err' => $e->getMessage()]);
            return response()->json(['ok' => false, 'message' => 'Unable to create payment intent'], 422);
        }
    }

    /**
     * Bundle: final payment (APM) + security hold (card/manual capture) + setup intent (off-session)
     */
    public function bundle(Request $request, Job $job): JsonResponse
    {
        try {
            $stripe         = $this->stripe();
            $remainingCents = (int) $this->remainingCents($job);
            $holdCents      = (int) $this->flowHoldCents($job);

            $min = 50;
            if ($remainingCents < $min) $remainingCents = 0;
            if ($holdCents      < $min) $holdCents      = 0;

            if ($remainingCents <= 0 && $holdCents <= 0) {
                return response()->json(['ok' => false, 'message' => 'Nothing to charge or hold.'], 422);
            }

            $receiptEmail = $this->validEmailOrNull($request->input('receipt_email')) ?? $this->validCustomerEmail($job);

            // Charge PI (APM)
            $chargePI = null;
            if ($remainingCents > 0) {
                $chargeParams = $this->buildApmPiParams($job, $receiptEmail);
                $chargeParams['amount']      = $remainingCents;
                $chargeParams['description'] = "Job #{$job->id} balance";
                $chargePI = $stripe->paymentIntents->create($chargeParams, [
                    'idempotency_key' => "bundle_charge_job_{$job->id}_" . uniqid('', true),
                ]);
            }

            // Hold PI (manual capture; card only)
            $holdPI = null;
            if ($holdCents > 0) {
                $holdParams = [
                    'amount'               => $holdCents,
                    'currency'             => $this->currencyFor($job),
                    'metadata'             => ['job_id' => (string) $job->id, 'env' => app()->environment(), 'type' => 'hold'],
                    'description'          => "Job #{$job->id} security hold",
                    'payment_method_types' => ['card'],
                    'capture_method'       => 'manual',
                ];
                if ($receiptEmail) $holdParams['receipt_email'] = $receiptEmail;

                $holdPI = $stripe->paymentIntents->create($holdParams, [
                    'idempotency_key' => "bundle_hold_job_{$job->id}_" . uniqid('', true),
                ]);
            }

            // SetupIntent (off-session)
            $setupIntent = $stripe->setupIntents->create([
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => ['job_id' => (string) $job->id, 'env' => app()->environment()],
            ], [
                'idempotency_key' => "bundle_setup_job_{$job->id}_" . uniqid('', true),
            ]);

            return response()->json([
                'ok'                   => true,
                'charge_client_secret' => $chargePI?->client_secret,
                'hold_client_secret'   => $holdPI?->client_secret,
                'setup_client_secret'  => $setupIntent->client_secret,
                'currency'             => $this->currencyFor($job),
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('PayController@bundle failed', [
                'job_id'          => $job->id ?? null,
                'remaining_cents' => $this->remainingCents($job),
                'hold_cents'      => $this->flowHoldCents($job),
                'currency'        => $this->currencyFor($job),
                'stripe_message'  => $e->getMessage(),
            ]);
            $userMsg = app()->environment('production')
                ? 'Could not prepare payment bundle.'
                : 'Could not prepare payment bundle: ' . $e->getMessage();
            return response()->json(['ok' => false, 'message' => $userMsg], 422);
        } catch (Throwable $e) {
            Log::error('PayController@bundle failed', [
                'job_id' => $job->id ?? null,
                'err'    => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'message' => 'Unexpected error preparing bundle'], 500);
        }
    }

    /* =========================================================================
     | Recording results (+ email on success)
     * ========================================================================= */

    /**
     * Record a successful payment for a Job, upsert Payment, create Deposit if available,
     * recompute paid totals, flip Job to 'paid' when covered, and (optionally) email receipt.
     * Route: POST /p/job/{job}/paid
     * Body: { payment_intent: "pi_xxx", payment_method?: "pm_xxx", charge?: "ch_xxx", amount_cents?: int, currency?: "nzd", meta?: {...}, status?: "succeeded" }
     */
    public function recordJobPaid(Request $request, Job $job): JsonResponse
    {
        $piId      = (string) $request->input('payment_intent');     // e.g. pi_123
        $pmId      = (string) $request->input('payment_method');     // e.g. pm_123
        $chargeId  = (string) $request->input('charge');             // e.g. ch_123
        $postedAmt = (int)    $request->integer('amount_cents', 0);
        $postedCur = strtolower((string) $request->input('currency', $this->currencyFor($job)));
        $meta      = (array)  $request->input('meta', []);
        $postedSt  = (string) $request->input('status', 'succeeded');

        if ($piId === '') {
            return response()->json(['ok' => false, 'message' => 'payment_intent is required'], 422);
        }

        // 1) Retrieve PI for truth
        try {
            $pi = $this->stripe()->paymentIntents->retrieve($piId, []);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'PaymentIntent not found'], 404);
        }

        // authoritative amounts/currency/status
        $amount   = (int) ($pi->amount_received ?? $pi->amount ?? 0) ?: $postedAmt;
        $currency = strtolower((string) ($pi->currency ?? $postedCur));
        $status   = in_array($pi->status, ['succeeded','requires_capture','processing'], true)
            ? $pi->status
            : $postedSt;

        $extra = $this->extractPiChargeDetails($pi);
        $cardBrand = $extra['card_brand'] ?? null;
        $last4     = $extra['card_last4'] ?? null;
        $receipt   = $extra['receipt_url'] ?? null;
        $reference = $extra['reference'] ?? $chargeId ?: null;

        // 2) Upsert Payment (idempotent on provider/provider_id)
        $candidate = [
            'job_id'        => $job->id,
            'amount_cents'  => $amount,
            'currency'      => $currency,
            'status'        => $status === 'succeeded' ? 'succeeded' : ($status === 'requires_capture' ? 'authorized' : 'processing'),
            'type'          => 'balance',
            'provider'      => 'stripe',
            'provider_id'   => $piId,
            'reference'     => $reference,
            'card_brand'    => $cardBrand,
            'card_last4'    => $last4,
            'receipt_url'   => $receipt,
            'paid_at'       => $status === 'succeeded' ? now() : null,
            'notes'         => 'Recorded via /p/job/{job}/paid',
        ];

        // Only include existing columns
        $paymentCols = Schema::getColumnListing('payments');
        $paymentData = Arr::only($candidate, $paymentCols);

        $lookup = Schema::hasColumn('payments', 'provider')
            ? ['provider' => 'stripe', 'provider_id' => $piId]
            : ['provider_id' => $piId];

        $payment = Payment::updateOrCreate($lookup, $paymentData);

        // 3) Create a Deposit row if that table exists
        if (Schema::hasTable('deposits')) {
            $depositCols = Schema::getColumnListing('deposits');

            $deposit = new Deposit();
            $set = function (Model $m, string $col, $val) use ($depositCols) {
                if (in_array($col, $depositCols, true) && $val !== null) {
                    $m->setAttribute($col, $val);
                }
            };

            $set($deposit, 'job_id',               $job->id);
            $set($deposit, 'booking_id',           $job->booking_id ?? null);
            $set($deposit, 'customer_id',          $job->customer_id ?? null);
            $set($deposit, 'amount_cents',         $amount ?: ($job->charge_cents ?? null));
            $set($deposit, 'currency',             strtoupper($currency));
            $set($deposit, 'status',               $status === 'succeeded' ? 'succeeded' : ($status === 'requires_capture' ? 'authorized' : 'processing'));
            $set($deposit, 'stripe_payment_intent',$piId);
            $set($deposit, 'stripe_payment_method',$pmId ?: null);
            $set($deposit, 'stripe_charge_id',     $chargeId ?: $reference);
            $set($deposit, 'meta',                 !empty($meta) ? $meta : null);
            if (in_array('type', $depositCols, true)) {
                $set($deposit, 'type', 'deposit');
            }

            try { $deposit->save(); } catch (Throwable $e) {
                Log::warning('recordJobPaid: deposit save failed', ['job_id' => $job->id, 'err' => $e->getMessage()]);
            }
        }

        // 4) Recompute totals from DB and update Job status/cache columns
        try {
            $paidCents = (int) $job->payments()
                ->whereIn('status', ['succeeded', 'captured'])
                ->sum('amount_cents');

            $totalCents = (int) ($job->charge_amount_cents ?? $job->charge_amount ?? 0);

            $jobCols = Schema::getColumnListing($job->getTable());
            $apply = function (string $col, $val) use ($job, $jobCols) {
                if (in_array($col, $jobCols, true)) {
                    $job->setAttribute($col, $val);
                }
            };

            $apply('paid_amount_cents', $paidCents);
            if ($totalCents > 0 && $paidCents >= $totalCents) {
                $apply('status', 'paid');
                $apply('payment_status', 'paid');
                $apply('paid_at', Carbon::now());
                if (in_array('balance_cents', $jobCols, true)) {
                    $apply('balance_cents', 0);
                }
            }

            $job->save();
        } catch (Throwable $e) {
            Log::warning('recordJobPaid: job reconciliation failed', ['job_id' => $job->id, 'err' => $e->getMessage()]);
        }

        // 5) Email receipt on success
        if ($status === 'succeeded') {
            $to = $this->validCustomerEmail($job);
            if ($to && class_exists(PaymentReceiptMail::class)) {
                try {
                    Mail::to($to)->queue(
                        new PaymentReceiptMail($job, $payment, route('portal.pay.show.job', ['job' => $job->id]))
                    );
                } catch (Throwable $e) {
                    Log::warning('Payment receipt email failed', [
                        'job_id' => $job->id ?? null,
                        'to'     => $to,
                        'err'    => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'ok'      => true,
            'payment' => Arr::only($payment->toArray(), [
                'id','amount_cents','currency','status','provider_id','card_brand','card_last4','receipt_url',
            ]),
        ]);
    }

    /**
     * Legacy minimal hook: mark an existing Payment (by provider_id) as succeeded and
     * then recompute Job totals. Prefer the richer recordJobPaid() above.
     */
    public function recordPaid(Request $request, Job $job): JsonResponse
    {
        $pi = (string) $request->input('payment_intent', '');

        if ($pi !== '') {
            try {
                $payment = Payment::query()
                    ->where('provider', 'stripe')
                    ->where('provider_id', $pi)
                    ->first();

                if ($payment && $payment->status !== 'succeeded') {
                    $payment->status = 'succeeded';
                    $payment->save();
                }
            } catch (Throwable $e) {
                Log::warning('recordPaid: unable to update Payment', [
                    'job_id' => $job->id, 'pi' => $pi, 'e' => $e->getMessage(),
                ]);
            }
        }

        // Recalculate totals from DB
        $totalCents = (int) ($job->charge_amount_cents ?? $job->charge_amount ?? 0);
        $paidCents  = (int) $job->payments()
            ->whereIn('status', ['succeeded', 'captured'])
            ->sum('amount_cents');

        $job->paid_amount_cents = $paidCents;
        if ($totalCents > 0 && $paidCents >= $totalCents) {
            $job->status = 'paid';
        }
        $job->save();

        return response()->json([
            'ok'         => true,
            'paid_cents' => $paidCents,
            'status'     => $job->status,
        ]);
    }
}
