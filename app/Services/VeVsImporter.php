<?php

namespace App\Services;

use App\Jobs\UpsertBookingFromVeVs;
use App\Models\Booking;
use Illuminate\Support\Facades\Log;
use Throwable;

class VeVsImporter
{
    public function __construct(
        protected VeVsApi $api
    ) {}

    /**
     * Import a VEVS reservation by reference and return the local Booking.
     * If already present locally, returns it unchanged.
     */
    public function importByRef(string $ref): Booking
    {
        $ref = strtoupper(trim($ref));

        // If it's already here, just return it.
        $existing = Booking::with('customer')->where('reference', $ref)->first();
        if ($existing) {
            return $existing;
        }

        // Fetch one row from VEVS
        $row = $this->api->reservationByRef($ref);
        if (!$row || !is_array($row)) {
            abort(404, 'Booking not found in VEVS.');
        }

        // Upsert via your existing job (sync: fast & simple for landing flow)
        try {
            // If you prefer queue: dispatch() and then requery the DB.
            UpsertBookingFromVeVs::dispatchSync($row);
        } catch (Throwable $e) {
            Log::error('[VeVsImporter] Upsert failed', [
                'ref'   => $ref,
                'error' => $e->getMessage(),
            ]);
            abort(502, 'Could not import booking from VEVS.');
        }

        $booking = Booking::with('customer')->where('reference', $ref)->first();
        if (!$booking) {
            // Defensive fallback if mapper didn’t set the reference as expected.
            abort(500, 'Booking import completed but record not found.');
        }

        return $booking;
    }
}
