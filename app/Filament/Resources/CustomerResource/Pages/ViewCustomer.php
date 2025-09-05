<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use App\Mail\CustomerPortalLinkMail;
use App\Models\Customer;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),

            // --- Token-based link (works with login consume + intended redirect)
            Actions\Action::make('sendPortalLink')
                ->label('Portal Link')
                ->icon('heroicon-m-paper-airplane')
                ->color('primary')
                ->modalHeading('Send customer portal link')
                ->form([
                    Forms\Components\Radio::make('link_type')
                        ->label('Link type')
                        ->options([
                            'dashboard' => 'Customer Dashboard',
                            'pay'       => 'Pay Deposit / Balance',
                        ])
                        ->inline()
                        ->default('dashboard')
                        ->required(),

                    Forms\Components\TextInput::make('email')
                        ->label('Send to')
                        ->email()
                        ->required()
                        ->default(fn (self $page) => (string)($page->record->email ?? '')),

                    Forms\Components\Textarea::make('note')
                        ->label('Optional message')
                        ->rows(2)
                        ->maxLength(500)
                        ->placeholder('We’ve set up your customer portal link…'),
                ])
                ->action(function (array $data, Customer $record): void {
                    // Ensure a long-lived portal token exists
                    if (method_exists($record, 'ensurePortalToken')) {
                        $record->ensurePortalToken();
                    } elseif (blank($record->portal_token)) {
                        $record->portal_token = Str::random(40);
                        $record->save();
                    }

                    // Work out intended destination
                    $intended = $data['link_type'] === 'pay'
                        ? (Route::has('portal.pay') ? route('portal.pay') : url('/'))
                        : (Route::has('portal.home') ? route('portal.home') : url('/'));

                    // Build the actual link via model helper if present; otherwise fall back to route with query params
                    if (method_exists($record, 'buildMagicLoginUrl')) {
                        $link = $record->buildMagicLoginUrl('portal.login.consume', ['intended' => $intended]);
                    } else {
                        // Fallback: send them to the consumer with query parameters
                        $token = $record->portal_token; // created above if blank
                        $link  = route('portal.login.consume', [
                            'token'   => $token,
                            'email'   => $record->email,
                            'intended'=> $intended,
                        ]);
                    }

                    // Email the link
                    if (!empty($data['email'])) {
                        Mail::to($data['email'])->send(
                            new CustomerPortalLinkMail($record, $link, (string)($data['note'] ?? ''))
                        );
                    }

                    // Show link for quick copy
                    Notification::make()
                        ->title('Portal link ready')
                        ->body($link)
                        ->success()
                        ->send();
                }),

            // --- Magic login link (session-based, via /p/login/consume or equivalent)
            Actions\Action::make('sendMagicLogin')
                ->label('Magic Login Link')
                ->icon('heroicon-m-link')
                ->color('gray')
                ->modalHeading('Send magic login link')
                ->form([
                    Forms\Components\TextInput::make('email')
                        ->label('Send to')
                        ->email()
                        ->required()
                        ->default(fn (self $page) => (string)($page->record->email ?? '')),
                    Forms\Components\Textarea::make('note')
                        ->label('Optional message')
                        ->rows(2)
                        ->maxLength(500)
                        ->placeholder('Use this one-time sign-in link to access your portal.'),
                ])
                ->action(function (array $data, Customer $record): void {
                    // Prefer model helper to build the link
                    if (method_exists($record, 'buildMagicLoginUrl')) {
                        $link = $record->buildMagicLoginUrl('portal.magic.consume');
                    } else {
                        // Fallback: generate a one-time token if helper exists
                        if (method_exists($record, 'issueLoginToken')) {
                            $raw = $record->issueLoginToken(); // sets hash + expiry internally
                            $link = route('portal.magic.consume', [
                                'token' => $raw,
                                'email' => $record->email,
                            ]);
                        } else {
                            // Absolute fallback: point to the magic-login request form
                            $link = Route::has('portal.magic.show') ? route('portal.magic.show') : url('/');
                        }
                    }

                    if (!empty($data['email'])) {
                        Mail::to($data['email'])->send(
                            new CustomerPortalLinkMail($record, $link, (string)($data['note'] ?? ''))
                        );
                    }

                    Notification::make()
                        ->title('Magic login link sent')
                        ->body($link)
                        ->success()
                        ->send();
                }),

            // --- Quick open (opens portal in new tab using magic login)
            Actions\Action::make('openPortal')
                ->label('Open Portal')
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color('gray')
                ->url(function (Customer $record) {
                    // Ensure we have a token if we need to fall back
                    if (blank($record->portal_token) && method_exists($record, 'ensurePortalToken')) {
                        $record->ensurePortalToken();
                    }

                    $intended = Route::has('portal.home') ? route('portal.home') : url('/');

                    if (method_exists($record, 'buildMagicLoginUrl')) {
                        return $record->buildMagicLoginUrl('portal.login.consume', ['intended' => $intended]);
                    }

                    // Fallback to consumer with query params if helper not available
                    if (!blank($record->portal_token) && Route::has('portal.login.consume')) {
                        return route('portal.login.consume', [
                            'token'    => $record->portal_token,
                            'email'    => $record->email,
                            'intended' => $intended,
                        ]);
                    }

                    return null;
                }, shouldOpenInNewTab: true)
                ->disabled(fn (Customer $record): bool => blank($record->portal_token) && ! method_exists($record, 'ensurePortalToken')),
        ];
    }
}
