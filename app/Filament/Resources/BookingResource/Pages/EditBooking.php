<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Payment;
use Filament\Resources\Pages\EditRecord;
use Filament\Actions;
use Filament\Forms;
use Illuminate\Support\Facades\Schema;

class EditBooking extends EditRecord
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('addDepositPaid')
                ->label('Add Deposit Paid')
                ->icon('heroicon-o-banknotes')
                ->form([
                    Forms\Components\TextInput::make('amount_nzd')
                        ->label('Amount (NZD)')
                        ->numeric()
                        ->step('0.01')
                        ->required(),
                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->default('completed')
                        ->options([
                            'completed' => 'Completed',
                            'paid'      => 'Paid',
                            'succeeded' => 'Succeeded',
                            'captured'  => 'Captured',
                        ]),
                    Forms\Components\TextInput::make('note')
                        ->label('Note')
                        ->maxLength(255),
                ])
                ->action(function (array $data) {
                    $record = $this->getRecord();
                    $amountCents = (int) round(((float) $data['amount_nzd']) * 100);

                    $p = new Payment();

                    if (Schema::hasColumn('payments', 'booking_id')) {
                        $p->booking_id = $record->id;
                    } elseif (Schema::hasColumn('payments', 'booking_reference')) {
                        $p->booking_reference = $record->reference;
                    }

                    $purposeCol = Schema::hasColumn('payments', 'purpose') ? 'purpose' : (Schema::hasColumn('payments', 'type') ? 'type' : null);
                    if ($purposeCol) {
                        $p->{$purposeCol} = 'deposit';
                    }

                    $p->amount = $amountCents;

                    if (Schema::hasColumn('payments', 'status')) {
                        $p->status = $data['status'] ?? 'completed';
                    }

                    if (Schema::hasColumn('payments', 'note') && !empty($data['note'])) {
                        $p->note = $data['note'];
                    }

                    $p->save();

                    $this->refreshFormData(['_all_']);
                }),

            Actions\DeleteAction::make(),
        ];
    }
}
