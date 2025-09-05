<?php

namespace App\Domain\Holds\Services\Adapters;

use App\Domain\Holds\Services\Contracts\SmsSender;

class NullSms implements SmsSender
{
    public function send(string $toPhone, string $message): void
    {
        // no-op for now
    }
}
