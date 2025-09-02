<?php

namespace App\Http\Controllers;

use App\Jobs\UpsertBookingFromVeVs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class VevsWebhookController extends Controller
{
    /**
     * Handle VEVS webhook deliveries.
     *
     * Secrets:
     *  - Put your shared secret in config/services.php as:
     *      'vevs' => ['webhook_secret' => env('VEVS_WEBHOOK_SECRET'), 'queue' => env('VEVS_QUEUE', true)]
     *  - We accept the secret either in the "X-Vevs-Secret" header, or the "token" query param.
     *
     * Payload shapes supported:
     *  - Single booking object (root-level fields)
     *  - Batch under "data" or "bookings": [{...}, {...}]
     *  - JSON or application/x-www-form-urlencoded
     */
    public function handle(Request $request): JsonResponse
    {
        $requestId = (string) str()->uuid();

        // 1) Verify secret (optional but recommended)
        $configuredSecret = (string) (config('services.vevs.webhook_secret') ?? '');
        if ($configuredSecret !== '') {
            $provided = trim((string) ($request->header('X-Vevs-Secret') ?? $request->query('token') ?? ''));
            if (! hash_equals($configuredSecret, $provided)) {
                Log::warning('[VEVS webhook] Invalid secret', ['rid' => $requestId, 'ip' => $request->ip()]);
                return response()->json([
                    'ok'   => false,
                    'rid'  => $requestId,
                    'error'=> 'Invalid signature/secret',
                ], 401);
            }
        } else {
            Log::notice('[VEVS webhook] No webhook secret configured', ['rid' => $requestId]);
        }

        // 2) Normalize payload
        $payload = $this->parsePayload($request);
        if ($payload === null) {
            Log::warning('[VEVS webhook] Empty/invalid payload', ['rid' => $requestId]);
            return response()->json([
                'ok'   => false,
                'rid'  => $requestId,
                'error'=> 'Empty or invalid payload',
            ], 400);
        }

        // 3) Extract rows (batch or single)
        $rows = [];
        if (is_array($payload)) {
            // Case: batch under known keys
            $candidate = Arr::get($payload, 'data');
            if (! is_array($candidate)) {
                $candidate = Arr::get($payload, 'bookings');
            }
            if (is_array($candidate)) {
                $rows = array_values(array_filter($candidate, 'is_array'));
            } else {
                // Case: single row at root (must look like an associative array of fields)
                $isAssoc = array_keys($payload) !== range(0, count($payload) - 1);
                if ($isAssoc) {
                    $rows = [$payload];
                }
            }
        }

        if (empty($rows)) {
            Log::warning('[VEVS webhook] No booking rows detected', ['rid' => $requestId, 'keys' => array_keys((array) $payload)]);
            return response()->json([
                'ok'   => false,
                'rid'  => $requestId,
                'error'=> 'No booking rows found',
            ], 400);
        }

        // 4) Dispatch upserts
        $useQueue = (bool) (config('services.vevs.queue') ?? true);
        $processed = 0;
        $failed    = 0;
        $errors    = [];

        foreach ($rows as $idx => $row) {
            try {
                // Ensure array shape
                $row = (array) $row;

                if ($useQueue) {
                    UpsertBookingFromVeVs::dispatch($row);
                } else {
                    // Run inline (synchronous) if queue disabled
                    app(UpsertBookingFromVeVs::class, ['row' => $row])->handle();
                }
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'index'   => $idx,
                    'message' => $e->getMessage(),
                ];
                Log::error('[VEVS webhook] Row failed', [
                    'rid'   => $requestId,
                    'index' => $idx,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'ok'        => $failed === 0,
            'rid'       => $requestId,
            'processed' => $processed,
            'failed'    => $failed,
            'errors'    => $errors,
        ], $failed ? 207 : 200); // 207 Multi-Status if some failed
    }

    /**
     * Parse JSON or form payload into array|null.
     */
    protected function parsePayload(Request $request): ?array
    {
        // Prefer JSON if present and valid
        if ($request->isJson()) {
            $json = $request->json()->all();
            if (is_array($json) && ! empty($json)) {
                return $json;
            }
        }

        // Fallback to all request input (form-urlencoded)
        $all = $request->all();
        if (is_array($all) && ! empty($all)) {
            return $all;
        }

        // Raw fallback (rare)
        $raw = (string) $request->getContent();
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && ! empty($decoded)) {
                    return $decoded;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return null;
    }
}
