<?php
// app/Jobs/SyncDreamDrivesBookings.php
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use App\Services\DreamDrivesClient;

class SyncDreamDrivesBookings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public \Carbon\CarbonInterface $from,
        public \Carbon\CarbonInterface $to,
        public ?int $requestedByUserId = null
    ) {}

    public function handle(DreamDrivesClient $client): void
    {
        $pages = $client->fetchBookings($this->from, $this->to);

        $count = 0;
        foreach ($pages as $page) {
            foreach ($page['data'] as $remote) {
                // Upsert into your local bookings table as you see fit
                // Example:
                \App\Models\Booking::updateOrCreate(
                    ['external_source' => 'dreamdrives', 'external_ref' => $remote['id']],
                    [
                        'reference'      => $remote['reference'] ?? $remote['id'],
                        'customer_id'    => $client->mapOrCreateCustomer($remote['customer']),
                        'vehicle'        => $remote['vehicle'] ?? null,
                        'start_at'       => $remote['start_at'],
                        'end_at'         => $remote['end_at'],
                        'total_amount'   => $remote['total_amount'] ?? 0,
                        'deposit_amount' => $remote['deposit_amount'] ?? 0,
                        'hold_amount'    => $remote['bond_amount'] ?? 0,
                        'currency'       => $remote['currency'] ?? 'NZD',
                        'status'         => $remote['status'] ?? 'pending',
                        'meta'           => $remote,
                    ]
                );
                $count++;
            }
        }

        Log::info("Dream Drives sync complete", [
            'from' => (string)$this->from,
            'to'   => (string)$this->to,
            'count'=> $count,
            'by'   => $this->requestedByUserId,
        ]);
    }
}

