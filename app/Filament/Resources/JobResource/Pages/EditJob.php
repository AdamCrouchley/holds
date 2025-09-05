<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobResource\Pages;

use App\Filament\Resources\JobResource;
use App\Services\ExternalSyncService;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Throwable;

class EditJob extends EditRecord
{
    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncByReference')
                ->label('Sync by reference')
                ->icon('heroicon-o-arrow-path')
                ->form([
                    TextInput::make('reference')
                        ->label('External reference')
                        ->required(),
                ])
                ->action(function (\App\Models\Job $record, array $data) {
                    $ref = (string) $data['reference'];
                    Log::withContext(['job_id' => $record->id, 'reference' => $ref]);
                    Log::info('Manual syncByReference triggered from Filament.');

                    try {
                        /** @var ExternalSyncService $svc */
                        $svc = app(ExternalSyncService::class);
                        $result = $svc->syncByReference($ref, $record);

                        Notification::make()
                            ->title('Sync complete')
                            ->body('Imported booking #' . ($result['booking_id'] ?? '—') . ' for ' . $ref)
                            ->success()
                            ->send();

                        $this->refreshFormData(['record' => $record->refresh()]);
                    } catch (Throwable $e) {
                        // Make the real reason visible in UI and logs
                        report($e);

                        Notification::make()
                            ->title('Sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
