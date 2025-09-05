<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use Filament\Resources\Pages\CreateRecord;

class CreateApiKey extends CreateRecord
{
    protected static string $resource = ApiKeyResource::class;

    // Auto-generate a secret if left blank
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['key'])) {
            $data['key'] = bin2hex(random_bytes(32)); // 256-bit
        }
        return $data;
    }
}
