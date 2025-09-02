<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TriggerController extends Controller
{
    public function paymentRequest(Request $req)
    {
        // --- auth via header token ---
        $token = $req->header('X-Holds-Key');
        abort_if(!$token, 401, 'Missing X-Holds-Key');

        $client = ApiClient::where('token', $token)->where('enabled', true)->first();
        abort_if(!$client, 401, 'Invalid API key');

        // --- validate ---
        $data = $req->validate([
            'reference'      => ['nullable','string'], // booking reference if known
            'portal_token'   => ['nullable','string'],
            'type'           => ['nullable','in:balance,bond,custom'],
            'amount'         => ['nullable','numeric','min:0'],  // dollars; optional if 'balance'
            'currency'       => ['nullable','string','size:3'],
            'due_at'         => ['nullable','date'],
            'message'        => ['nullable','string','max:2000'],
            // If booking not known, allow creating a shell booking+customer:
            'customer.name'  => ['nullable','string','max:255'],
            'customer.email' => ['nullable','email'],
            'customer.phone' => ['nullable','string','max:255'],
            'start_at'       => ['nullable','date'],
            'end_at'         => ['nullable','date'],
        ]);

        $idempotencyKey = $req->header('Idempotency-Key');

        return DB::transaction(function () use ($data, $client, $idempotencyKey) {

            // 1) Find or create booking
            $booking = null;

            if (!empty($data['reference'])) {
                $booking = Booking::where('reference', $data['reference'])->first();
            }
            if (!$booking && !empty($data['portal_token'])) {
                $booking = Booking::where('portal_token', $data['portal_token'])->first();
            }
            if (!$booking) {
                // create minimal booking if customer provided
                abort_if(empty($data['customer']), 422, 'booking not found; provide reference or customer info');

                $cust = Customer::firstOrCreate(
                    ['email' => $data['customer']['email'] ?? 'noemail+'.Str::uuid().'@holds.invalid'],
                    [
                        'name'  => $data['customer']['name'] ?? 'Guest',
                        'phone' => $data['customer']['phone'] ?? null,
                    ]
                );

                $booking = Booking::create([
                    'customer_id'   => $cust->id,
                    'reference'     => $data['reference'] ?? 'API-'.Str::upper(Str::random(10)),
                    'status'        => 'pending',
                    'currency'      => strtoupper($data['currency'] ?? 'NZD'),
                    'start_at'      => !empty($data['start_at']) ? now()->parse($data['start_at']) : null,
                    'end_at'        => !empty($data['end_at'])   ? now()->parse($data['end_at'])   : null,
                    'source_system' => 'api',
                    'source_id'     => $client->id,
                    'meta'          => ['created_via' => 'Trigger API'],
                    'portal_token'  => Str::random(48),
                ]);
            } else {
                if (empty($booking->portal_token)) {
                    $booking->forceFill(['portal_token' => Str::random(48)])->save();
                }
            }

            // 2) Prevent duplicate requests via Idempotency-Key
            if ($idempotencyKey && PaymentRequest::where('idempotency_key', $idempotencyKey)->exists()) {
                $existing = PaymentRequest::where('idempotency_key', $idempotencyKey)->first();
                return response()->json([
                    'ok' => true,
                    'id' => $existing->id,
                    'payment_request' => $existing->toArray(),
                    'portal_url' => url('/p/b/'.$booking->portal_token),
                ], 200);
            }

            // 3) Create the payment request
            $amountCents = null;
            if (isset($data['amount']) && $data['amount'] !== null && $data['amount'] !== '') {
                $amountCents = (int) round(((float) $data['amount']) * 100);
            }

            $pr = PaymentRequest::create([
                'booking_id'      => $booking->id,
                'type'            => $data['type'] ?? 'balance',
                'amount'          => $amountCents, // null means “remaining balance”
                'currency'        => strtoupper($data['currency'] ?? ($booking->currency ?? 'NZD')),
                'due_at'          => !empty($data['due_at']) ? now()->parse($data['due_at']) : null,
                'status'          => 'pending',
                'idempotency_key' => $idempotencyKey,
                'source_system'   => 'api',
                'meta'            => [
                    'message' => $data['message'] ?? null,
                    'api_client_id' => $client->id,
                ],
            ]);

            // 4) Notify guest (email/SMS). Swap for your system.
            try {
                \Mail::to(optional($booking->customer)->email)
                    ->send(new \App\Mail\PaymentRequestMail($booking, $pr));
                $pr->update(['status' => 'sent']);
            } catch (\Throwable $e) {
                // leave as pending, you can retry with a job
            }

            return response()->json([
                'ok' => true,
                'id' => $pr->id,
                'payment_request' => $pr->toArray(),
                'portal_url' => url('/p/b/'.$booking->portal_token),
            ], 201);
        });
    }
}
