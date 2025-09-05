<?php

namespace App\Console\Commands;

use App\Jobs\UpsertBookingFromVeVs;
use App\Services\VeVsApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncVeVsRecent extends Command
{
    protected $signature = 'vevs:sync-recent
                            {--queue : Dispatch jobs to the queue}
                            {--limit=200 : Max rows to process}
                            {--dry : Don\'t write to DB}
                            {--debug : Log sample payload & keys}';

    protected $description = 'Fetch recent VeVS reservations (week made + week pickup) and upsert them';

    public function handle(VeVsApi $api): int
    {
        try {
            $a = $this->normalize($api->reservationsWeekMade());
            $b = $this->normalize($api->reservationsWeekPickup());
        } catch (\Throwable $e) {
            $this->error('Fetch failed: '.$e->getMessage());
            Log::error('[vevs:sync-recent] fetch failed', ['error' => $e->getMessage()]);
            return self::FAILURE;
        }

        // merge + de-dupe by best available ref
        $rows = array_merge($a, $b);
        $seen = []; $list = [];
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row)) continue;
            $ref = $row['ref_id'] ?? $row['Reference'] ?? $row['reference'] ?? $row['uuid'] ?? $row['booking_id'] ?? md5(json_encode($row));
            if (isset($seen[$ref])) continue;
            $seen[$ref] = true;
            $list[] = $row;
        }

        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) $list = array_slice($list, 0, $limit);

        if ($this->option('debug') && !empty($list)) {
            Log::info('[vevs:sync-recent] sample', [
                'count'  => count($list),
                'keys'   => array_slice(array_keys($list[0]), 0, 50),
                'sample' => $list[0],
            ]);
        }

        $dry   = (bool) $this->option('dry');
        $queue = (bool) $this->option('queue');
        $qName = config('queue.names.imports', 'imports');

        $n = 0;
        foreach ($list as $row) {
            $n++;
            if ($dry) continue;
            if ($queue) {
                UpsertBookingFromVeVs::dispatch($row)->onQueue($qName);
            } else {
                (new UpsertBookingFromVeVs($row))->handle();
            }
        }

        $this->info(($dry ? 'Dry run: ' : '') . ($queue ? 'Queued ' : 'Processed ') . $n . ' reservation(s).');
        return self::SUCCESS;
    }

    private function normalize(mixed $raw): array
    {
        if (is_array($raw)) {
            if ($this->isList($raw)) return $raw;
            foreach (['data','reservations','items','results','Bookings','Result','Reservations'] as $k) {
                if (array_key_exists($k, $raw) && is_array($raw[$k])) {
                    return $this->isList($raw[$k]) ? $raw[$k] : [$raw[$k]];
                }
            }
            foreach ($raw as $v) if (is_array($v) && $this->isList($v)) return $v;
            return !empty($raw) ? [$raw] : [];
        }
        return [];
    }

    private function isList(array $a): bool
    {
        if (function_exists('array_is_list')) return array_is_list($a);
        $i=0; foreach ($a as $k=>$_) if ($k!==$i++) return false; return true;
    }
}
