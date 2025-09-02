<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Http\Controllers\PaymentController;
use App\Models\Booking;
use App\Models\Payment;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    /** Eager-load relations and ensure we have a portal token for the accessor. */
    protected function resolveRecord($key): Model
    {
        /** @var Booking $record */
        $record = static::getModel()::query()
            ->with(['customer', 'payments'])
            ->findOrFail($key);

        if (method_exists($record, 'ensurePortalToken')) {
            $record->ensurePortalToken();
        }

        return $record;
    }

    /* ============================== Helpers =============================== */

    private function currency(): string
    {
        return strtolower($this->record->currency ?? 'nzd');
    }

    /**
     * Persist a ledger Payment row if the table/columns exist.
     * Normalises "purpose" into legacy `type` when needed.
     *
     * @param array<string,mixed> $attrs
     */
    private function writePaymentLedger(array $attrs): void
    {
        if (!Schema::hasTable('payments')) return;

        $p = new Payment();

        if (Schema::hasColumn('payments', 'booking_id'))                $p->booking_id = $this->record->id;
        if (Schema::hasColumn('payments', 'booking_reference'))         $p->booking_reference = $this->record->reference;
        if (Schema::hasColumn('payments', 'amount') && isset($attrs['amount']))      $p->amount = (int) $attrs['amount'];
        if (Schema::hasColumn('payments', 'currency') && isset($attrs['currency']))  $p->currency = (string) $attrs['currency'];
        if (Schema::hasColumn('payments', 'status') && isset($attrs['status']))      $p->status = (string) $attrs['status'];
        if (Schema::hasColumn('payments', 'note') && isset($attrs['note']))          $p->note = (string) $attrs['note'];
        if (Schema::hasColumn('payments', 'stripe_payment_intent_id') && isset($attrs['pi_id'])) {
            $p->stripe_payment_intent_id = (string) $attrs['pi_id'];
        }

        // purpose/type compatibility + normalisation
        $purposeCol = Schema::hasColumn('payments', 'purpose')
            ? 'purpose'
            : (Schema::hasColumn('payments', 'type') ? 'type' : null);

        if ($purposeCol && isset($attrs['purpose'])) {
            $raw = (string) $attrs['purpose'];

            if ($purposeCol === 'type') {
                $map = [
                    'booking_deposit'  => 'deposit',
                    'booking_balance'  => 'balance',
                    'bond_hold'        => 'hold',
                    'bond_void'        => 'refund',
                    'bond_capture'     => 'post_hire_charge',
                    'post_hire_charge' => 'post_hire_charge',
                    'refund'           => 'refund',
                ];
                $p->{$purposeCol} = $map[$raw] ?? $raw;
            } else {
                $p->{$purposeCol} = $raw;
            }
        }

        $p->save();
    }

    private function idempotency(string $prefix): string
    {
        return $prefix . '_' . (string) Str::uuid();
    }

    /** Latest “hold” Payment on this booking that’s still capturable. */
    private function currentHold(?Booking $booking = null): ?Payment
    {
        $booking ??= $this->record;

        return $booking->payments()
            ->where(function ($q) {
                $q->where('purpose', 'hold')->orWhere('type', 'hold'); // legacy compatibility
            })
            ->whereIn('status', ['requires_capture', 'processing', 'succeeded']) // generous
            ->latest('id')
            ->first();
    }

    /* =========================== Header actions =========================== */

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Edit Booking')
                ->icon('heroicon-o-pencil-square'),

            // --- New: Open payment page (uses accessor ->portal_url) ---
            Actions\Action::make('open_payment_page')
                ->label('Open payment page')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->url(fn () => $this->record->portal_url, shouldOpenInNewTab: true)
                ->button(),

            // --- New: Copy link button (clipboard + toast fallback) ---
            Actions\Action::make('copy_payment_link')
                ->label('Copy link')
                ->icon('heroicon-o-clipboard-document')
                ->color('gray')
                ->button()
                ->extraAttributes(fn () => [
                    'x-data' => '{}',
                    'x-on:click' =>
                        "navigator.clipboard.writeText(" .
                        Js::from($this->record->portal_url) .
                        ").then(() => { window.filament?.notifications?.push?.({ title: 'Link copied', status: 'success' }) ?? alert('Link copied to clipboard'); })",
                ]),

            // ----- Payments group (unchanged functionality) -----
            Actions\ActionGroup::make([
                Actions\Action::make('captureBond')
                    ->label('Capture bond')
                    ->icon('heroicon-o-credit-card')
                    ->color('danger')
                    ->disabled(fn (Booking $r) => !$this->currentHold($r))
                    ->tooltip('Capture the current pre-authorised bond')
                    ->action(function (Booking $record) {
                        $hold = $this->currentHold($record);
                        if (!$hold) {
                            $this->notify('warning', 'No authorised bond to capture.');
                            return;
                        }
                        // PaymentController::captureHold(Request $request, Payment $payment)
                        app(PaymentController::class)->captureHold(request(), $hold);
                    }),

                Actions\Action::make('voidBond')
                    ->label('Void bond hold')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->disabled(fn (Booking $r) => !$this->currentHold($r))
                    ->tooltip('Void the current pre-authorisation')
                    ->action(function (Booking $record) {
                        $hold = $this->currentHold($record);
                        if (!$hold) {
                            $this->notify('warning', 'No authorised bond to void.');
                            return;
                        }
                        // PaymentController::releaseHold(Payment $payment)
                        app(PaymentController::class)->releaseHold($hold);
                    }),

                Actions\Action::make('postHireCharge')
                    ->label('Post-hire charge')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->form([
                        \Filament\Forms\Components\TextInput::make('amount_nzd')
                            ->label('Amount (NZD)')
                            ->numeric()->step('0.01')->required(),
                        \Filament\Forms\Components\TextInput::make('reason')
                            ->label('Reason (e.g., parking ticket)')
                            ->maxLength(200)->required(),
                    ])
                    ->tooltip('Requires a saved customer card (ask the renter to tick “Save this card” on the pay page).')
                    ->action(function (array $data, Booking $record) {
                        $amountCents = (int) round(((float) $data['amount_nzd']) * 100);

                        // PaymentController::postHireCharge(Booking $booking, Request $req)
                        request()->merge([
                            'amount_cents' => $amountCents,
                            'description'  => (string) $data['reason'],
                        ]);

                        app(PaymentController::class)->postHireCharge($record, request());
                    }),
            ])
                ->label('Payments')
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->size('sm'),
        ];
    }
}
