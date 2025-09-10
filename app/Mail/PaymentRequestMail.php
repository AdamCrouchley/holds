<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public Job $job;
    public string $payUrl;
    public string $logoUrl;

    public function __construct(Job $job, ?string $payUrl = null)
    {
        $this->job    = $job;
        $this->payUrl = $payUrl ?: route('portal.pay.show', $job);

        // Serve logo via public/storage symlink
        $this->logoUrl = asset('storage/branding/logo.png');
    }

    public function build()
    {
        // Always use the booking_reference field
        $reference = $this->job->booking_reference
            ?: ('RES-' . $this->job->getKey());

        return $this
            ->subject('Payment request for booking ' . $reference)
            ->markdown('emails.payment-request', [
                'job'       => $this->job,
                'payUrl'    => $this->payUrl,
                'logoUrl'   => $this->logoUrl,
                'reference' => $reference,
            ]);
    }
}
