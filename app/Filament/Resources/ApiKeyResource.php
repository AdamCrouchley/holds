<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApiKeyResource\Pages;
use App\Models\ApiKey;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Textarea;

use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\Grid as InfoGrid;

use Filament\Resources\Resource;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TagsColumn;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiKeyResource extends Resource
{
    protected static ?string $model = ApiKey::class;

    // Sidebar
    protected static ?string $navigationGroup = 'Developer';
    protected static ?string $navigationLabel = 'API Keys';
    protected static ?string $navigationIcon  = 'heroicon-o-key';
    protected static ?int    $navigationSort  = 90;

    // URL slug for the resource
    protected static ?string $slug = 'api-keys';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        Grid::make(12)->schema([
                            TextInput::make('name')
                                ->label('Key name')
                                ->placeholder('e.g. Server-to-Server Integration')
                                ->required()
                                ->maxLength(120)
                                ->columnSpan(6),

                            TextInput::make('key')
                                ->label('Secret')
                                ->helperText('Store this securely. You can generate a new value.')
                                ->password() // hides while typing
                                ->revealable()
                                ->unique(ignoreRecord: true) // enforce uniqueness
                                ->suffixAction(
                                    Forms\Components\Actions\Action::make('generate')
                                        ->label('Generate')
                                        ->icon('heroicon-m-sparkles')
                                        ->action(function (Forms\Set $set) {
                                            // 64 hex chars (256-bit)
                                            $set('key', bin2hex(random_bytes(32)));
                                        })
                                )
                                ->columnSpan(6),
                        ]),

                        TagsInput::make('scopes')
                            ->helperText('Optional access scopes, e.g. "payments:read", "deposits:write".')
                            ->placeholder('Type a scope and press enter')
                            ->columnSpanFull(),

                        Toggle::make('active')
                            ->label('Active')
                            ->default(true),

                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull()
                            ->hint('Optional notes for internal use'),
                    ])
                    ->columns(12),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('key')
                    ->label('Secret')
                    ->formatStateUsing(function (?string $state) {
                        if (! $state) return '—';
                        // mask: show first 6 and last 4
                        $len = strlen($state);
                        return substr($state, 0, 6) . str_repeat('•', max(0, $len - 10)) . substr($state, -4);
                    })
                    ->copyable()
                    ->copyMessage('Secret copied')
                    ->toggleable(),

                TagsColumn::make('scopes')
                    ->limit(3)
                    ->separator(', ')
                    ->toggleable(),

                IconColumn::make('active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->dateTime('Y-m-d H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active'),
                Tables\Filters\Filter::make('created_today')
                    ->label('Created today')
                    ->query(fn (Builder $q) => $q->whereDate('created_at', now()->toDateString())),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('regenerate')
                    ->label('Regenerate')
                    ->icon('heroicon-m-arrow-path')
                    ->requiresConfirmation()
                    ->color('warning')
                    ->action(function (ApiKey $record) {
                        $record->update(['key' => bin2hex(random_bytes(32))]);
                        Notification::make()
                            ->title('API key regenerated')
                            ->body('The secret has been updated.')
                            ->success()
                            ->send();
                    }),
                DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
{
    return $infolist
        ->schema([
            InfoSection::make('Details')
                ->schema([
                    InfoGrid::make(2)->schema([
                        TextEntry::make('name'),
                        TextEntry::make('active')->badge()
                            ->formatStateUsing(fn (bool $state) => $state ? 'Active' : 'Inactive')
                            ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                    ]),

                    TextEntry::make('key')
                        ->label('Secret')
                        ->copyable()
                        ->copyMessage('Secret copied')
                        ->formatStateUsing(fn (?string $state) => $state ?: '—'),

                    TextEntry::make('scopes')
                        ->badge()
                        ->separator(', ')
                        ->visible(fn (\App\Models\ApiKey $record) => filled($record->scopes)),

                    TextEntry::make('notes')
                        ->prose()
                        ->markdown()
                        ->visible(fn (\App\Models\ApiKey $record) => filled($record->notes)),

                    TextEntry::make('created_at')->dateTime('Y-m-d H:i'),
                    TextEntry::make('updated_at')->dateTime('Y-m-d H:i'),
                ]),
        ]);
}


    public static function getRelations(): array
    {
        return [
            // e.g. RelationManagers\TokensRelationManager::class
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListApiKeys::route('/'),
            'create' => Pages\CreateApiKey::route('/create'),
            'view'   => Pages\ViewApiKey::route('/{record}'),
            'edit'   => Pages\EditApiKey::route('/{record}/edit'),
        ];
    }

    /** Optional: improve global search */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'key'];
    }
}
