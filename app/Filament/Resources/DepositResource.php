<?php

namespace App\Filament\Resources;

use App\Models\Deposit;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Resources\DepositResource\Pages;

class DepositResource extends Resource
{
    protected static ?string $model = Deposit::class;
    protected static ?string $slug  = 'deposits';
    protected static ?string $navigationGroup = 'Payments';
    protected static ?string $navigationIcon  = 'heroicon-o-credit-card';

    public static function form(Form $form): Form
    {
        return $form->schema([
            // Adjust field names to match your columns
            Forms\Components\TextInput::make('amount')
                ->numeric()
                ->prefix('NZD') // change if needed
                ->required(),

            Forms\Components\Select::make('status')
                ->options([
                    'pending'   => 'Pending',
                    'paid'      => 'Paid',
                    'failed'    => 'Failed',
                    'refunded'  => 'Refunded',
                ])
                ->searchable()
                ->required(),

            Forms\Components\TextInput::make('reference')
                ->maxLength(191),

            // Uncomment if you have these relationships/columns
            // Forms\Components\Select::make('booking_id')
            //     ->relationship('booking', 'id')
            //     ->searchable()
            //     ->preload(),
            //
            // Forms\Components\Select::make('customer_id')
            //     ->relationship('customer', 'name') // or email
            //     ->searchable()
            //     ->preload(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label('Ref')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    // If you store cents, transform it: ->formatStateUsing(fn ($v) => number_format($v / 100, 2))
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'paid'     => 'success',
                        'pending'  => 'warning',
                        'failed'   => 'danger',
                        'refunded' => 'info',
                        default    => 'gray',
                    })
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->since(), // shows “2 hours ago”
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending'  => 'Pending',
                        'paid'     => 'Paid',
                        'failed'   => 'Failed',
                        'refunded' => 'Refunded',
                    ]),
                Tables\Filters\Filter::make('created_between')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(), // remove if you don’t want deletes
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDeposits::route('/'),
            'create' => Pages\CreateDeposit::route('/create'),
            'view'   => Pages\ViewDeposit::route('/{record}'),
            'edit'   => Pages\EditDeposit::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return (string) static::getModel()::count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }
}
