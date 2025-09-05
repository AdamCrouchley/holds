<?php

namespace App\Domain\Holds\Services\Contracts;

interface SmsSender {
    public function send(string $toPhone, string $message): void;
}
