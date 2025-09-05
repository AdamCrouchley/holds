<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\JobStatus;
use App\Filament\Resources\JobResource\Pages;
use App\Models\Flow;
use App\Models\Job;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class JobResource extends Resource
{
    protected static ?string $model = Job::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationLabel = 'Jobs';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Flow & Status')
                ->schema([
                    Select::make('flow_id')
                        ->label('Flow')
                        ->helperText('Pick the template of rules this Job should follow. The Hold value will prefill from this Flow.')
                        ->relationship('flow', 'name') // MUST match Job::flow()
                        ->searchable()
                        ->preload()
                        ->reactive()
                        ->afterStateUpdated(function ($state, Set $set) {
                            // Copy Flow hold (cents) into Job.hold_amount_cents (shown in dollars in the UI).
                            $cents = (int) (Flow::find($state)?->hold_amount_cents ?? 0);
                            $set('hold_amount_cents', number_format($cents / 100, 2, '.', ''));
                        })
                        ->required(),

                    Select::make('status')
                        ->label('Status')
                        ->helperText('Lifecycle of this Job (e.g. pending → active → captured/released).')
                        ->options(collect(JobStatus::cases())->mapWithKeys(
                            fn ($c) => [$c->value => $c->value]
                        )->all())
                        ->required(),

                    TextInput::make('external_reference')
                        ->label('Booking reference')
                        ->helperText('Your booking number/reference used outside this system.')
                        ->maxLength(120),
                ])
                ->columns(2),

            Section::make('Customer')
                ->schema([
                    TextInput::make('customer_name')
                        ->label('Customer name')
                        ->helperText('Primary contact for this booking.'),

                    TextInput::make('customer_email')
                        ->label('Email')
                        ->helperText('Used for receipts and notifications.')
                        ->email(),

                    TextInput::make('customer_phone')
                        ->label('Phone')
                        ->helperText('Optional, for urgent contact if required.'),
                ])
                ->columns(2),

            Section::make('Billing address')
                ->description('Stored as structured JSON on this Job.')
                ->schema([
                    Group::make()
                        ->statePath('billing_address')
                        ->schema([
                            Grid::make(2)->schema([
                                TextInput::make('line1')
                                    ->label('Address line 1')
                                    ->helperText('e.g., 123 Example Street'),
                                TextInput::make('line2')
                                    ->label('Address line 2')
                                    ->helperText('Unit / Apartment / Suite (optional)'),
                            ]),
                            Grid::make(3)->schema([
                                TextInput::make('city')->label('City')->helperText('Town or city.'),
                                TextInput::make('region')->label('Region/State')->helperText('Optional.'),
                                TextInput::make('postcode')->label('Postcode')->helperText('ZIP / Postcode.'),
                            ]),
                            TextInput::make('country')
                                ->label('Country')
                                ->default('New Zealand')
                                ->helperText('Full country name is fine.'),
                        ])
                        ->columnSpanFull(),
                ]),

            Section::make('Dates')
                ->schema([
                    DateTimePicker::make('start_at')
                        ->label('Start at')
                        ->helperText('Pickup or hold start date/time.'),

                    DateTimePicker::make('end_at')
                        ->label('End at')
                        ->helperText('Planned return date/time.'),
                ])
                ->columns(3),

            // Financials — dollars in UI, cents in DB; includes Paid/Remaining placeholders
            Section::make('Financials')
                ->schema([
                    // Charge (editable)
                    TextInput::make('charge_amount_cents')
                        ->label('Charge Amount (NZD)')
                        ->prefix('NZ$')
                        ->numeric()
                        ->required()
                        // Show dollars in the UI
                        ->formatStateUsing(fn ($state) => $state !== null ? number_format(((int) $state) / 100, 2, '.', '') : null)
                        // Save cents to DB
                        ->dehydrateStateUsing(function ($state) {
                            if ($state === null || $state === '') {
                                return null;
                            }
                            return (int) round(((float) $state) * 100);
                        })
                        ->helperText('Remainder of the booking fee to charge (in dollars). Stored as cents.'),

                    // Hold (read-only; prefilled from Flow)
                    TextInput::make('hold_amount_cents')
                        ->label('Hold (NZD) — from Flow')
                        ->prefix('NZ$')
                        ->helperText('Read-only: prefilled from the selected Flow template for this Job.')
                        ->disabled()
                        // Show dollars in the UI
                        ->formatStateUsing(fn ($state) => $state === null ? '0.00' : number_format(((int) $state) / 100, 2, '.', ''))
                        // Keep dehydrating so initial value persists in DB
                        ->dehydrateStateUsing(fn ($state) => $state === null ? null : (int) round(((float) $state) * 100)),

                    // Paid to date (placeholder)
                    Placeholder::make('paid_amount')
                        ->label('Paid to date')
                        ->content(fn (?Job $record) =>
                            $record ? ('NZ$ ' . number_format(((int) ($record->paid_amount_cents ?? 0)) / 100, 2)) : '—'
                        ),

                    // Remaining (placeholder)
                    Placeholder::make('remaining_amount')
                        ->label('Remaining')
                        ->content(fn (?Job $record) =>
                            $record ? ('NZ$ ' . number_format(((int) ($record->remaining_amount_cents ?? 0)) / 100, 2)) : '—'
                        ),
                ])
                ->columns(2),

            Section::make('Payment (Admin only)')
                ->description('Managed by the system during authorise/capture and webhooks.')
                ->schema([
                    TextInput::make('psp')
                        ->label('Provider')
                        ->default('stripe')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Admin only — set automatically.'),
                    TextInput::make('psp_authorization_id')
                        ->label('Authorization ID')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Admin only — from payment gateway.'),
                    TextInput::make('psp_payment_method_id')
                        ->label('Payment Method ID')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Admin only — from payment gateway.'),
                    TextInput::make('psp_customer_id')
                        ->label('Customer ID')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Admin only — from payment gateway.'),
                ])
                ->collapsed(),

            Section::make('Metadata (Admin only)')
                ->schema([
                    KeyValue::make('meta')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Admin only — diagnostic metadata.'),
                    KeyValue::make('comms_log')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Admin only — system communication log (timestamps, message IDs).'),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('flow.name')
                    ->label('Flow')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'active',
                        'success' => 'captured',
                        'gray'    => 'released',
                        'danger'  => 'failed',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('external_reference')
                    ->label('Booking')
                    ->searchable(),

                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable(),

                Tables\Columns\TextColumn::make('charge_amount_cents')
                    ->label('Charge (NZD)')
                    ->formatStateUsing(fn ($state) => number_format(((int) ($state ?? 0)) / 100, 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('hold_amount_cents')
                    ->label('Hold (NZD)')
                    ->formatStateUsing(fn ($state) => number_format(((int) ($state ?? 0)) / 100, 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->since()
                    ->label('Updated'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(JobStatus::cases())->mapWithKeys(
                        fn ($c) => [$c->value => $c->value]
                    )->all()),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            JobResource\RelationManagers\EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListJobs::route('/'),
            'create' => Pages\CreateJob::route('/create'),
            'edit'   => Pages\EditJob::route('/{record}/edit'),
            'view'   => Pages\ViewJob::route('/{record}'),
        ];
    }
}
