<?php

namespace App\Filament\Resources\BookingResource\Pages;

use App\Filament\Resources\BookingResource;
use App\Jobs\UpsertBookingFromVeVs;
use App\Services\VeVsApi;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // --- Debugger / Diagnostics ---
            Actions\Action::make('debugger')
                ->label('Debugger')
                ->icon('heroicon-o-bug-ant')
                ->form([
                    Forms\Components\Toggle::make('deep_scan')
                        ->label('Deep scan all DB connections')
                        ->default(true),
                    Forms\Components\Toggle::make('test_fetch')
                        ->label('Run test fetch (week made, limit 1)')
                        ->default(false),
                    Forms\Components\Toggle::make('log_sample')
                        ->label('Log sample payload (if test fetch)')
                        ->default(true),
                    Forms\Components\Toggle::make('clear_caches')
                        ->label('Clear caches (optimize:clear)')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    $deepScan   = (bool) ($data['deep_scan'] ?? false);
                    $runFetch   = (bool) ($data['test_fetch'] ?? false);
                    $logSample  = (bool) ($data['log_sample'] ?? true);
                    $clear      = (bool) ($data['clear_caches'] ?? false);

                    if ($clear) {
                        try {
                            Artisan::call('optimize:clear');
                        } catch (Throwable $e) {
                            Log::warning('[ListBookings] optimize:clear failed', ['error' => $e->getMessage()]);
                        }
                    }

                    try {
                        $report = $this->buildDebugReport($runFetch, $logSample, $deepScan);
                    } catch (Throwable $e) {
                        $this->toast('danger', 'Debugger failed: ' . $e->getMessage());
                        return;
                    }

                    $summary = collect($report)->map(fn ($v, $k) => "{$k}: {$v}")->implode("\n");

                    Notification::make()
                        ->title('Diagnostics complete')
                        ->body("Check storage/logs/laravel.log for full details.\n\n{$summary}")
                        ->success()
                        ->send();
                }),


            // --- Sync a single booking by reference ---
            Actions\Action::make('sync_by_ref')
                ->label('Sync by Reference')
                ->icon('heroicon-o-magnifying-glass-circle')
                ->form([
                    Forms\Components\TextInput::make('ref')
                        ->label('Booking Reference')
                        ->placeholder('e.g. QT1692049005')
                        ->required()
                        ->maxLength(191),
                    Forms\Components\Toggle::make('queue')
                        ->label('Queue (background) instead of Sync now')
                        ->default(false),
                    Forms\Components\Toggle::make('dry_run')
                        ->label('Dry run (don’t write to DB)')
                        ->default(false),
                    Forms\Components\Toggle::make('debug')
                        ->label('Debug: log sample payload')
                        ->default(false),
                ])
                ->action(function (array $data) {
                    $ref = trim($data['ref'] ?? '');
                    if ($ref === '') {
                        $this->toast('danger', 'Please enter a booking reference.');
                        return;
                    }

                    $this->runSync(
                        fetch: fn () => [app(VeVsApi::class)->reservationByRef($ref)], // wrap as list
                        label: "reference {$ref}",
                        useQueue: (bool) ($data['queue'] ?? false),
                        limit: 1,
                        dryRun: (bool) ($data['dry_run'] ?? false),
                        debug: (bool) ($data['debug'] ?? false),
                    );
                }),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Small shared form used by “recent” sync actions.
     */
    private function syncFormSchema(): array
    {
        return [
            Forms\Components\TextInput::make('limit')
                ->numeric()
                ->minValue(1)
                ->maxValue(1000)
                ->label('Limit (optional)'),
            Forms\Components\Toggle::make('queue')
                ->label('Queue (background) instead of Sync now')
                ->default(false),
            Forms\Components\Toggle::make('dry_run')
                ->label('Dry run (don’t write to DB)')
                ->default(false),
            Forms\Components\Toggle::make('debug')
                ->label('Debug: log sample payload')
                ->default(false),
        ];
    }

    /**
     * Centralized sync runner.
     *
     * @param  callable(): mixed  $fetch  Returns list/wrapped list/single object.
     */
    private function runSync(
        callable $fetch,
        string $label,
        bool $useQueue = false,
        ?int $limit = null,
        bool $dryRun = false,
        bool $debug = false
    ): void {
        try {
            $raw = $fetch();
        } catch (Throwable $e) {
            $this->toast('danger', 'Fetch failed: ' . $e->getMessage());
            return;
        }

        $list = $this->normalizeToList($raw);

        if ($debug) {
            Log::info('[ListBookings] Fetch debug', [
                'label'   => $label,
                'type'    => is_object($raw) ? get_class($raw) : gettype($raw),
                'count'   => count($list),
                'keys'    => !empty($list) && is_array($list[0]) ? array_slice(array_keys($list[0]), 0, 20) : [],
                'sample'  => $list[0] ?? null,
            ]);
        }

        if (empty($list)) {
            $msg = "No reservations returned ({$label}).";
            if ($debug) $msg .= ' See laravel.log for details.';
            $this->toast('warning', $msg);
            return;
        }

        if ($limit !== null && $limit > 0) {
            $list = array_slice($list, 0, $limit);
        }

        $processed = 0;
        $queueName = config('queue.names.imports', 'imports');

        foreach ($list as $row) {
            if (!is_array($row) || empty($row)) continue;

            $processed++;

            if ($debug) {
                $this->logAmountShapes($row);
            }

            if ($dryRun) continue;

            try {
                if ($useQueue) {
                    UpsertBookingFromVeVs::dispatch($row)->onQueue($queueName);
                } else {
                    (new UpsertBookingFromVeVs($row))->handle();
                }
            } catch (Throwable $e) {
                Log::error('[ListBookings] Upsert failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'row'   => array_slice($row, 0, 40, true),
                ]);
            }
        }

        if ($dryRun) {
            $this->toast('success', "Dry run: {$processed} reservation(s) inspected ({$label}).");
        } elseif ($useQueue) {
            $this->toast('success', "Queued {$processed} reservation(s) for processing ({$label}). Run `php artisan queue:work --queue={$queueName}` to process.");
        } else {
            $this->toast('success', "Processed {$processed} reservation(s) ({$label}).");
        }

        $this->resetTable(); // Filament v3 refresh
    }

    // --------------------------
    // Debugger / Diagnostics
    // --------------------------

    private function buildDebugReport(bool $runFetch, bool $logSample, bool $deepScan): array
    {
        $baseUrl   = (string) (Config::get('services.vevs.base_url') ?? '');
        $hasBase   = $baseUrl !== '';
        $hasApiSvc = class_exists(VeVsApi::class);

        $api = $hasApiSvc ? app(VeVsApi::class) : null;
        $hasResByRef   = $api && method_exists($api, 'reservationByRef');
        $hasWeekMade   = $api && method_exists($api, 'reservationsWeekMade');
        $hasWeekPickup = $api && method_exists($api, 'reservationsWeekPickup');

        $routes = [
            'portal.login.consume' => Route::has('portal.login.consume') ? 'yes' : 'no',
        ];

        $defaultConn = (string) (Config::get('database.default') ?? 'default');
        $defaultDriver = (string) (Config::get("database.connections.{$defaultConn}.driver") ?? '');
        $defaultDbName = (string) (Config::get("database.connections.{$defaultConn}.database") ?? '');
        $defaultHasDeposits = Schema::hasTable('deposits');
        $defaultHasBookingId = $defaultHasDeposits && Schema::hasColumn('deposits','booking_id');

        // Deep scan all configured connections & log exact columns on each
        if ($deepScan) {
            $conns = (array) Config::get('database.connections', []);
            foreach ($conns as $name => $_cfg) {
                try {
                    $driver = (string) (Config::get("database.connections.{$name}.driver") ?? '');
                    $dbName = (string) (Config::get("database.connections.{$name}.database") ?? '');
                    $schema = Schema::connection($name);
                    $hasTable = $schema->hasTable('deposits');
                    $hasCol   = $hasTable ? $schema->hasColumn('deposits', 'booking_id') : false;

                    $columns = [];
                    if ($hasTable) {
                        if ($driver === 'sqlite') {
                            $columns = collect(DB::connection($name)->select('PRAGMA table_info(deposits)'))
                                ->pluck('name')->values()->all();
                        } elseif ($driver === 'mysql') {
                            $columns = collect(DB::connection($name)->select('SHOW COLUMNS FROM deposits'))
                                ->pluck('Field')->values()->all();
                        } else { // pgsql and others
                            $columns = collect(DB::connection($name)->select("
                                SELECT column_name
                                FROM information_schema.columns
                                WHERE table_name = 'deposits'
                                ORDER BY ordinal_position
                            "))->pluck('column_name')->values()->all();
                        }
                    }

                    // Migration status on this connection (best effort)
                    $migrRan = null;
                    try {
                        if ($schema->hasTable('migrations')) {
                            $migrRan = collect(DB::connection($name)->table('migrations')->pluck('migration'))->filter(function ($m) {
                                return str_contains($m, 'add_booking_id_to_deposits');
                            })->values()->all();
                        }
                    } catch (Throwable $e) {
                        $migrRan = null;
                    }

                    Log::info('[ListBookings] deep scan', [
                        'connection'   => $name,
                        'driver'       => $driver,
                        'database'     => $dbName,
                        'has_deposits' => $hasTable ? 'yes' : 'no',
                        'booking_id'   => $hasCol ? 'yes' : 'no',
                        'columns'      => $columns,
                        'migration_matches' => $migrRan,
                    ]);
                } catch (Throwable $e) {
                    Log::error('[ListBookings] deep scan error', [
                        'connection' => $name,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('[ListBookings] Diagnostics summary', [
            'env'     => [
                'php'           => PHP_VERSION,
                'app_env'       => (string) (Config::get('app.env') ?? ''),
                'database.default' => $defaultConn,
                'driver'        => $defaultDriver,
                'database'      => $defaultDbName,
            ],
            'routes'  => $routes,
            'config'  => ['services.vevs.base_url' => $baseUrl ?: '(empty)'],
            'methods' => [
                'VeVsApi::reservationByRef'      => $hasResByRef ? 'yes' : 'no',
                'VeVsApi::reservationsWeekMade'  => $hasWeekMade ? 'yes' : 'no',
                'VeVsApi::reservationsWeekPickup'=> $hasWeekPickup ? 'yes' : 'no',
            ],
            'default_connection' => [
                'has_deposits' => $defaultHasDeposits ? 'yes' : 'no',
                'booking_id'   => $defaultHasBookingId ? 'yes' : 'no',
            ],
        ]);

        $report = [
            'base_url_set'     => $hasBase ? 'yes' : 'no',
            'vevs_api_bound'   => $hasApiSvc ? 'yes' : 'no',
            'route_portal_pay' => $routes['portal.login.consume'],
            'db_default'       => $defaultConn . " ({$defaultDriver})",
            'db_name'          => $defaultDbName,
            'deposits_fk'      => $defaultHasBookingId ? 'yes' : 'no',
            'payments_table'   => Schema::hasTable('payments') ? 'yes' : 'no',
        ];

        if ($runFetch && $hasWeekMade) {
            try {
                $data = app(VeVsApi::class)->reservationsWeekMade();
                $list = $this->normalizeToList($data);
                $cnt  = count($list);
                $report['test_fetch'] = "ok ({$cnt} rows)";

                if ($logSample && $cnt > 0) {
                    Log::info('[ListBookings] Test fetch sample', [
                        'count'  => $cnt,
                        'keys'   => array_slice(array_keys((array) $list[0]), 0, 50),
                        'sample' => $list[0],
                    ]);
                }
            } catch (Throwable $e) {
                $report['test_fetch'] = 'error: ' . $e->getMessage();
                Log::error('[ListBookings] Test fetch failed', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        } else {
            $report['test_fetch'] = $runFetch ? 'not-available (method missing)' : 'skipped';
        }

        return $report;
    }

    // --------------------------
    // Helpers
    // --------------------------

    /** Accepts: list, {data:[...]}, {reservations:[...]}, etc., or single object. */
    private function normalizeToList(mixed $raw): array
    {
        if (is_array($raw) && $this->isList($raw)) return $raw;

        if (is_array($raw)) {
            foreach (['data','reservations','items','results','Bookings','Result','Reservations'] as $key) {
                if (array_key_exists($key, $raw)) {
                    $val = $raw[$key];
                    if (is_array($val)) return $this->isList($val) ? $val : [$val];
                }
            }
            foreach ($raw as $val) {
                if (is_array($val) && $this->isList($val)) return $val;
            }
            return !empty($raw) ? [$raw] : [];
        }

        if (is_object($raw)) return [$raw];

        return [];
    }

    private function isList(array $arr): bool
    {
        if (function_exists('array_is_list')) return array_is_list($arr);
        $i = 0; foreach ($arr as $k => $_) { if ($k !== $i++) return false; }
        return true;
    }

    private function normalizeLimit(null|string|int $limit): ?int
    {
        if ($limit === null || $limit === '') return null;
        $n = (int) $limit;
        return $n > 0 ? $n : null;
    }

    /** Log shape/preview of currency & money fields for a row. */
    private function logAmountShapes(array $row): void
    {
        $groups = [
            'currency' => ['Currency','currency','Curr'],
            'total'    => ['TotalAmount','total_amount','Total','total','grand_total','GrandTotal','TotalPrice','AmountTotal','BookingTotal'],
            'deposit'  => ['DepositAmount','deposit_amount','Deposit','deposit','Prepayment','prepayment','Advance','advance','BookingDeposit'],
            'hold'     => ['HoldAmount','hold_amount','SecurityHold','security_hold','Bond','bond','SecurityDeposit','security_deposit','PreAuth','preauth','Preauthorization','preauthorization'],
        ];

        $out = [];
        foreach ($groups as $label => $keys) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $row)) {
                    $v = $row[$k];
                    $type = get_debug_type($v);
                    $preview = $this->previewValue($v);
                    $first = is_array($v) ? $this->previewValue(reset($v)) : null;

                    $out[] = [
                        'field'   => $k,
                        'label'   => $label,
                        'type'    => $type,
                        'value'   => $preview,
                        'first'   => $first,
                    ];
                }
            }
        }

        if (!empty($out)) {
            Log::info('[ListBookings] Money field shapes', [
                'row_ref' => $row['Reference'] ?? $row['reference'] ?? $row['BookingRef'] ?? null,
                'fields'  => $out,
            ]);
        }
    }

    private function previewValue(mixed $v): string
    {
        if (is_array($v)) {
            $n = count($v);
            $first = reset($v);
            $head = $n ? (is_scalar($first) ? (string) ($first) : get_debug_type($first)) : 'empty';
            return "array($n) head=" . $head;
        }
        if (is_object($v)) return 'object:' . get_class($v);
        $s = (string) (is_bool($v) ? ($v ? 'true' : 'false') : $v);
        return mb_strimwidth($s, 0, 120, '…');
    }

    /** Toast helper. */
    private function toast(string $type, string $message): void
    {
        $note = Notification::make()->title($message);
        match ($type) {
            'success' => $note->success(),
            'warning' => $note->warning(),
            'danger', 'error' => $note->danger(),
            default => null,
        };
        $note->send();
    }
}
