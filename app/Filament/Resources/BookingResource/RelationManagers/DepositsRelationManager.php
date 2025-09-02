<?php

namespace App\Filament\Resources\BookingResource\RelationManagers;

use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DepositsRelationManager extends RelationManager
{
    /** The relationship name on Booking.php */
    protected static string $relationship = 'deposits';

    /** Filament v3 signature */
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return 'Deposits';
    }

    public static function getModelLabel(): string
    {
        return 'deposit';
    }

    public static function getPluralModelLabel(): string
    {
        return 'deposits';
    }

    /** No create/edit form – this section is read-only in the admin */
    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                // amount is stored in cents; render as dollars
                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->state(fn ($record) => (int) ($record->amount ?? 0))
                    ->money('nzd', divideBy: 100)
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'success' => ['succeeded', 'captured', 'paid', 'completed'],
                        'warning' => ['pending', 'authorized', 'hold'],
                        'danger'  => ['failed', 'canceled', 'cancelled', 'declined'],
                    ])
                    ->formatStateUsing(fn ($state) => $state ? strtolower($state) : '—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
            ])
            // read-only table
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
