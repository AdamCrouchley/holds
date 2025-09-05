<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Services\ExternalSyncService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importByReference') // NOTE: renamed to avoid any collisions
                ->label('Sync by Reference')
                ->icon('heroicon-o-magnifying-glass')
                ->form([
                    TextInput::make('reference')
                        ->label('External reference')
                        ->required()
                        ->maxLength(120),
                ])
                ->action(function (array $data): void {
                    try {
                        /** @var ExternalSyncService $svc */
                        $svc = app(ExternalSyncService::class);

                        // DO NOT type-hint scalars anywhere; read from $data:
                        $result = $svc->syncByReference((string) $data['reference']);

                        Notification::make()
                            ->title('Sync complete')
                            ->body('Imported/updated booking #'.($result['booking_id'] ?? '—').' for '.$data['reference'])
                            ->success()
                            ->send();

                        // Refresh the table
                        $this->dispatch('refresh');
                    } catch (Throwable $e) {
                        report($e);

                        Notification::make()
                            ->title('Sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),

            Actions\CreateAction::make(), // New booking
        ];
    }
}
