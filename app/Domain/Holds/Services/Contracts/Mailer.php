<?php

namespace App\Domain\Holds\Services\Contracts;

interface Mailer {
    public function send(string $toEmail, string $subject, string $html, ?string $text = null, array $opts = []): void;
}
