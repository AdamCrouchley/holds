<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HoldReleasedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public int $holdCents,
        public ?string $currency = 'NZD',
        public ?object $brand = null
    ) {}

    public function build()
    {
        $brand = $this->brand;
        $subject = sprintf(
            '%s — Bond hold released for Job #%d',
            $brand->short_name ?? 'Dream Drives',
            $this->job->id
        );

        return $this->subject($subject)
            ->markdown('mail.hold-released', [
                'job'       => $this->job,
                'holdCents' => $this->holdCents,
                'currency'  => $this->currency,
                'brand'     => $brand,
            ]);
    }
}
