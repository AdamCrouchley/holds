<?php

namespace App\Domain\Holds\Services\Adapters;

use App\Domain\Holds\Services\Contracts\Mailer;
use Illuminate\Support\Facades\Mail;

class LaravelMailer implements Mailer
{
    public function send(string $toEmail, string $subject, string $html, ?string $text = null, array $opts = []): void
    {
        Mail::raw($text ?? strip_tags($html), function($m) use ($toEmail, $subject) {
            $m->to($toEmail)->subject($subject);
        });
    }
}
