<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobResource\Pages;

use App\Filament\Resources\JobResource;
use App\Models\Job;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewJob extends ViewRecord
{
    protected static string $resource = JobResource::class;

    /**
     * Header actions shown at the top-right of the View Job page.
     * This preserves your existing "Email payment request" action
     * and adds a new button that links to the signed pay page.
     */
    protected function getHeaderActions(): array
    {
        return [
            // Your existing button (adjust if you wired it differently)
            Actions\Action::make('emailPaymentRequest')
                ->label('Email payment request')
                ->icon('heroicon-o-envelope')
                ->color('warning')
                ->button()
                ->action('sendPaymentRequest'), // if you use a URL instead, swap to ->url(...)

            // New: Open the customer pay page (signed) in a new tab
            Actions\Action::make('openPayPage')
                ->label('Open pay page')
                ->icon('heroicon-o-credit-card')
                ->color('success')
                ->button()
                ->url(fn (Job $record) => $record->payUrl())
                ->openUrlInNewTab(),
        ];
    }

    /**
     * If your existing "Email payment request" uses an action method,
     * keep (or replace) this stub with your real implementation.
     */
    public function sendPaymentRequest(): void
    {
        // Implement your email logic or route it to a service/action.
        // e.g., app(EmailPaymentRequestAction::class)->execute($this->record);
        $this->notify('success', 'Payment request email queued.');
    }
}
