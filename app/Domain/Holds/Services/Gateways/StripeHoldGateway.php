<?php

namespace App\Domain\Holds\Services\Gateways;

use App\Domain\Holds\Services\Contracts\HoldGateway;
use App\Domain\Holds\DTO\HoldDTO;

class StripeHoldGateway implements HoldGateway
{
    public function createHold(HoldDTO $hold): HoldDTO { return $hold; }
    public function renew(string $providerId): HoldDTO { return new HoldDTO(provider_id:$providerId); }
    public function capture(string $providerId, int $amountCents): HoldDTO { return new HoldDTO(provider_id:$providerId); }
    public function release(string $providerId): HoldDTO { return new HoldDTO(provider_id:$providerId); }
}
