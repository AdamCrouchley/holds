<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class DreamDrivesFeed
{
    private string $base;
    private string $key;
    private string $tz;

    public function __construct(?string $base = null, ?string $key = null, ?string $tz = null)
    {
        $this->base = $base ?? config('services.dreamdrives.base');
        $this->key  = $key  ?? config('services.dreamdrives.key');
        $this->tz   = $tz   ?? config('services.dreamdrives.tz', 'Pacific/Auckland');
    }

    public function reservationWeekMade(Carbon $anyDayOfWeek): array
    {
        return $this->getRows('ReservationWeekMade', $anyDayOfWeek);
    }

    public function reservationWeekPickup(Carbon $anyDayOfWeek): array
    {
        return $this->getRows('ReservationWeekPickup', $anyDayOfWeek);
    }

    /** Get and normalize into an array of row arrays */
    private function getRows(string $endpoint, Carbon $day): array
    {
        $url = rtrim($this->base, '/').'/'.$this->key.'/'.$endpoint;

        $resp = Http::timeout(30)->acceptJson()->get($url, [
            'format' => 'json',
            'date'   => $day->toDateString(),
        ]);

        if ($resp->status() === 404) {
            $resp = Http::timeout(30)->acceptJson()->get($url, [
                'format' => 'json',
                'week'   => $day->isoFormat('GGGG-[W]WW'),
            ]);
        }

        $resp->throw();

        $json = $resp->json(); // could be array|string|null
        return $this->normalizeRows($json);
    }

    /** Normalize unknown JSON shapes into a list of associative arrays */
    private function normalizeRows(mixed $json): array
    {
        if (is_array($json)) {
            // Common wrappers
            foreach (['reservations','items','data','rows','results'] as $k) {
                if (array_key_exists($k, array_change_key_case(array_keys($json), CASE_LOWER))) {
                    // Recompute with lowercase map
                    $lower = [];
                    foreach ($json as $kk => $vv) $lower[strtolower($kk)] = $vv;
                    return $this->normalizeRows($lower[$k]);
                }
            }
            // If assoc with array values, return values
            if (!$this->isList($json)) {
                $allArrays = true;
                foreach ($json as $v) { if (!is_array($v)) { $allArrays = false; break; } }
                if ($allArrays) return array_values($json);
            }
            // Already a list
            return array_map(function ($row) {
                if (is_string($row)) {
                    $decoded = json_decode($row, true);
                    return is_array($decoded) ? $decoded : ['_raw' => $row];
                }
                return is_array($row) ? $row : ['_raw' => $row];
            }, $json);
        }

        if (is_string($json)) {
            // Try to decode whole string
            $decoded = json_decode($json, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->normalizeRows($decoded);
            }
            // Try newline-delimited JSON
            $rows = [];
            foreach (preg_split("/\r\n|\n|\r/", trim($json)) as $line) {
                if ($line === '') continue;
                $d = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($d)) $rows[] = $d;
            }
            return $rows;
        }

        return [];
    }

    private function isList(array $arr): bool
    {
        if ($arr === []) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
