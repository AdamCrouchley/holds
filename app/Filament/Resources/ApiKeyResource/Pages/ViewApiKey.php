<?php

namespace App\Filament\Resources\ApiKeyResource\Pages;

use App\Filament\Resources\ApiKeyResource;
use App\Models\ApiKey;
use Filament\Resources\Pages\ViewRecord;
use Filament\Actions;
use Filament\Notifications\Notification;

class ViewApiKey extends ViewRecord
{
    protected static string $resource = ApiKeyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('regenerate')
                ->label('Regenerate')
                ->icon('heroicon-m-arrow-path')
                ->requiresConfirmation()
                ->color('warning')
                ->action(function () {
                    /** @var ApiKey $record */
                    $record = $this->getRecord();
                    $record->update(['key' => bin2hex(random_bytes(32))]);

                    Notification::make()
                        ->title('API key regenerated')
                        ->body('The secret has been updated.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['key']);
                }),

            Actions\EditAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
