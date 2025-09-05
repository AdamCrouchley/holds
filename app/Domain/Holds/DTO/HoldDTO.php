<?php

namespace App\Domain\Holds\DTO;

class HoldDTO
{
    public function __construct(
        public ?int $id = null,
        public ?int $job_id = null,
        public ?string $provider_id = null,
        public string $status = 'pending',
        public int $amount_cents = 0,
        public string $currency = 'NZD',
        public ?string $expires_at = null,
        public ?int $captured_amount_cents = 0,
        public array $meta = [],
    ) {}
}
