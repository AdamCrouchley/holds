<?php

// app/Jobs/SyncDreamDrivesWeekMade.php
namespace App\Jobs;

use App\Jobs\UpsertBookingFromVeVs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncDreamDrivesWeekMade implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        if (!config('services.dreamdrives.enabled')) {
            Log::info('[DreamDrives] feed disabled');
            return;
        }

        $url = config('services.dreamdrives.feed_url');
        $brand = config('services.dreamdrives.brand', 'dreamdrives');

        $resp = Http::timeout(20)->retry(2, 500)->get($url);
        if ($resp->failed()) {
            Log::error('[DreamDrives] fetch failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            return;
        }

        $items = $resp->json() ?? [];
        if (!is_array($items)) {
            Log::warning('[DreamDrives] unexpected payload', ['sample' => $items]);
            return;
        }

        foreach ($items as $raw) {
            // Normalize payload minimally; Upsert job can translate fields
            dispatch(new UpsertBookingFromVeVs($raw + [
                '_brand'  => $brand,
                '_source' => 'vevs',          // consistent with your Jimny path
                '_feed'   => 'week_made',     // provenance
            ]))->onQueue('imports');
        }

        Log::info('[DreamDrives] enqueued upserts', ['count' => count($items)]);
    }
}

