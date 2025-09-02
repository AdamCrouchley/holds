<?php

// app/Mail/BookingPaymentLink.php
namespace App\Mail;
use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class BookingPaymentLink extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking, public string $url) {}

    public function build() {
        return $this->subject('Complete your booking payment')
            ->markdown('mail.booking.payment-link', ['booking' => $this->booking, 'url' => $this->url]);
    }
}
