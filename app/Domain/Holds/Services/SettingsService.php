<?php

namespace App\Domain\Holds\Services;

use App\Domain\Holds\Services\Contracts\SettingsRepository;
use App\Domain\Holds\Models\BrandSettings;

class SettingsService implements SettingsRepository
{
    public function get(int $brandId, string $key, mixed $default = null): mixed
    {
        $s = BrandSettings::firstWhere('brand_id', $brandId);
        return $s[$key] ?? $default;
    }

    public function set(int $brandId, string $key, mixed $value): void
    {
        $s = BrandSettings::firstOrCreate(['brand_id'=>$brandId]);
        $s->forceFill([$key => $value])->save();
    }
}
