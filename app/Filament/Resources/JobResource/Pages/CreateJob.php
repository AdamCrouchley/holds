<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobResource\Pages;

use App\Filament\Resources\JobResource;
use App\Models\Booking;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateJob extends CreateRecord
{
    protected static string $resource = JobResource::class;

    protected ?string $heading = 'Create Job';

    /**
     * Normalize/augment payload before create.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Ensure currency default
        if (empty($data['currency'])) {
            $data['currency'] = 'NZD';
        }

        // If your form lets users type dollars, convert to cents safely.
        // Supports either `amount` (dollars) or direct `amount_cents`.
        if (isset($data['amount']) && ! isset($data['amount_cents'])) {
            // Accept strings like "1,234.56"
            $normalized = preg_replace('/[^\d.]/', '', (string) $data['amount']);
            $data['amount_cents'] = (int) round(((float) $normalized) * 100);
            unset($data['amount']); // not a DB column
        }

        // Stamp a reference if missing; prefer the booking reference if available.
        if (empty($data['reference'])) {
            $data['reference'] = $this->deriveReference($data);
        }

        // Soft-guard status default
        if (empty($data['status'])) {
            $data['status'] = 'draft';
        }

        return $data;
    }

    /**
     * After record is created, you can trigger any side-effects.
     */
    protected function afterCreate(): void
    {
        // Optional toast
        Notification::make()
            ->title('Job created')
            ->body('Your job has been created successfully.')
            ->success()
            ->send();
    }

    /**
     * Where to redirect after creation (back to edit by default).
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }

    /**
     * Create the Eloquent record (keeps default, but you can hook if needed).
     */
    protected function handleRecordCreation(array $data): Model
    {
        // Let the Resource handle model + fillable rules.
        return static::getModel()::create($data);
    }

    /**
     * Build a sensible reference:
     * - If booking_id is provided and a booking exists with a reference, use that
     * - Else generate a unique short code.
     */
    private function deriveReference(array $data): string
    {
        // Prefer an existing booking reference
        if (! empty($data['booking_id'])) {
            try {
                $booking = Booking::query()
                    ->select(['id', 'reference'])
                    ->whereKey($data['booking_id'])
                    ->first();

                if ($booking && ! empty($booking->reference)) {
                    return (string) $booking->reference;
                }
            } catch (\Throwable $e) {
                // Swallow – fall back to generated reference
            }
        }

        // Fall back: JOB-YYYYMMDD-RAND
        $date = now()->format('Ymd');
        $rand = Str::upper(Str::random(6));

        return "JOB-{$date}-{$rand}";
    }
}
