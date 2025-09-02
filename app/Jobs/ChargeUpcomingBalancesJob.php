<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Payment;
use App\Services\StripeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ChargeUpcomingBalancesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;           // basic retry
    public int $backoff = 3600;      // 1h between retries

    public function handle(StripeService $stripe): void
    {
        // Charge bookings starting within T-3 days that still owe money
        $bookings = Booking::with('customer')
            ->where('balance_charged', false)
            ->whereBetween('start_at', [now(), now()->addDays(3)])
            ->get();

        foreach ($bookings as $b) {
            $cust = $b->customer;
            $due  = max(0, (int)$b->total_amount - (int)$b->deposit_amount);
            if ($due <= 0 || !$cust?->default_payment_method_id || !$cust?->stripe_customer_id) {
                continue;
            }

            try {
                $idem = 'balance_'.$b->id.'_'.Str::uuid();
                $pi = $stripe->stripe->paymentIntents->create([
                    'amount'         => $due,
                    'currency'       => strtolower($b->currency),
                    'customer'       => $cust->stripe_customer_id,
                    'payment_method' => $cust->default_payment_method_id,
                    'off_session'    => true,
                    'confirm'        => true,
                    'description'    => "Final balance {$b->reference}",
                    'metadata'       => [
                        'booking_id'  => (string)$b->id,
                        'booking_ref' => $b->reference,
                        'type'        => 'balance',
                    ],
                ], ['idempotency_key' => $idem]);

                Payment::create([
                    'booking_id' => $b->id,
                    'customer_id'=> $cust->id,
                    'type'       => 'balance',
                    'amount'     => $due,
                    'currency'   => $b->currency,
                    'stripe_payment_intent_id' => $pi->id,
                    'status'     => 'pending',
                ]);

                Log::info('[holds] balance PI created', ['booking' => $b->id, 'pi' => $pi->id]);
            } catch (\Throwable $e) {
                Log::error('[holds] balance charge failed to create PI', [
                    'booking' => $b->id, 'error' => $e->getMessage()
                ]);
                // Let retries handle it; webhook will capture final status if PI was created.
            }
        }
    }
}
