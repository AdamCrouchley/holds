<?php

// app/Mail/PaymentReceipt.php
namespace App\Mail;
use App\Models\Booking;
use Illuminate\Mail\Mailable;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;

class PaymentReceipt extends Mailable
{
    use Queueable, SerializesModels;
    public function __construct(public Booking $booking, public int $amountCents) {}
    public function build() {
        return $this->subject('Payment receipt')
            ->markdown('mail.booking.receipt', [
                'booking'=>$this->booking,
                'amount'=>$this->amountCents,
            ]);
    }
}
