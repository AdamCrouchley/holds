<?php

namespace App\Mail;

use App\Models\Job;
use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public Job $job;
    public ?Payment $payment;
    public string $logoUrl;
    public string $viewUrl; // where the customer can view their booking/receipt
    public string $bookingReference;
    public string $currency;
    public ?string $paidAtTz;

    /**
     * @param Job $job
     * @param Payment|null $payment  Optional but recommended; include to show amount, last4, etc.
     * @param string|null $viewUrl   Optional; defaults to your pay/show route (adjust as needed).
     */
    public function __construct(Job $job, ?Payment $payment = null, ?string $viewUrl = null)
    {
        $this->job = $job;
        $this->payment = $payment;

        // Always use the booking_reference
        $this->bookingReference = $job->booking_reference ?: ('RES-' . $job->getKey());

        // Public logo URL (served via storage:link)
        $this->logoUrl = asset('storage/branding/logo.png');

        // Where the customer can view the booking or receipt
        $this->viewUrl = $viewUrl ?: route('portal.pay.show', $job);

        // Currency from Job -> Flow -> NZD (upper-case for display)
        $this->currency = strtoupper($job->currency ?? optional($job->flow)->currency ?? 'NZD');

        // Paid timestamp formatted in job timezone if we have payment info
        if ($payment && ($payment->paid_at ?? $payment->created_at ?? null)) {
            $tz = $job->timezone ?? config('app.timezone', 'UTC');
            $ts = Carbon::parse($payment->paid_at ?? $payment->created_at)->timezone($tz);
            $this->paidAtTz = $ts->isoFormat('ddd D MMM YYYY, HH:mm') . " ({$tz})";
        } else {
            $this->paidAtTz = null;
        }
    }

    public function build()
    {
        // Amount helpers
        $fmtMoney = function ($cents) {
            if ($cents === null) return null;
            $amount = number_format(((int) $cents) / 100, 2);
            return "{$this->currency} {$amount}";
        };

        // Try to read useful payment details (defensive)
        $amountCents = $this->payment->amount_cents ?? $this->job->paid_cents ?? null;
        $amountDisplay = $fmtMoney($amountCents);

        $cardBrand = $this->payment->card_brand ?? $this->payment->brand ?? null;
        $cardLast4 = $this->payment->card_last4 ?? $this->payment->last4 ?? null;
        $provider  = $this->payment->provider ?? 'stripe';
        $providerId = $this->payment->provider_id ?? null;   // e.g. pi_xxx
        $receiptUrl = $this->payment->receipt_url ?? null;    // if you store Stripe's receipt_url

        $subject = 'Payment received for booking ' . $this->bookingReference;

        return $this
            ->subject($subject)
            ->markdown('emails.payment-receipt', [
                'job'            => $this->job,
                'payment'        => $this->payment,
                'logoUrl'        => $this->logoUrl,
                'viewUrl'        => $this->viewUrl,
                'reference'      => $this->bookingReference,
                'currency'       => $this->currency,
                'amountDisplay'  => $amountDisplay,
                'paidAtTz'       => $this->paidAtTz,
                'cardBrand'      => $cardBrand,
                'cardLast4'      => $cardLast4,
                'provider'       => $provider,
                'providerId'     => $providerId,
                'receiptUrl'     => $receiptUrl,
            ]);
    }
}
