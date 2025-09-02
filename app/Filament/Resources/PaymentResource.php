<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon  = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = 'Payments';
    protected static ?string $navigationLabel = 'Payments';
    protected static ?string $modelLabel      = 'Payment';
    protected static ?string $pluralModelLabel = 'Payments';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('booking_id')
                ->relationship('booking', 'reference')
                ->searchable()
                ->required(),

            Forms\Components\Select::make('type')
                ->options([
                    'deposit'  => 'Deposit',
                    'balance'  => 'Balance',
                    'refund'   => 'Refund',
                    'other'    => 'Other',
                ])
                ->required(),

            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->prefix('NZD')
                ->helperText('Amount stored in cents (e.g. 12345 = $123.45).')
                ->required(),

            Forms\Components\Select::make('status')
                ->options([
                    'pending'   => 'Pending',
                    'succeeded' => 'Succeeded',
                    'failed'    => 'Failed',
                ])
                ->required(),

            Forms\Components\TextInput::make('provider')->maxLength(50),
            Forms\Components\TextInput::make('provider_ref')->label('Provider Ref')->maxLength(191),
            Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('booking.reference')
                    ->label('Booking')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->money('nzd')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'succeeded' => 'Succeeded',
                        'failed'    => 'Failed',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'deposit'  => 'Deposit',
                        'balance'  => 'Balance',
                        'refund'   => 'Refund',
                        'other'    => 'Other',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            // Add relation managers here if needed
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPayments::route('/'),
            'create' => Pages\CreatePayment::route('/create'),
            'view'   => Pages\ViewPayment::route('/{record}'),
            'edit'   => Pages\EditPayment::route('/{record}/edit'),
        ];
    }
}
