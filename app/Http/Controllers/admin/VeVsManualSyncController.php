<?php
// app/Http/Controllers/Admin/VeVsManualSyncController.php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\VeVsApi;
use App\Jobs\UpsertBookingFromVeVs;

class VeVsManualSyncController extends Controller
{
    public function show(string $ref, VeVsApi $api)
    {
        $payload = $api->reservationByRef($ref);
        UpsertBookingFromVeVs::dispatchSync($payload); // do it immediately
        return back()->with('status', "Reservation {$ref} synced.");
    }
}
