<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\Booking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings'; // Customer::bookings()

    protected static ?string $title = 'Bookings';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static ?string $icon = 'heroicon-o-clipboard-document-check';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Booking')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('reference')
                        ->label('Reference')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(4),

                    // Nice calendar pickers for Start / End
                    Forms\Components\DateTimePicker::make('start_at')
                        ->label('Start')
                        ->native(false)                 // flatpickr calendar
                        ->seconds(false)
                        ->closeOnDateSelection(false)
                        ->required()
                        ->columnSpan(4),

                    Forms\Components\DateTimePicker::make('end_at')
                        ->label('End')
                        ->native(false)
                        ->seconds(false)
                        ->closeOnDateSelection(false)
                        ->required()
                        ->after('start_at')             // basic UX guard
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('total_amount')
                        ->label('Total (NZD)')
                        ->numeric()->step('0.01')
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) ($state ?? 0)) * 100))
                        ->formatStateUsing(fn (?int $cents) => number_format(((int) ($cents ?? 0)) / 100, 2))
                        ->columnSpan(3),

                    Forms\Components\TextInput::make('hold_amount')
                        ->label('Bond Hold (NZD)')
                        ->numeric()->step('0.01')
                        ->dehydrateStateUsing(fn ($state) => (int) round(((float) ($state ?? 0)) * 100))
                        ->formatStateUsing(fn (?int $cents) => number_format(((int) ($cents ?? 0)) / 100, 2))
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

                    Forms\Components\TextInput::make('brand')
                        ->label('Brand')
                        ->helperText('jimny or dreamdrives')
                        ->datalist(['jimny', 'dreamdrives'])
                        ->maxLength(32)
                        ->columnSpan(3),
                ]),
        ]);
    }

    public function table(Table $table): Table
{
    return $table
        ->recordTitleAttribute('reference')
        ->columns([
            Tables\Columns\TextColumn::make('reference')
                ->label('Ref')
                ->sortable()
                ->searchable()
                ->url(fn ($record) => route('filament.admin.resources.bookings.view', $record)) // 👈
                ->openUrlInNewTab(), // optional

            Tables\Columns\TextColumn::make('start_at')
                ->label('Start')
                ->dateTime('jS F Y, H:i')
                ->sortable(),

            Tables\Columns\TextColumn::make('end_at')
                ->label('End')
                ->dateTime('jS F Y, H:i')
                ->sortable(),

            Tables\Columns\BadgeColumn::make('status')
                ->label('Status')
                ->colors([
                    'warning' => 'pending',
                    'success' => 'paid',
                    'danger'  => 'cancelled',
                ])
                ->sortable(),
        ])
        ->headerActions([
            Tables\Actions\CreateAction::make(),
        ])
        ->actions([
            Tables\Actions\EditAction::make(),
            Tables\Actions\DeleteAction::make(),
        ]);
}

}
