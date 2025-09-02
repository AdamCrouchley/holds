<?php

namespace App\Jobs;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stripe\StripeClient;
use Throwable;

class CancelHoldPaymentIntentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $bookingId, public string $paymentIntentId) {}

    public function handle(): void
    {
        $booking = Booking::find($this->bookingId);
        if (!$booking) return;

        $secret = config('services.stripe.secret') ?: env('STRIPE_SECRET');
        $stripe = new StripeClient($secret);

        try {
            $pi = $stripe->paymentIntents->retrieve($this->paymentIntentId);
            if ($pi && $pi->status === 'requires_capture') {
                // Still an open hold → cancel it
                $stripe->paymentIntents->cancel($pi->id);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
