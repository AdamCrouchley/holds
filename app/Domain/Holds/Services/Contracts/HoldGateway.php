<?php

namespace App\Domain\Holds\Services\Contracts;

use App\Domain\Holds\DTO\HoldDTO;

interface HoldGateway {
    public function createHold(HoldDTO $hold): HoldDTO;
    public function renew(string $providerId): HoldDTO;
    public function capture(string $providerId, int $amountCents): HoldDTO;
    public function release(string $providerId): HoldDTO;
}
