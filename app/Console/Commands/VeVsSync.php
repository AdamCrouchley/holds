<?php

namespace App\Console\Commands;

use App\Jobs\UpsertBookingFromVeVs;
use App\Services\VeVsApi;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

class VeVsSync extends Command
{
    /**
     * Examples:
     *  php artisan vevs:sync                          # defaults to weekMade
     *  php artisan vevs:sync --mode=weekPickup
     *  php artisan vevs:sync --mode=ref --ref=CK1602584016
     *  php artisan vevs:sync --mode=weekMade --limit=50 --queue
     *  php artisan vevs:sync --mode=ref --ref=CK1602584016 --dump
     */
    protected $signature = 'vevs:sync
        {--mode=weekMade : weekMade|weekPickup|ref}
        {--ref= : Booking reference (when mode=ref)}
        {--limit=0 : Max rows to process (0 = no limit)}
        {--queue : Dispatch jobs to the queue (default executes synchronously)}
        {--dump : Dump the first fetched row for debugging (keys + JSON)}';

    protected $description = 'Sync reservations from VEVS into the local DB';

    public function handle(): int
    {
        /** @var VeVsApi $api */
        $api = app(VeVsApi::class);

        $mode  = (string) $this->option('mode');
        $ref   = (string) ($this->option('ref') ?? '');
        $limit = (int) ($this->option('limit') ?? 0);
        $queue = (bool) $this->option('queue');
        $dump  = (bool) $this->option('dump');

        if (!in_array($mode, ['weekMade', 'weekPickup', 'ref'], true)) {
            $this->error('Invalid --mode. Use one of: weekMade, weekPickup, ref');
            return Command::INVALID;
        }

        if ($mode === 'ref' && $ref === '') {
            $this->error('When --mode=ref you must also pass --ref=BOOKING_REFERENCE');
            return Command::INVALID;
        }

        // -------- Fetch from VEVS --------
        $rows = [];
        try {
            if ($mode === 'weekMade') {
                $rows = (array) $api->reservationsWeekMade();
            } elseif ($mode === 'weekPickup') {
                $rows = (array) $api->reservationsWeekPickup();
            } else { // mode=ref
                $rowOrRows = $api->reservationByRef($ref); // <-- corrected name
                if (!$rowOrRows) {
                    $this->warn("No reservation found for reference: {$ref}");
                    return Command::SUCCESS;
                }
                // Accept a single assoc row or an array of rows
                $rows = Arr::isAssoc($rowOrRows) ? [$rowOrRows] : (array) $rowOrRows;
            }
        } catch (Throwable $e) {
            $this->error('Error fetching from VEVS: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if (empty($rows)) {
            $this->info('No rows returned from VEVS.');
            return Command::SUCCESS;
        }

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Optional debug
        if ($dump) {
            $first = (array) ($rows[0] ?? []);
            $this->line('--- First row (keys) ---');
            $this->line(implode(', ', array_keys($first)));
            $this->newLine();
            $this->line('--- First row (json) ---');
            $this->line(json_encode($first, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
        }

        // -------- Process rows --------
        $total   = count($rows);
        $success = 0;
        $failed  = 0;

        $this->info("Processing {$total} row(s) — " . ($queue ? 'queueing jobs' : 'running synchronously') . '…');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $r) {
            try {
                // Try common reference keys for logging
                $refForLog = (string) (
                    $r['ReferenceID']    // VEVS typical
                    ?? $r['Reference']
                    ?? $r['reference']
                    ?? $r['BookingNumber']
                    ?? $r['ref_id']
                    ?? Str::upper(Str::random(8))
                );

                if ($queue) {
                    UpsertBookingFromVeVs::dispatch($r);
                } else {
                    // run immediately
                    (new UpsertBookingFromVeVs($r))->handle();
                }

                $success++;
            } catch (Throwable $e) {
                $failed++;
                $this->output->writeln('');
                $this->warn("Failed to process row (ref: {$refForLog}): " . $e->getMessage());
            } finally {
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Success: {$success}, Failed: {$failed}.");

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
