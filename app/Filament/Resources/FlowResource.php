<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FlowResource\Pages;
use App\Models\Flow;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlowResource extends Resource
{
    protected static ?string $model = Flow::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Flows';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basics')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Flow name')
                    ->helperText('A short label admins will pick when creating Jobs (e.g., “Standard Car Rental Hold”).')
                    ->required()
                    ->maxLength(150),

                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->helperText('Internal notes so staff know when to use this flow. Shown only to admins.'),

                Forms\Components\TagsInput::make('tags')
                    ->helperText('Optional tags for filtering/reporting (e.g., “christchurch”, “long-term”).'),
            ])->columns(2),

            // --- Financials: dollars in UI, cents in DB ---
            Forms\Components\Section::make('Financials')->schema([
                Forms\Components\TextInput::make('hold_amount_cents')
                    ->label('Hold (NZD)')
                    ->helperText('Default bond/hold for Jobs that use this Flow. Enter dollars; we store cents.')
                    ->prefix('NZ$')
                    ->numeric()
                    ->step('0.01')
                    ->placeholder('0.00')
                    ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn ($state) => $state === null ? null : (int) round(((float) $state) * 100))
                    ->required()
                    ->default(0),

                Forms\Components\TextInput::make('authorized_amount_cents')
                    ->label('Authorized (NZD)')
                    ->helperText('Optional default pre-authorized amount if different from Hold. Enter dollars; we store cents.')
                    ->prefix('NZ$')
                    ->numeric()
                    ->step('0.01')
                    ->placeholder('0.00')
                    ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn ($state) => $state === null ? null : (int) round(((float) $state) * 100)),

                Forms\Components\TextInput::make('captured_amount_cents')
                    ->label('Captured (NZD)')
                    ->helperText('Optional default capture amount (e.g., common charges). Enter dollars; we store cents.')
                    ->prefix('NZ$')
                    ->numeric()
                    ->step('0.01')
                    ->placeholder('0.00')
                    ->formatStateUsing(fn ($state) => $state === null ? null : $state / 100)
                    ->dehydrateStateUsing(fn ($state) => $state === null ? null : (int) round(((float) $state) * 100)),
            ])->columns(3),

            // --- Behaviour ---
            Forms\Components\Section::make('Behaviour')->schema([
                Forms\Components\TextInput::make('auto_renew_days')
                    ->label('Auto-renew (days)')
                    ->helperText('How often to refresh the card authorisation so the hold doesn’t expire.')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(7),

                Forms\Components\TextInput::make('auto_release_days')
                    ->label('Auto-release after return (days)')
                    ->helperText('If no capture is taken, release the hold this many days after the return date.')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(3),

                Forms\Components\Toggle::make('allow_partial_capture')
                    ->label('Allow partial capture')
                    ->helperText('Permit capturing less than the full hold (e.g., only fuel or minor damage).')
                    ->default(true),

                Forms\Components\Toggle::make('auto_capture_on_damage')
                    ->label('Auto-capture on damage')
                    ->helperText('If a damage amount is added to a Job, automatically capture up to that amount.')
                    ->default(true),

                Forms\Components\Toggle::make('auto_cancel_if_no_capture')
                    ->label('Auto-cancel if no capture')
                    ->helperText('If nothing is captured within the window below, cancel the hold automatically.')
                    ->default(true),

                Forms\Components\TextInput::make('auto_cancel_after_days')
                    ->label('Auto-cancel after (days)')
                    ->helperText('Maximum days before cancelling the hold entirely if nothing is captured.')
                    ->numeric()
                    ->minValue(0)
                    ->required()
                    ->default(14),
            ])->columns(3),

            // --- Requirements & Comms ---
            Forms\Components\Section::make('Requirements & Comms')->schema([
                Forms\Components\KeyValue::make('required_fields')
                    ->keyLabel('Required field key')
                    ->valueLabel('Enabled')
                    ->helperText('List of required field names for Jobs using this Flow (e.g., customer_name, customer_email).'),

                Forms\Components\KeyValue::make('comms')
                    ->keyLabel('Event')
                    ->valueLabel('Template / action')
                    ->helperText('Communication mapping (e.g., on_create → email:hold_created).'),

                Forms\Components\KeyValue::make('webhooks')
                    ->keyLabel('Key')
                    ->valueLabel('Value / URL')
                    ->helperText('Optional outbound webhooks (e.g., captured → https://example.com/webhook).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                // Money columns displayed as dollars
                Tables\Columns\TextColumn::make('hold_amount_cents')
                    ->label('Hold (NZD)')
                    ->formatStateUsing(fn ($state) => number_format((($state ?? 0) / 100), 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('authorized_amount_cents')
                    ->label('Authorized (NZD)')
                    ->toggleable(isToggledHiddenByDefault: true) // hide by default; show if useful
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format(($state / 100), 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('captured_amount_cents')
                    ->label('Captured (NZD)')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format(($state / 100), 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('auto_renew_days')->label('Renew (d)')->sortable(),
                Tables\Columns\TextColumn::make('auto_release_days')->label('Release (d)')->sortable(),

                Tables\Columns\TagsColumn::make('tags'),

                Tables\Columns\TextColumn::make('jobs_count')
                    ->counts('jobs')
                    ->label('Jobs'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->since()
                    ->label('Updated'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            FlowResource\RelationManagers\JobsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListFlows::route('/'),
            'create' => Pages\CreateFlow::route('/create'),
            'edit'   => Pages\EditFlow::route('/{record}/edit'),
            'view'   => Pages\ViewFlow::route('/{record}'),
        ];
    }
}
