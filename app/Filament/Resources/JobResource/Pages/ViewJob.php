<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobResource\Pages;

use App\Filament\Resources\JobResource;
use App\Http\Controllers\JobController;
use App\Models\Job;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class ViewJob extends ViewRecord
{
    protected static string $resource = JobResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 1) Email payment request
            Actions\Action::make('emailPaymentRequest')
                ->label('Email payment request')
                ->icon('heroicon-o-envelope')
                ->color('warning')
                ->requiresConfirmation()
                ->action('sendPaymentRequest'),

            // 2) Copy pay link (modal with Copy button)
            Actions\Action::make('copyPayLink')
                ->label('Copy pay link')
                ->icon('heroicon-o-clipboard')
                ->color('gray')
                ->modalHeading('Copy pay link')
                ->modalSubmitActionLabel('Close')
                ->form([
                    Forms\Components\TextInput::make('pay_link')
                        ->formatStateUsing(fn () => $this->signedPayUrl($this->getRecord()))
                        ->readOnly()
                        ->columnSpanFull()
                        ->extraAttributes([
                            'x-data' => '{ copied: false }',
                            'x-ref'  => 'pay',
                            '@focus' => '$refs.pay.select()',
                        ])
                        ->suffixActions([
                            Forms\Components\Actions\Action::make('copy')
                                ->icon('heroicon-o-clipboard-document')
                                ->label('Copy')
                                ->extraAttributes([
                                    'type' => 'button',
                                    'x-on:click' =>
                                        "navigator.clipboard.writeText(\$refs.pay.value).then(() => {
                                            copied = true;
                                            window.dispatchEvent(new CustomEvent('filament-notify', {
                                                detail: { status: 'success', message: 'Pay link copied' }
                                            }));
                                        }).catch(() => {
                                            window.dispatchEvent(new CustomEvent('filament-notify', {
                                                detail: { status: 'danger', message: 'Copy failed' }
                                            }));
                                        });",
                                ]),
                        ])
                        ->helperText('Click Copy, or select and press ⌘C / CTRL+C'),
                ]),

            // 3) Open pay page (new tab)
            Actions\Action::make('openPayPage')
                ->label('Open pay page')
                ->icon('heroicon-o-link')
                ->color('success')
                ->url(fn () => $this->signedPayUrl($this->getRecord()))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Invoked by the "Email payment request" action.
     * Uses the STATIC wrapper and surfaces the returned message.
     * Expects: JobController::emailPaymentRequestStatic(Job $job, array $payload): array{0: bool, 1: string}
     */
    public function sendPaymentRequest(): void
    {
        /** @var Job $job */
        $job = $this->getRecord();

        try {
            // Prefill or override as desired
            $payload = []; // e.g. ['to' => $job->customer_email]

            [$ok, $msg] = JobController::emailPaymentRequestStatic($job, $payload);

            if ($ok) {
                Notification::make()
                    ->title('Payment request sent')
                    ->body($msg ?: 'An email with the payment link has been sent to the customer.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Failed to send email')
                    ->body($msg ?: 'Mailer reported a problem. Check logs for details.')
                    ->danger()
                    ->send();
            }
        } catch (\Throwable $e) {
            Log::error('Email payment request failed from ViewJob', [
                'job_id' => $job->getKey(),
                'err'    => $e->getMessage(),
            ]);

            Notification::make()
                ->title('Failed to send email')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Always return a SIGNED URL (includes ?expires=&signature=).
     */
    protected function signedPayUrl(Job $job): string
    {
        return URL::signedRoute('portal.pay.show.job', ['job' => $job->getKey()]);
    }
}
