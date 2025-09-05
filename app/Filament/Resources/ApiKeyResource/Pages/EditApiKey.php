<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions;

class EditApiKey extends EditRecord
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // If secret left blank while editing, keep existing value
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['key'] ?? '') === '') {
            unset($data['key']);
        }
        return $data;
    }
}
