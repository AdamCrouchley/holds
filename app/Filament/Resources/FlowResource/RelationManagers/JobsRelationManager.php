<?php

namespace App\Filament\Resources\FlowResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class JobsRelationManager extends RelationManager
{
    protected static string $relationship = 'jobs';
    protected static ?string $recordTitleAttribute = 'external_reference';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->sortable(),

                Tables\Columns\TextColumn::make('external_reference')
                    ->label('Booking')
                    ->searchable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->searchable(),

                Tables\Columns\TextColumn::make('hold_amount_cents')
                    ->label('Hold (cents)')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('external_reference')
                    ->label('Booking Ref'),

                Forms\Components\TextInput::make('customer_name'),

                Forms\Components\TextInput::make('customer_email')
                    ->email(),

                Forms\Components\TextInput::make('customer_phone'),

                Forms\Components\TextInput::make('vehicle_reference'),

                Forms\Components\TextInput::make('hold_amount_cents')
                    ->numeric(),
            ])
            ->columns(2);
    }
}
