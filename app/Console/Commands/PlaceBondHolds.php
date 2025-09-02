<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use Stripe\StripeClient;
use Illuminate\Support\Facades\Log;

class PlaceBondHolds extends Command
{
    protected $signature = 'bookings:place-bond-holds';
    protected $description = 'Place manual-capture authorizations for upcoming bookings';

    public function handle(): int
    {
        $stripe = new StripeClient(config('services.stripe.secret'));

        // example window: one day from now (current hour)
        $from = now()->addDay()->startOfHour();
        $to   = now()->addDay()->endOfHour();

        Booking::with('customer')
            ->whereBetween('start_at', [$from, $to])
            ->whereNotNull('hold_amount')
            ->chunkById(100, function ($bookings) use ($stripe) {
                foreach ($bookings as $b) {
                    if ($b->stripe_bond_pi_id) {
                        $this->line("Skip {$b->reference} (already authorized)");
                        continue;
                    }

                    $cust = $b->customer;
                    if (!$cust?->stripe_customer_id) {
                        Log::warning("No Stripe customer for bond", ['booking' => $b->id]);
                        continue;
                    }

                    // Needs a default PM saved earlier
                    $sc = $stripe->customers->retrieve($cust->stripe_customer_id);
                    $defaultPm = $sc->invoice_settings?->default_payment_method ?? null;
                    if (!$defaultPm) {
                        Log::warning("No default PM for bond", ['booking' => $b->id, 'customer' => $cust->id]);
                        continue;
                    }

                    $pi = $stripe->paymentIntents->create([
                        'amount'         => (int) $b->hold_amount,
                        'currency'       => 'nzd',
                        'customer'       => $cust->stripe_customer_id,
                        'payment_method' => $defaultPm,
                        'confirm'        => true,
                        'off_session'    => true,
                        'capture_method' => 'manual',
                        'metadata'       => [
                            'type'       => 'bond_hold',
                            'booking_id' => $b->id,
                            'reference'  => (string) $b->reference,
                        ],
                    ]);

                    if (in_array($pi->status, ['requires_capture', 'processing', 'succeeded'], true)) {
                        $b->forceFill([
                            'stripe_bond_pi_id'  => $pi->id,
                            'bond_authorized_at' => now(),
                        ])->save();
                        $this->info("Bond authorized for {$b->reference}");
                    } else {
                        Log::warning("Bond PI unexpected status", [
                            'booking' => $b->id,
                            'status'  => $pi->status,
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }
}
