<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for VEVS JSON endpoints with:
 *  - Configurable endpoint names via services.vevs.endpoints[...]
 *  - Automatic fallback candidates (PascalCase / lowercase / .php / with and without trailing slash)
 *  - Normalisation of responses into a list (array of arrays)
 *  - Robust logging (status + short body on errors)
 *
 * Example config (config/services.php):
 * 'vevs' => [
 *     'timeout'   => 25,
 *     'endpoints' => [
 *         'by_ref'      => 'Reservation',
 *         'week_made'   => 'ReservationWeekMade',
 *         'week_pickup' => 'ReservationWeekPickup', // or ReservationWeekArrivals
 *     ],
 * ],
 */
class VeVsApi
{
    protected string $baseUrl;
    protected int $timeout;
    /** @var array<string,string> */
    protected array $endpoints;

    public function __construct(string $baseUrl, ?string $apiKey = null)
    {
        $cfg               = (array) config('services.vevs', []);
        $this->timeout     = (int) ($cfg['timeout'] ?? 20);
        $this->endpoints   = (array) ($cfg['endpoints'] ?? []);
        $base              = rtrim((string) $baseUrl, '/');

        // Append API key segment once if provided separately.
        if (!empty($apiKey) && $base !== '' && !str_ends_with($base, $apiKey)) {
            $base .= '/' . $apiKey;
        }

        $this->baseUrl = $base; // e.g. https://rental.jimny.co.nz/api/<KEY>
    }

    /* ---------------------------------------------------------------------
     | Core HTTP
     * --------------------------------------------------------------------*/
    /**
     * Try a list of path candidates until one returns a valid JSON body.
     *
     * @param  array<int,string> $paths  paths like '/Reservation', '/reservation.php', etc.
     * @param  array<string,mixed> $query
     * @return array<int,array<string,mixed>>
     */
    protected function tryJsonEndpoints(array $paths, array $query = []): array
    {
        $query = array_merge(['format' => 'json'], $query);

        foreach ($paths as $path) {
            $url  = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
            $full = $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

            $resp = Http::withHeaders([
                    'User-Agent' => 'Laravel/VeVsSync (+https://laravel.com)',
                    'Accept'     => 'application/json, text/plain, */*',
                ])
                ->timeout($this->timeout)
                ->withOptions(['allow_redirects' => true])
                ->get($full);

            Log::info('[VeVsApi] request', ['url' => $full, 'status' => $resp->status()]);

            if (!$resp->ok()) {
                // For 404s: keep trying next candidate. For other 4xx/5xx: bail early.
                if ($resp->status() >= 400 && $resp->status() !== 404) {
                    Log::warning('[VeVsApi] non-404 error', [
                        'url'    => $full,
                        'status' => $resp->status(),
                        'body'   => mb_substr($resp->body() ?? '', 0, 500),
                    ]);
                    return [];
                }
                continue;
            }

            $json = $resp->json();

            // Treat explicit VEVS error objects as hard-fail (avoid count==1 on ERR object)
            if (is_array($json) && isset($json['status']) && $json['status'] === 'ERR') {
                Log::warning('[VeVsApi] API error', [
                    'url'  => $full,
                    'code' => $json['code'] ?? null,
                    'text' => $json['text'] ?? null,
                ]);
                return [];
            }

            $list = $this->normalizeList($json);
            if (!empty($list)) {
                return $list;
            }
            // If JSON was valid but empty, keep trying others.
        }

        return [];
    }

    /**
     * Convert common VEVS shapes into a clean list.
     * Accepts:
     *  - [ {...}, {...} ]
     *  - { data: [ ... ] }
     *  - { reservations: [ ... ] }
     *  - { items: [ ... ] }
     *  - { results: [ ... ] }
     *  - { ...single object... } -> [ {...} ]
     *  - { something: { ... }, list: [ ... ] } -> first list it finds
     *
     * @param mixed $json
     * @return array<int,array<string,mixed>>
     */
    protected function normalizeList($json): array
    {
        if (is_array($json)) {
            if (array_is_list($json)) {
                return $json;
            }

            foreach (['data', 'reservations', 'items', 'results'] as $key) {
                if (isset($json[$key]) && is_array($json[$key])) {
                    return array_is_list($json[$key]) ? $json[$key] : [$json[$key]];
                }
            }

            foreach ($json as $value) {
                if (is_array($value) && array_is_list($value)) {
                    return $value;
                }
            }

            if (!empty($json)) {
                return [$json];
            }
        }

        return [];
    }

    /**
     * Build a set of candidate paths for an endpoint name, covering common variants.
     *
     * @param  string $preferred  e.g. 'Reservation'
     * @param  array<int,string> $extras e.g. ['/Reservation.php', '/reservationweekmade']
     * @return array<int,string>
     */
    protected function buildCandidates(string $preferred, array $extras = []): array
    {
        $preferred = trim($preferred);
        $cands = [];

        if ($preferred !== '') {
            $lc = strtolower($preferred);
            $cands = array_merge($cands, [
                '/' . $preferred,
                '/' . $preferred . '/',
                '/' . $lc,
                '/' . $lc . '/',
                '/' . $preferred . '.php',
                '/' . $lc . '.php',
            ]);
        }

        return array_values(array_unique(array_merge($cands, $extras)));
    }

    /* ---------------------------------------------------------------------
     | Single reservation by reference
     * --------------------------------------------------------------------*/
    /**
     * Alias kept for older code paths (e.g. artisan command calling ->reservationByRef()).
     */
    public function reservationByRef(string $reference): array
    {
        return $this->reservationByReference($reference);
    }

    /**
     * GET /Reservation?format=json&ref_id=QT123...
     * Normalises single-object responses into an array and returns the first item.
     */
    public function reservationByReference(string $reference): array
    {
        $byRef = (string) ($this->endpoints['by_ref'] ?? 'Reservation');

        $candidates = $this->buildCandidates($byRef, [
            // Legacy/fallbacks
            '/Reservation', '/Reservation/', '/reservation', '/reservation/',
            '/Reservation.php', '/reservation.php',
        ]);

        $list = $this->tryJsonEndpoints($candidates, ['ref_id' => trim($reference)]);
        return $list[0] ?? [];
    }

    /* ---------------------------------------------------------------------
     | “This week” lists (made / pickup)
     * --------------------------------------------------------------------*/
    /**
     * GET {base}/ReservationWeekMade?format=json
     */
    public function reservationsWeekMade(): array
    {
        $ep = (string) ($this->endpoints['week_made'] ?? 'ReservationWeekMade');

        $candidates = $this->buildCandidates($ep, [
            '/ReservationWeekMade', '/ReservationWeekMade/', '/reservationweekmade',
            '/reservationweekmade/', '/ReservationWeekMade.php', '/reservationweekmade.php',
        ]);

        $list = $this->tryJsonEndpoints($candidates);
        if (empty($list)) {
            Log::notice('[VeVsApi] ReservationWeekMade returned no data or endpoint not found.');
        }
        return $list;
    }

    /**
     * Pickup/Arrivals for current week.
     * Tries configured endpoint first, then known variants.
     */
    public function reservationsWeekPickup(): array
    {
        $cfgName   = (string) ($this->endpoints['week_pickup'] ?? '');
        $variants  = [];

        if ($cfgName !== '') {
            $variants = $this->buildCandidates($cfgName);
        }

        $candidates = array_merge($variants, [
            // pickup
            '/ReservationWeekPickup', '/ReservationWeekPickup/', '/reservationweekpickup',
            '/reservationweekpickup.php', '/ReservationWeekPickup.php',

            // arrivals
            '/ReservationWeekArrivals', '/ReservationWeekArrivals/', '/reservationweekarrivals',
            '/reservationweekarrivals.php', '/ReservationWeekArrivals.php',

            // other common fallbacks
            '/ReservationArrivalsWeek', '/ReservationArrivalsWeek/',
            '/ReservationPickupWeek',   '/ReservationPickupWeek/',
        ]);

        $list = $this->tryJsonEndpoints($candidates);
        if (empty($list)) {
            Log::notice('[VeVsApi] Week pickup/arrivals endpoint not found or empty.');
        }
        return $list;
    }

    /* ---------------------------------------------------------------------
     | Optional: Date-range helpers (future-proof your syncs)
     * --------------------------------------------------------------------*/
    /**
     * Fetch reservations “made” between two dates (inclusive), if your VEVS has such a filter.
     * Falls back to weekMade when range filters aren’t supported (returns current-week data).
     *
     * @param string $from Y-m-d
     * @param string $to   Y-m-d
     * @return array<int,array<string,mixed>>
     */
    public function reservationsMadeBetween(string $from, string $to): array
    {
        $ep = (string) ($this->endpoints['made_between'] ?? '');
        if ($ep === '') {
            // Fallback behaviour (best-effort)
            return $this->reservationsWeekMade();
        }

        $candidates = $this->buildCandidates($ep);
        $list = $this->tryJsonEndpoints($candidates, [
            'from'   => $from,
            'to'     => $to,
            'format' => 'json',
        ]);

        if (empty($list)) {
            Log::notice('[VeVsApi] made_between returned no data; falling back to weekMade.');
            return $this->reservationsWeekMade();
        }

        return $list;
    }

    /**
     * Fetch reservations “pickup” between two dates (inclusive), if supported on your install.
     * Falls back to weekPickup when range filters aren’t supported.
     */
    public function reservationsPickupBetween(string $from, string $to): array
    {
        $ep = (string) ($this->endpoints['pickup_between'] ?? '');
        if ($ep === '') {
            return $this->reservationsWeekPickup();
        }

        $candidates = $this->buildCandidates($ep);
        $list = $this->tryJsonEndpoints($candidates, [
            'from'   => $from,
            'to'     => $to,
            'format' => 'json',
        ]);

        if (empty($list)) {
            Log::notice('[VeVsApi] pickup_between returned no data; falling back to weekPickup.');
            return $this->reservationsWeekPickup();
        }

        return $list;
    }

    /* ---------------------------------------------------------------------
     | Utilities
     * --------------------------------------------------------------------*/
    /**
     * Lightweight health-check that tries a very cheap endpoint first (by_ref with impossible ref),
     * then falls back to week_made. Use in diagnostics.
     */
    public function ping(): bool
    {
        try {
            // Try a fast 404-ish hit that should still return JSON (some installs do)
            $byRef = (string) ($this->endpoints['by_ref'] ?? 'Reservation');
            $ok = !empty($this->tryJsonEndpoints($this->buildCandidates($byRef), ['ref_id' => 'PING-NOOP']));
            if ($ok) {
                return true;
            }

            // Else try week made
            $week = $this->reservationsWeekMade();
            return !empty($week);
        } catch (\Throwable $e) {
            Log::error('[VeVsApi] ping failed', ['e' => $e->getMessage()]);
            return false;
        }
    }
}
