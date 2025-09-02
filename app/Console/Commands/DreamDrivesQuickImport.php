<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DreamDrivesFeed;
use App\Actions\Importers\UpsertDreamDrives;
use Carbon\Carbon;
use Throwable;

class DreamDrivesQuickImport extends Command
{
    protected $signature = 'dd:sync
        {--only=both : recent|future|both}
        {--months=12 : months ahead for the future window}';

    protected $description = 'Import Dream Drives: recent (last 14 days made) and/or future (next N months pickup).';

    public function handle(DreamDrivesFeed $feed, UpsertDreamDrives $upsert): int
    {
        $tz     = config('services.dreamdrives.tz', 'Pacific/Auckland');
        $only   = strtolower($this->option('only') ?? 'both');
        $months = (int)($this->option('months') ?? 12);

        $today = Carbon::now($tz)->startOfDay();

        $totalWeeks = 0; $imported = 0; $skipped = 0; $errors = 0;

        // ---------------- Recent: last 14 days (MADE) ----------------
        if ($only === 'recent' || $only === 'both') {
            $fromRecent = $today->copy()->subDays(14)->startOfWeek();
            $toRecent   = $today->copy()->endOfWeek();

            $this->info("Recent window (MADE): {$fromRecent->toDateString()} → {$toRecent->toDateString()}");
            for ($d = $fromRecent->copy(); $d->lte($toRecent); $d->addWeek()) {
                $totalWeeks++;
                $this->line('• Week of '.$d->toDateString().' (made)');
                try {
                    $rows = $feed->reservationWeekMade($d);
                    [$i,$s,$e] = $this->processRows($rows, $upsert, $d);
                    $imported += $i; $skipped += $s; $errors += $e;
                } catch (Throwable $ex) {
                    $errors++;
                    $this->error('  ! error fetching made feed: '.$ex->getMessage());
                }
            }
        }

        // ---------------- Future: next N months (PICKUP) ----------------
        if ($only === 'future' || $only === 'both') {
            $fromFuture = $today->copy()->startOfWeek();
            $toFuture   = $today->copy()->addMonths(max(0, $months))->endOfWeek();

            $this->info("Future window (PICKUP): {$fromFuture->toDateString()} → {$toFuture->toDateString()} (+{$months} mo)");
            for ($d = $fromFuture->copy(); $d->lte($toFuture); $d->addWeek()) {
                $totalWeeks++;
                $this->line('• Week of '.$d->toDateString().' (pickup)');
                try {
                    $rows = $feed->reservationWeekPickup($d); // may 404 if not supported
                    [$i,$s,$e] = $this->processRows($rows, $upsert, $d);
                    $imported += $i; $skipped += $s; $errors += $e;
                } catch (Throwable $ex) {
                    $this->warn('  - pickup endpoint not available or failed: '.$ex->getMessage());
                    break; // don’t spam the same error for every week
                }
            }
        }

        $this->info("Done: {$totalWeeks} week(s), {$imported} imported, {$skipped} skipped, {$errors} errors.");
        return $errors ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Normalize rows and import them with the upserter.
     * Returns [imported, skipped, errors].
     */
    private function processRows(mixed $rows, UpsertDreamDrives $upsert, Carbon $week): array
    {
        $imported = 0; $skipped = 0; $errors = 0;

        if (!is_array($rows)) {
            $this->warn('  - feed returned non-array, skipping week '.$week->toDateString());
            return [0, 1, 0];
        }

        // If provider wraps results, unwrap common keys
        foreach (['reservations','items','data','rows','results'] as $k) {
            if (array_key_exists($k, $rows) && is_array($rows[$k])) {
                $rows = $rows[$k];
                break;
            }
        }

        foreach ($rows as $i => $row) {
            if (is_string($row)) {
                $decoded = json_decode($row, true);
                $row = is_array($decoded) ? $decoded : null;
            }
            if (!is_array($row)) {
                $skipped++;
                $this->warn("  - skipped non-object row #{$i} for week ".$week->toDateString());
                continue;
            }

            try {
                $upsert->importReservation($row);
                $imported++;
            } catch (Throwable $e) {
                $errors++;
                $ref = $this->refFromRow($row) ?: '?';
                $this->error("  ! error on row ref {$ref}: ".$e->getMessage());
            }
        }

        return [$imported, $skipped, $errors];
    }

    private function refFromRow(array $row): ?string
    {
        foreach (['Reference','ResNo','ref_id','uuid','Id','ReservationId','ReservationID','id'] as $k) {
            if (!empty($row[$k])) return (string)$row[$k];
        }
        return null;
    }
}
