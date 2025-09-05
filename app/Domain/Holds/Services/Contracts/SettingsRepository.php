<?php

namespace App\Domain\Holds\Services\Contracts;

interface SettingsRepository {
    public function get(int $brandId, string $key, mixed $default = null): mixed;
    public function set(int $brandId, string $key, mixed $value): void;
}
