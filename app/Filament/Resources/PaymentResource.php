<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Models\Payment;
use App\Models\Job;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon   = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup  = 'Payments';
    protected static ?string $navigationLabel  = 'Payments';
    protected static ?string $modelLabel       = 'Payment';
    protected static ?string $pluralModelLabel = 'Payments';

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Link payments to Jobs only (ignore Bookings)
            Select::make('job_id')
                ->label('Job (by reference)')
                ->relationship('job', 'external_reference')
                ->nullable()
                ->searchable()
                ->searchDebounce(300)
                ->getSearchResultsUsing(fn (string $search) =>
                    Job::query()
                        ->when($search !== '', function ($q) use ($search) {
                            $q->where('external_reference', 'like', "%{$search}%");
                            // allow quick lookup by numeric id as well
                            if (ctype_digit($search)) {
                                $q->orWhere('id', (int) $search);
                            }
                        })
                        ->orderByDesc('id')
                        ->limit(50)
                        ->pluck('external_reference', 'id')
                        ->all()
                )
                ->getOptionLabelUsing(fn ($value) =>
                    optional(Job::find($value))->external_reference ?? ('#' . $value)
                )
                ->helperText('Type a job reference (e.g. ZT1756941934) or a Job ID.'),

            // Optional free-text reference (kept for audit/consistency if you use it across tables)
            TextInput::make('reference')
                ->label('Reference (optional)')
                ->helperText('Free text reference if you want to store one on the payment.')
                ->nullable(),

            // Type / Status / Mechanism
            Select::make('type')
                ->label('Type')
                ->options([
                    'booking_deposit' => 'Booking Deposit',
                    'booking_balance' => 'Booking Balance',
                    'post_hire'       => 'Post-hire Charge',
                    'bond_hold'       => 'Bond Hold',
                    'bond_capture'    => 'Bond Capture',
                    'refund'          => 'Refund',
                    'other'           => 'Other',
                ])
                ->required(),

            Select::make('status')
                ->label('Status')
                ->options([
                    'pending'   => 'Pending',
                    'succeeded' => 'Succeeded',
                    'failed'    => 'Failed',
                    'canceled'  => 'Canceled',
                ])
                ->required(),

            Select::make('mechanism')
                ->label('Mechanism')
                ->options([
                    'card'          => 'Card',
                    'bank_transfer' => 'Bank Transfer',
                    'cash'          => 'Cash',
                    'other'         => 'Other',
                ])
                ->nullable(),

            // Money (shown in dollars, stored in cents)
            TextInput::make('amount_cents')
                ->label('Amount')
                ->numeric()
                ->prefix('NZ$')
                ->helperText('Enter amount in dollars (e.g. 123.45). Will be stored as cents.')
                ->required()
                ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100)) // dollars → cents
                ->formatStateUsing(fn ($state) => $state !== null ? number_format(((int) $state) / 100, 2, '.', '') : null), // cents → dollars

            Select::make('currency')
                ->label('Currency')
                ->options([
                    'NZD' => 'NZD',
                    'AUD' => 'AUD',
                    'USD' => 'USD',
                    'EUR' => 'EUR',
                    'GBP' => 'GBP',
                ])
                ->default('NZD')
                ->required(),

            // PSP / Stripe references (optional)
            TextInput::make('stripe_payment_intent_id')->label('Stripe Payment Intent ID')->maxLength(191)->nullable(),
            TextInput::make('stripe_payment_method_id')->label('Stripe Payment Method ID')->maxLength(191)->nullable(),
            TextInput::make('stripe_charge_id')->label('Stripe Charge ID')->maxLength(191)->nullable(),

            // Extra details (JSON as key/value)
            KeyValue::make('details')
                ->label('Details (JSON)')
                ->addButtonLabel('Add detail')
                ->reorderable()
                ->nullable()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('job.external_reference')
                    ->label('Job')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount_cents')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, $record) =>
                        ($record->currency ?? 'NZD') . ' ' . number_format(((int) $state) / 100, 2)
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'succeeded',
                        'danger'  => 'failed',
                        'gray'    => 'canceled',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
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
                        'canceled'  => 'Canceled',
                    ]),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'booking_deposit' => 'Booking Deposit',
                        'booking_balance' => 'Booking Balance',
                        'post_hire'       => 'Post-hire Charge',
                        'bond_hold'       => 'Bond Hold',
                        'bond_capture'    => 'Bond Capture',
                        'refund'          => 'Refund',
                        'other'           => 'Other',
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
