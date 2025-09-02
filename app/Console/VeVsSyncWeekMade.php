<?php

namespace App\Console\Commands;

use App\Jobs\UpsertBookingFromVeVs;
use App\Services\VeVsApi;
use Illuminate\Console\Command;
use Throwable;

class VeVsSyncWeekMade extends Command
{
    /**
     * Usage:
     *  php artisan vevs:sync-week-made          # queue jobs (default)
     *  php artisan vevs:sync-week-made --now    # run synchronously (no queue worker needed)
     */
    protected $signature = 'vevs:sync-week-made {--now : Run synchronously without queue}';

    protected $description = 'Sync reservations made this week from the VEVS JSON API and upsert Customers & Bookings.';

    public function handle(VeVsApi $api): int
    {
        $this->info('Syncing VEVS reservations (week made)...');

        try {
            $list = $api->reservationsWeekMade();
        } catch (Throwable $e) {
            $this->error('Failed to fetch from VEVS: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (!is_array($list) || count($list) === 0) {
            $this->warn('No reservations returned.');
            return self::SUCCESS;
        }

        $syncNow = (bool) $this->option('now');
        $count   = 0;

        foreach ($list as $row) {
            $count++;

            if ($syncNow) {
                // Run immediately in this process
                (new UpsertBookingFromVeVs($row))->handle();
                $this->line("• processed #{$count}");
            } else {
                // Queue for background processing
                UpsertBookingFromVeVs::dispatch($row);
            }
        }

        if ($syncNow) {
            $this->info("Processed {$count} reservations (sync).");
        } else {
            $this->info("Queued {$count} reservations for processing.");
            $this->line('Tip: run `php artisan queue:work` to process the queue.');
        }

        return self::SUCCESS;
    }
}
