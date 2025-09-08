<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobAccessToken;
// use App\Models\Booking; // uncomment if you have a Booking model
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;
use Throwable;

class PayController extends Controller
{
    /**
     * Show the payment page for a Job via standard route-model binding.
     * Route example: GET /p/job/{job}/pay  (signed recommended)
     */
    public function show(Request $request, Job $job)
    {
        // abort_if($job->status === 'cancelled', 404);
        // abort_unless($request->hasValidSignature(), 403);

        return view('portal.pay', ['job' => $job]);
    }

    /**
     * Optional: show the payment page for a Booking if you support bookings.
     * Route example: GET /p/booking/{booking}/pay
     */
    public function showBooking(Request $request, /* Booking */ $booking)
    {
        // Replace type if you have a concrete Booking model: public function showBooking(Request $request, Booking $booking)
        return view('portal.pay', ['booking' => $booking]);
    }

    /**
     * Show the payment page for a Job via a shareable token link.
     * Route example: GET /p/pay/t/{token}
     */
    public function showByToken(string $token)
    {
        $access = JobAccessToken::with('job')->where('token', $token)->firstOrFail();

        // Optional expiry/revocation check if your model has it
        abort_unless(method_exists($access, 'isValid') ? $access->isValid() : true, 403);

        return view('portal.pay', [
            'job'   => $access->job,
            'token' => $token,
        ]);
    }

    /**
     * Create/Upsert a Stripe PaymentIntent for a Job/Booking.
     *
     * POST /p/intent/{type}/{id}
     * Body (JSON/Form):
     * - amount_cents: int (required)
     * - currency: string (default "NZD")
     * - mode: "payment" | "hold" (default "payment")
     * - reference: string (optional) — your internal reference to persist
     *
     * Returns: { payment_intent_id, client_secret, mode }
     */
    public function intent(Request $request, string $type, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency'     => ['sometimes', 'string', 'size:3'],
            'mode'         => ['sometimes', 'in:payment,hold'],
            'reference'    => ['sometimes', 'string', 'max:120'],
            // If you need to pass customer/payment_method ids, add them here
        ]);

        $currency = strtoupper($validated['currency'] ?? 'NZD');
        $mode     = $validated['mode'] ?? 'payment';

        // Resolve the payee context (job or booking)
        [$ownerType, $owner] = $this->resolveOwner($type, $id);

        // Optionally guard against invalid/closed states here
        // abort_if($owner->status === 'cancelled', 422, 'Item is not payable.');

        // Stripe
        $secret = config('services.stripe.secret') ?: env('STRIPE_SECRET');
        throw_if(empty($secret), ValidationException::withMessages(['stripe' => 'Stripe secret key is not configured.']));

        $stripe = new StripeClient($secret);

        try {
            $params = [
                'amount'                     => (int) $validated['amount_cents'],
                'currency'                   => $currency,
                'automatic_payment_methods'  => ['enabled' => true],
                // You can include metadata to link back to your domain objects
                'metadata'                   => array_filter([
                    'owner_type' => $ownerType,
                    'owner_id'   => (string) $owner->getKey(),
                    'reference'  => Arr::get($validated, 'reference'),
                    'app'        => config('app.name', 'Laravel'),
                ]),
            ];

            // Manual capture for holds (aka "authorise" only)
            if ($mode === 'hold') {
                $params['capture_method'] = 'manual';
            }

            $pi = $stripe->paymentIntents->create($params);

            // If you want to persist a record linking this PI to your Job/Booking/Payment table, do it here.
            // e.g., Payment::create([... 'stripe_payment_intent_id' => $pi->id, 'reference' => $validated['reference'] ?? null, ...]);

            return response()->json([
                'ok'                 => true,
                'mode'               => $mode,
                'payment_intent_id'  => $pi->id,
                'client_secret'      => $pi->client_secret,
            ]);
        } catch (Throwable $e) {
            report($e);
            return response()->json([
                'ok'      => false,
                'message' => 'Unable to create PaymentIntent.',
            ], 422);
        }
    }

    /**
     * Acknowledge that a manual-capture "hold" succeeded on the client.
     *
     * POST /p/pay/{type}/{id}/hold-recorded
     * Body (optional):
     * - payment_intent_id: string
     * - reference: string
     *
     * Returns: 204 No Content (or 200 JSON if you prefer)
     */
    public function holdRecorded(Request $request, string $type, int $id)
    {
        // Make sure it’s a valid owner; throws 404 if not.
        [, $owner] = $this->resolveOwner($type, $id);

        // Optionally verify PI exists & is authorized:
        // if ($piId = $request->string('payment_intent_id')->toString()) {
        //     $secret = config('services.stripe.secret') ?: env('STRIPE_SECRET');
        //     $stripe = new \Stripe\StripeClient($secret);
        //     $pi = $stripe->paymentIntents->retrieve($piId);
        //     abort_unless($pi && $pi->status === 'requires_capture', 422, 'Hold not in an authorizable state.');
        // }

        // Optionally persist a local flag / timestamp, or attach to a Payment row.
        // $owner->forceFill(['latest_hold_recorded_at' => now()])->save();

        return response()->noContent(); // 204
    }

    /**
     * Resolve whether we’re dealing with a Job or Booking, returning [type, model].
     *
     * @return array{0:string,1:mixed}
     */
    protected function resolveOwner(string $type, int $id): array
    {
        $type = strtolower($type);

        if ($type === 'job') {
            /** @var Job $job */
            $job = Job::query()->findOrFail($id);
            return ['job', $job];
        }

        if ($type === 'booking') {
            // Replace this with your real Booking model and import it.
            // $booking = Booking::query()->findOrFail($id);
            // return ['booking', $booking];

            abort(404, 'Booking support is not enabled.');
        }

        abort(404, 'Unsupported owner type.');
    }
}
