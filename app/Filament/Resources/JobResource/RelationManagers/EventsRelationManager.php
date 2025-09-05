<?php

namespace App\Filament\Resources\JobResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';
    protected static ?string $recordTitleAttribute = 'type';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('type')
                    ->options([
                        'info' => 'Info',
                        'warning' => 'Warning',
                        'error' => 'Error',
                        'status_change' => 'Status Change',
                    ])
                    ->required(),

                Forms\Components\Textarea::make('message')
                    ->rows(3)
                    ->columnSpanFull(),

                // If you store structured data on the event:
                Forms\Components\Textarea::make('meta')
                    ->label('Meta (JSON)')
                    ->rows(3)
                    ->helperText('Optional extra data for this event.')
                    ->columnSpanFull()
                    ->nullable(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->colors([
                        'info' => 'primary',
                        'warning' => 'warning',
                        'error' => 'danger',
                        'status_change' => 'success',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('message')
                    ->limit(80)
                    ->toggleable(), // allow hide/show

                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->since()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
