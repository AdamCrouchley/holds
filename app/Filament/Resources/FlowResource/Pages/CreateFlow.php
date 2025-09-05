<?php

namespace App\Filament\Resources\FlowResource\Pages;

use App\Filament\Resources\FlowResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlow extends CreateRecord
{
    protected static string $resource = FlowResource::class;

    // Optional: where to go after creating
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
