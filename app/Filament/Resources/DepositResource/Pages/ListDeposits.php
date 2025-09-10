<?php

namespace App\Filament\Resources\DepositResource\Pages;

use App\Filament\Resources\DepositResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Actions;

class ListDeposits extends ListRecords
{
    protected static string $resource = DepositResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
