<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use App\Models\Job;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class ExternalSyncService
{
    public function __construct(
        private readonly VeVsApi $api, // adjust if your API class is named differently
    ) {}

    /**
     * Fetch a booking by external reference, validate, and upsert it.
     * Returns ['ok' => bool, 'booking_id' => int]
     */
    public function syncByReference(string $reference, ?Job $job = null): array
    {
        Log::withContext(['reference' => $reference, 'job_id' => $job?->id]);
        Log::info('syncByReference: start');

        try {
            // 1) Call external API
            $payload = $this->api->reservationByReference($reference);

            if (empty($payload)) {
                throw new \RuntimeException('External API returned no data for reference: ' . $reference);
            }

            // 2) Validate the key fields you rely on
            $v = Validator::make($payload, [
                // Tweak these to match your payload
                'id'                => 'required',
                'external_reference'=> 'nullable|string|max:120',
                'customer.name'     => 'nullable|string|max:200',
                'customer.email'    => 'nullable|email|max:200',
                // add more fields you need when saving
            ]);

            if ($v->fails()) {
                Log::warning('syncByReference: validation failed', ['errors' => $v->errors()->toArray()]);
                throw new \InvalidArgumentException('Validation failed: ' . $v->errors()->first());
            }

            // 3) Map payload → DB fields (adjust as needed)
            $externalRef = $payload['external_reference'] ?? $reference; // fallback to provided ref
            $attrs = [
                'external_reference' => $externalRef,
                'customer_name'      => Arr::get($payload, 'customer.name'),
                'customer_email'     => Arr::get($payload, 'customer.email'),
                'customer_phone'     => Arr::get($payload, 'customer.phone'),
                // dates, amounts etc. — convert to your schema
                // 'start_at' => Carbon::parse($payload['starts_at']),
                // 'end_at'   => Carbon::parse($payload['ends_at']),
            ];

            // 4) Persist atomically
            $booking = DB::transaction(function () use ($externalRef, $attrs, $job) {
                // Upsert booking
                $booking = Booking::updateOrCreate(
                    ['external_reference' => $externalRef],
                    $attrs,
                );

                // Associate to current job (optional)
                if ($job) {
                    $job->booking()->associate($booking);
                    $job->external_reference = $externalRef; // keep in sync if you like
                    $job->save();
                }

                return $booking;
            });

            Log::info('syncByReference: success', ['booking_id' => $booking->id]);

            return ['ok' => true, 'booking_id' => $booking->id];
        } catch (Throwable $e) {
            // Log with context AND throw — Filament action will show message
            Log::error('syncByReference: exception', [
                'message' => $e->getMessage(),
                'class'   => $e::class,
            ]);
            throw $e;
        }
    }
}
