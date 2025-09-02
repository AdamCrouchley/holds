<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms;
use Filament\Tables;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';
    protected static ?string $title = 'Payments';

    public function form(Form $form): Form
    {
        // Helpers: dollars in UI, store cents
        $formatAsDollars = fn (?int $cents) => number_format(((int)($cents ?? 0))/100, 2, '.', '');
        $dehydrateAsCents = fn ($state) => (int) round(((float) ($state ?? 0)) * 100);

        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->label('Amount (NZD)')
                ->numeric()
                ->step('0.01')
                ->formatStateUsing(fn ($state) => $formatAsDollars($state))
                ->dehydrateStateUsing(fn ($state) => $dehydrateAsCents($state))
                ->required(),

            Forms\Components\Select::make('status')
                ->label('Status')
                ->options([
                    'completed' => 'Completed',
                    'paid'      => 'Paid',
                    'succeeded' => 'Succeeded',
                    'captured'  => 'Captured',
                    'pending'   => 'Pending',
                    'failed'    => 'Failed',
                    'refunded'  => 'Refunded',
                ])
                ->default('completed')
                ->required()
                ->visible(fn () => Schema::hasColumn('payments', 'status')),

            // Use whichever column you have: purpose or type
            Forms\Components\Select::make('purpose')
                ->label('Purpose')
                ->options([
                    'deposit' => 'Deposit',
                    'rental'  => 'Rental',
                    'hold'    => 'Security Hold',
                    'other'   => 'Other',
                ])
                ->visible(fn () => Schema::hasColumn('payments', 'purpose')),

            Forms\Components\Select::make('type')
                ->label('Type')
                ->options([
                    'deposit' => 'Deposit',
                    'rental'  => 'Rental',
                    'hold'    => 'Security Hold',
                    'other'   => 'Other',
                ])
                ->visible(fn () => Schema::hasColumn('payments', 'type')),

            Forms\Components\TextInput::make('note')
                ->label('Note')
                ->maxLength(255)
                ->visible(fn () => Schema::hasColumn('payments', 'note')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->money('nzd', divideBy: 100)
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'success' => ['completed', 'paid', 'succeeded', 'captured'],
                        'warning' => ['pending'],
                        'danger'  => ['failed'],
                        'secondary' => ['refunded'],
                    ])
                    ->visible(fn () => Schema::hasColumn('payments', 'status'))
                    ->sortable(),

                Tables\Columns\TextColumn::make('purpose')
                    ->label('Purpose')
                    ->visible(fn () => Schema::hasColumn('payments', 'purpose'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->visible(fn () => Schema::hasColumn('payments', 'type'))
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Note')
                    ->limit(40)
                    ->visible(fn () => Schema::hasColumn('payments', 'note'))
                    ->toggleable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Add Payment')
                    ->mutateFormDataUsing(function (array $data, Model $ownerRecord) {
                        // Link new payment to the booking safely
                        if (Schema::hasColumn('payments', 'booking_id')) {
                            $data['booking_id'] = $ownerRecord->id;
                        } elseif (Schema::hasColumn('payments', 'booking_reference')) {
                            $data['booking_reference'] = $ownerRecord->reference;
                        }

                        // Ensure purpose/type=deposit if the amount is intended as a deposit
                        // (left as-is; user can choose in the form)
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
