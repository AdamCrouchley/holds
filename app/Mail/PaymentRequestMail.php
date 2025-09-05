<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public string $payUrl,
        public int $amountCents
    ) {}

    public function build()
    {
        $amount = number_format($this->amountCents / 100, 2);

        return $this->subject("Payment request for {$this->job->reference} - \${$amount}")
            ->markdown('emails.payment_request', [
                'job'        => $this->job,
                'payUrl'     => $this->payUrl,
                'amount'     => $amount,
                'amountCents'=> $this->amountCents,
            ]);
    }
}
