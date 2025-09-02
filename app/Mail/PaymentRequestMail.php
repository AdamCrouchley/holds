<?php

namespace App\Mail;

use App\Models\Booking;
use App\Models\PaymentRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Booking $booking, public PaymentRequest $pr) {}

    public function build()
    {
        $url = url('/p/b/'.$this->booking->portal_token);

        return $this->subject('Payment request for your booking '.$this->booking->reference)
            ->view('emails.payment-request')
            ->with([
                'booking' => $this->booking,
                'pr'      => $this->pr,
                'url'     => $url,
                'amount'  => $this->pr->amount ? number_format($this->pr->amount/100, 2) : null,
            ]);
    }
}
