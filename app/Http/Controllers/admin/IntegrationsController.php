<?php

// app/Http/Controllers/Admin/IntegrationsController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Jobs\SyncDreamDrivesBookings;

class IntegrationsController extends Controller
{
    public function syncDreamDrives(Request $request)
    {
        // Optional date range; defaults to “upcoming week”
        $data = $request->validate([
            'from' => ['nullable','date'],
            'to'   => ['nullable','date','after_or_equal:from'],
        ]);

        $from = $data['from'] ?? now()->startOfDay();
        $to   = $data['to']   ?? now()->addWeek()->endOfDay();

        SyncDreamDrivesBookings::dispatch($from, $to, auth()->id());

        return back()->with('status', "Dream Drives sync queued for {$from->toDateString()} → {$to->toDateString()}");
    }
}
