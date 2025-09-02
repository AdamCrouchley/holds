<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DreamDrivesFeed;
use App\Actions\Importers\UpsertDreamDrives;
use Carbon\Carbon;
use Throwable;

class DreamDrivesImport extends Command
{
    protected $signature = 'dd:import
        {--from=2019-01-01 : start date (YYYY-MM-DD)}
        {--to= : end date (default: today + 12 months)}
        {--only= : made|pickup|both}';

    protected $description = 'Import Dream Drives reservations by week (past + future)';

    public function handle(DreamDrivesFeed $feed, UpsertDreamDrives $upserter): int
    {
        $from = Carbon::parse($this->option('from'))->startOfWeek();
        $to   = $this->option('to')
            ? Carbon::parse($this->option('to'))->endOfWeek()
            : now()->addMonths(12)->endOfWeek();
        $only = strtolower($this->option('only') ?: 'both');

        $this->info("Importing weeks from {$from->toDateString()} to {$to->toDateString()} ({$only})");

        $weeks = 0; $count = 0; $skipped = 0; $errors = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addWeek()) {
            $weeks++;
            $this->line("• Week of ".$day->toDateString());

            $process = function (array $rows) use ($upserter, $day, &$count, &$skipped, &$errors) {
                foreach ($rows as $i => $row) {
                    if (is_string($row)) {
                        $decoded = json_decode($row, true);
                        $row = is_array($decoded) ? $decoded : null;
                    }
                    if (!is_array($row)) {
                        $skipped++;
                        $this->warn("  - skipped non-object row #{$i} for week ".$day->toDateString());
                        continue;
                    }
                    try {
                        $upserter->importReservation($row);
                        $count++;
                    } catch (Throwable $e) {
                        $errors++;
                        $ref = $row['Reference'] ?? $row['ResNo'] ?? $row['Id'] ?? $row['ReservationId'] ?? '?';
                        $this->error("  ! error on row ref {$ref}: ".$e->getMessage());
                    }
                }
            };

            if ($only === 'made' || $only === 'both') {
                $process($feed->reservationWeekMade($day));
            }
            if ($only === 'pickup' || $only === 'both') {
                $process($feed->reservationWeekPickup($day));
            }
        }

        $this->info("Done: {$weeks} week(s), {$count} imported, {$skipped} skipped, {$errors} errors.");
        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
