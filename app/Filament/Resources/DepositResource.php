<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DepositResource\Pages;
use App\Models\Deposit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DepositResource extends Resource
{
    protected static ?string $model = Deposit::class;

    protected static ?string $navigationIcon  = 'heroicon-o-credit-card';
    protected static ?string $navigationGroup = 'Payments';
    protected static ?string $navigationLabel = 'Deposits';
    protected static ?string $modelLabel      = 'Deposit';
    protected static ?string $pluralModelLabel = 'Deposits';

    public static function form(Form $form): Form
    {
        // Optional: If deposits are system-created, you can keep this minimal or make fields disabled.
        return $form->schema([
            Forms\Components\Select::make('booking_id')
                ->relationship('booking', 'reference')
                ->searchable()
                ->required(),

            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->prefix('NZD')
                ->required(),

            Forms\Components\Select::make('status')
                ->options([
                    'authorised' => 'Authorised',
                    'captured'   => 'Captured',
                    'voided'     => 'Voided',
                    'failed'     => 'Failed',
                ])
                ->required()
                ->default('authorised'),

            Forms\Components\DateTimePicker::make('authorised_at'),
            Forms\Components\DateTimePicker::make('expires_at'),

            // Common gateway metadata (adjust to your schema)
            Forms\Components\TextInput::make('provider')->maxLength(50),
            Forms\Components\TextInput::make('provider_ref')->label('Provider Ref')->maxLength(191),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('authorised_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('booking.reference')->label('Booking')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('amount')->money('nzd')->sortable(),
                Tables\Columns\TextColumn::make('status')->badge()->sortable(),
                Tables\Columns\TextColumn::make('authorised_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'authorised' => 'Authorised',
                        'captured'   => 'Captured',
                        'voided'     => 'Voided',
                        'failed'     => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('capture')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'authorised')
                    ->action(fn ($record) => app(\App\Http\Controllers\DepositController::class)->capture(request(), $record)),

                Tables\Actions\Action::make('void')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->status === 'authorised')
                    ->action(fn ($record) => app(\App\Http\Controllers\DepositController::class)->void($record)),

                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
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
            // e.g. Relation managers for events/payments if you add them later
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDeposits::route('/'),
            'create' => Pages\CreateDeposit::route('/create'),
            'edit'   => Pages\EditDeposit::route('/{record}/edit'),
            'view'   => Pages\ViewDeposit::route('/{record}'),
        ];
    }
}
