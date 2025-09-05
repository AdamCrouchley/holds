<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Grid as InfoGrid;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon   = 'heroicon-o-user';
    protected static ?string $navigationLabel  = 'Customers';
    protected static ?string $modelLabel       = 'Customer';
    protected static ?string $pluralModelLabel = 'Customers';
    protected static ?string $navigationGroup  = 'Billing';
    protected static ?int    $navigationSort   = 10;

    /**
     * Create/Edit form (minimal fields for payments)
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Customer details')
                ->columns(12)
                ->schema([
                    Forms\Components\TextInput::make('first_name')
                        ->label('First name')
                        ->required()
                        ->maxLength(100)
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('last_name')
                        ->label('Last name')
                        ->required()
                        ->maxLength(100)
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('company')
                        ->label('Company')
                        ->maxLength(150)
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->unique(ignoreRecord: true)
                        ->required()
                        ->maxLength(191)
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('phone')
                        ->label('Phone')
                        ->tel()
                        ->maxLength(50)
                        ->columnSpan(4),

                    Forms\Components\Textarea::make('notes')
                        ->label('Notes')
                        ->rows(3)
                        ->columnSpan(12),
                ]),
        ]);
    }

    /**
     * List table (lean columns)
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('first_name')
                    ->label('First')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('last_name')
                    ->label('Last')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('phone')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('company')
                    ->label('Company')
                    ->searchable()
                    ->toggleable(),

                // Payments-related (hidden by default)
                TextColumn::make('stripe_customer_id')
                    ->label('Stripe Customer')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('default_payment_method_id')
                    ->label('Default PM')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([])
            ->actions([
                Tables\Actions\ViewAction::make(),

                Tables\Actions\Action::make('add_card')
                    ->label('Add/Update Card')
                    ->icon('heroicon-m-credit-card')
                    ->url(fn (Customer $record) => route('customers.pm.add', $record))
                    ->openUrlInNewTab(),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('Create your first customer to get started.')
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    /**
     * View page infolist (payments-focused)
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfoSection::make('Customer')
                ->schema([
                    InfoGrid::make(3)->schema([
                        TextEntry::make('first_name')->label('First name'),
                        TextEntry::make('last_name')->label('Last name'),
                        TextEntry::make('company')->label('Company'),
                    ]),
                    InfoGrid::make(3)->schema([
                        TextEntry::make('email')->icon('heroicon-m-envelope')->copyable(),
                        TextEntry::make('phone')->icon('heroicon-m-phone')->copyable(),
                        TextEntry::make('created_at')->label('Created')->dateTime('Y-m-d H:i'),
                    ]),
                ]),

            InfoSection::make('Payments')
                ->collapsed()
                ->schema([
                    InfoGrid::make(2)->schema([
                        TextEntry::make('stripe_customer_id')
                            ->label('Stripe Customer ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('default_payment_method_id')
                            ->label('Default Payment Method')
                            ->placeholder('—')
                            ->copyable(),
                    ]),
                ]),

            InfoSection::make('Notes')
                ->collapsed()
                ->schema([
                    TextEntry::make('notes')->label('Notes')->columnSpanFull(),
                ]),
        ]);
    }

    /**
     * Relations (none for now)
     */
public static function getRelations(): array
{
    return [
        \App\Filament\Resources\CustomerResource\RelationManagers\BookingsRelationManager::class,
        // ... any other relation managers
    ];
}

    /**
     * Pages
     */
    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'view'   => Pages\ViewCustomer::route('/{record}'),
            'edit'   => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }

    /**
     * Global search configuration
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['first_name', 'last_name', 'email', 'phone', 'company'];
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            return (string) static::getModel()::count();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
