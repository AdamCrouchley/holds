<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingResource\Pages;
use App\Filament\Resources\BookingResource\RelationManagers\PaymentsRelationManager;
use App\Models\Booking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon  = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationGroup = 'Bookings';
    protected static ?string $navigationLabel = 'Bookings';

    /**
     * Keep the query simple and predictable.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'payments']);
    }

    public static function form(Form $form): Form
    {
        $formatAsDollars   = fn (?int $cents) => number_format(((int) ($cents ?? 0)) / 100, 2, '.', '');
        $dehydrateAsCents  = fn ($state) => (int) round(((float) ($state ?? 0)) * 100);

        return $form->schema([
            Forms\Components\Section::make('Booking')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('reference')
                        ->label('Reference')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(4),

                    // NEW: Brand selector so you can flip between Jimny / Dream Drives
                    Forms\Components\Select::make('brand')
                        ->label('Brand')
                        ->options([
                            'jimny'        => 'Jimny',
                            'dreamdrives'  => 'Dream Drives',
                        ])
                        ->required()
                        ->native(false)
                        ->columnSpan(4),

                    Forms\Components\Select::make('customer_id')
                        ->label('Customer')
                        ->relationship('customer', 'email')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->columnSpan(4),

                    Forms\Components\DateTimePicker::make('start_at')
                        ->label('Start')
                        ->seconds(false)
                        ->required()
                        ->columnSpan(6),

                    Forms\Components\DateTimePicker::make('end_at')
                        ->label('End')
                        ->seconds(false)
                        ->required()
                        ->after('start_at')
                        ->columnSpan(6),
                ]),

            Forms\Components\Section::make('Money')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('total_amount')
                        ->label('Total (NZD)')
                        ->numeric()
                        ->step('0.01')
                        ->formatStateUsing(fn ($state) => $formatAsDollars($state))
                        ->dehydrateStateUsing(fn ($state) => $dehydrateAsCents($state))
                        ->required()
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('deposit_amount')
                        ->label('Deposit Required (NZD)')
                        ->numeric()
                        ->step('0.01')
                        ->formatStateUsing(fn ($state) => $formatAsDollars($state))
                        ->dehydrateStateUsing(fn ($state) => $dehydrateAsCents($state))
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('hold_amount')
                        ->label('Bond Hold (NZD)')
                        ->helperText('Pre-authorised hold amount.')
                        ->numeric()
                        ->step('0.01')
                        ->formatStateUsing(fn ($state) => $formatAsDollars($state))
                        ->dehydrateStateUsing(fn ($state) => $dehydrateAsCents($state))
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('currency')
                        ->label('Currency')
                        ->default('NZD')
                        ->maxLength(8)
                        ->columnSpan(3),

                    Forms\Components\Select::make('status')
                        ->label('Status')
                        ->options([
                            'pending'   => 'Pending',
                            'paid'      => 'Paid',
                            'cancelled' => 'Cancelled',
                        ])
                        ->default('pending')
                        ->required()
                        ->columnSpan(3),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                // === Brand badge (left of Ref) ===
                Tables\Columns\TextColumn::make('brand_badge')
                    ->label('')
                    ->state(function (Booking $r) {
                        $brand = strtolower((string) ($r->brand ?? $r->source_system ?? ''));
                        return $brand === 'jimny' ? 'J' : 'D';
                    })
                    ->badge()
                    ->alignCenter()
                    ->colors([
                        'success' => fn ($state) => $state === 'J', // Jimny = green
                        'info'    => fn ($state) => $state === 'D', // Dream Drives = blue
                    ])
                    ->tooltip(function (Booking $r) {
                        $brand = strtolower((string) ($r->brand ?? $r->source_system ?? ''));
                        return $brand === 'jimny' ? 'Jimny' : 'Dream Drives';
                    })
                    ->sortable(false)
                    ->toggleable(isToggledHiddenByDefault: false),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Ref')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer_label')
                    ->label('Customer')
                    ->state(fn (Booking $r) => $r->customer?->name
                        ?? trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: '—')
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->whereHas('customer', function (Builder $q) use ($search) {
                            $q->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name',  'like', "%{$search}%")
                                ->orWhere('email',      'like', "%{$search}%");
                        });
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_at')
                    ->label('Start')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('end_at')
                    ->label('End')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Booked')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                // Money
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->state(fn (Booking $r) => (int) ($r->total_amount ?? 0))
                    ->money('nzd', divideBy: 100)
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount_paid')
                    ->label('Paid So Far')
                    ->state(fn (Booking $r) => (int) ($r->payments
                        ? $r->payments
                            ->whereIn('status', ['succeeded', 'paid', 'captured', 'completed'])
                            ->sum('amount')
                        : 0))
                    ->money('nzd', divideBy: 100)
                    ->sortable(),

                Tables\Columns\TextColumn::make('balance_due')
                    ->label('Balance Due')
                    ->state(function (Booking $r) {
                        $total = (int) ($r->total_amount ?? 0);
                        $paid  = (int) ($r->payments
                            ? $r->payments
                                ->whereIn('status', ['succeeded', 'paid', 'captured', 'completed'])
                                ->sum('amount')
                            : 0);
                        return max(0, $total - $paid);
                    })
                    ->money('nzd', divideBy: 100)
                    ->sortable(),

                Tables\Columns\TextColumn::make('hold_amount')
                    ->label('Bond Hold')
                    ->state(fn (Booking $r) => (int) ($r->hold_amount ?? 0))
                    ->money('nzd', divideBy: 100)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('bond_status')
                    ->label('Bond Status')
                    ->state(function (Booking $r) {
                        $hasAuth = filled($r->stripe_bond_pi_id ?? null);
                        $capt    = !empty($r->bond_captured_at);
                        $rel     = !empty($r->bond_released_at);
                        return $rel ? 'Released' : ($capt ? 'Captured' : ($hasAuth ? 'Authorised' : '—'));
                    })
                    ->badge()
                    ->colors([
                        'success' => fn ($state) => $state === 'Captured',
                        'warning' => fn ($state) => $state === 'Authorised',
                        'gray'    => fn ($state) => $state === '—',
                        'info'    => fn ($state) => $state === 'Released',
                    ])
                    ->toggleable(),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('Status')
                    ->colors([
                        'warning' => 'pending',
                        'success' => 'paid',
                        'danger'  => 'cancelled',
                    ])
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending'   => 'Pending',
                        'paid'      => 'Paid',
                        'cancelled' => 'Cancelled',
                    ]),
                Tables\Filters\Filter::make('upcoming')
                    ->label('Upcoming')
                    ->query(fn ($q) => $q->where('start_at', '>=', now())),
                Tables\Filters\Filter::make('past')
                    ->label('Past')
                    ->query(fn ($q) => $q->where('end_at', '<', now())),
                Tables\Filters\Filter::make('needs_bond')
                    ->label('Needs Bond Hold')
                    ->query(fn ($q) => $q
                        ->whereNotNull('hold_amount')
                        ->where('hold_amount', '>', 0)
                        ->whereNull('stripe_bond_pi_id')),
                Tables\Filters\Filter::make('bond_authorised')
                    ->label('Bond Authorised (uncaptured)')
                    ->query(fn ($q) => $q
                        ->whereNotNull('stripe_bond_pi_id')
                        ->whereNull('bond_captured_at')
                        ->whereNull('bond_released_at')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('open_pay_page')
                    ->label('Open Pay Page')
                    ->icon('heroicon-o-credit-card')
                    ->url(fn (Booking $r) => route('portal.pay', $r->id))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Booking')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')->label('Reference'),

                    TextEntry::make('status')
                        ->badge()
                        ->colors([
                            'warning' => 'pending',
                            'success' => 'paid',
                            'danger'  => 'cancelled',
                        ])
                        ->label('Status'),

                    TextEntry::make('start_at')->label('Start')->dateTime('Y-m-d H:i'),
                    TextEntry::make('end_at')->label('End')->dateTime('Y-m-d H:i'),

                    TextEntry::make('customer_label')
                        ->label('Customer')
                        ->state(fn (Booking $r) => $r->customer?->name
                            ?? trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''))
                            ?: '—'),
                ]),

            Section::make('Money')
                ->columns(4)
                ->schema([
                    TextEntry::make('total_amount')
                        ->label('Total Amount')
                        ->state(fn (Booking $r) => (int) ($r->total_amount ?? 0))
                        ->money('nzd', divideBy: 100),

                    TextEntry::make('amount_paid')
                        ->label('Paid So Far')
                        ->state(fn (Booking $r) => (int) ($r->payments
                            ? $r->payments->whereIn('status', ['succeeded', 'paid', 'captured', 'completed'])->sum('amount')
                            : 0))
                        ->money('nzd', divideBy: 100),

                    TextEntry::make('balance_due')
                        ->label('Balance Due')
                        ->state(function (Booking $r) {
                            $total = (int) ($r->total_amount ?? 0);
                            $paid  = (int) ($r->payments
                                ? $r->payments->whereIn('status', ['succeeded', 'paid', 'captured', 'completed'])->sum('amount')
                                : 0);
                            return max(0, $total - $paid);
                        })
                        ->money('nzd', divideBy: 100),

                    TextEntry::make('hold_amount')
                        ->label('Bond Hold')
                        ->state(fn (Booking $r) => (int) ($r->hold_amount ?? 0))
                        ->money('nzd', divideBy: 100),

                    TextEntry::make('bond_status')
                        ->label('Bond Status')
                        ->state(function (Booking $r) {
                            $hasAuth = filled($r->stripe_bond_pi_id ?? null);
                            $capt    = !empty($r->bond_captured_at);
                            $rel     = !empty($r->bond_released_at);
                            return $rel ? 'Released' : ($capt ? 'Captured' : ($hasAuth ? 'Authorised' : '—'));
                        }),
                    TextEntry::make('currency')->label('Currency')->default('NZD'),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class, // keep payments only
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'view'   => Pages\ViewBooking::route('/{record}'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
