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
        public string $payUrl,          // signed or token URL
        public ?int $amountCents = null,// optional specific amount (e.g., deposit)
        public ?string $purpose = null, // 'deposit' | 'balance' | 'payment'
        public ?object $brand = null,   // Brand context (logo/url/colors)
    ) {}

    public function build()
    {
        $brand = $this->brand;
        $subject = sprintf(
            '%s — Secure payment link for Job #%d',
            $brand->short_name ?? 'Dream Drives',
            $this->job->id
        );

        return $this->subject($subject)
            ->markdown('mail.payment-request', [
                'job'         => $this->job,
                'payUrl'      => $this->payUrl,
                'amountCents' => $this->amountCents,
                'purpose'     => $this->purpose,
                'brand'       => $brand,
            ]);
    }
}
