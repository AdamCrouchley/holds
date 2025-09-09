<?php

namespace App\Mail;

use App\Models\Job;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class HoldPlacedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Job $job,
        public int $holdCents,
        public ?string $currency = 'NZD',
        public ?object $brand = null,
        public ?string $expectedReleaseNote = null // e.g. "usually 7–10 days"
    ) {}

    public function build()
    {
        $brand = $this->brand;
        $subject = sprintf(
            '%s — Bond hold placed for Job #%d',
            $brand->short_name ?? 'Dream Drives',
            $this->job->id
        );

        return $this->subject($subject)
            ->markdown('mail.hold-placed', [
                'job'                 => $this->job,
                'holdCents'           => $this->holdCents,
                'currency'            => $this->currency,
                'brand'               => $brand,
                'expectedReleaseNote' => $this->expectedReleaseNote,
            ]);
    }
}

