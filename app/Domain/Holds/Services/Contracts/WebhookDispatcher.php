<?php

namespace App\Domain\Holds\Services\Contracts;

interface WebhookDispatcher {
    public function dispatch(string $event, array $payload, int $brandId): void;
}
