<?php

namespace App\Console\Commands;

use App\Models\AutomationSetting;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RunAutomations extends Command
{
    protected $signature = 'holds:run-automations {--dry : show actions without creating}';
    protected $description = 'Create scheduled balance/bond payment requests for upcoming bookings';

    public function handle(): int
    {
        $settings = AutomationSetting::first();
        if (!$settings || !$settings->active) {
            $this->info('Automations inactive; nothing to do.');
            return self::SUCCESS;
        }

        $tz   = $settings->timezone ?: config('app.timezone', 'Pacific/Auckland');
        $now  = CarbonImmutable::now($tz);

        // Only send within the configured local hour
        [$h,$m,$s] = explode(':', $settings->send_at_local ?: '09:00:00');
        $windowStart = $now->setTime((int)$h, (int)$m, (int)$s);
        $windowEnd   = $windowStart->addMinutes(15); // small window; we run every 15m
        if ($now->lt($windowStart) || $now->gte($windowEnd)) {
            $this->info("Outside send window {$windowStart->format('H:i')}–{$windowEnd->format('H:i')} {$tz}.");
            return self::SUCCESS;
        }

        $balanceDays = (int) $settings->send_balance_days_before;
        $bondDays    = (int) $settings->send_bond_days_before;

        // target start_at dates to check
        $balanceTargetDate = $now->addDays($balanceDays)->startOfDay();
        $bondTargetDate    = $now->addDays($bondDays)->startOfDay();

        // Fetch upcoming bookings (simple filter; tweak to your schema)
        $bookings = Booking::query()
            ->whereNotNull('start_at')
            ->whereBetween('start_at', [$now->startOfDay(), $now->copy()->addDays(max($balanceDays,$bondDays))->endOfDay()])
            ->whereIn('status', ['pending','paid','confirmed']) // include those not cancelled
            ->get();

        $dry = (bool) $this->option('dry');
        $created = 0;

        foreach ($bookings as $b) {
            $startLocal = $b->start_at ? $b->start_at->copy()->setTimezone($tz) : null;
            if (!$startLocal) continue;

            // compute paid in cents
            $paidC = (int)($b->paid_amount ?? 0);
            if ($paidC === 0) {
                try {
                    $paidC = (int) Payment::where('booking_id', $b->id)->where('status','succeeded')->sum('amount');
                } catch (\Throwable $e) { /* ok */ }
            }
            $totalC = (int)($b->total_amount ?? 0);
            $remainC = max($totalC - $paidC, 0);
            $bondC = (int)($b->hold_amount ?? 0);

            // BALANCE automation
            if ($balanceDays >= 0 && $startLocal->isSameDay($balanceTargetDate) && $remainC > 0) {
                $dueAt = $startLocal->copy()->subDays($balanceDays)->setTime((int)$h, (int)$m, 0);
                $idk = "auto:balance:b{$b->id}:{$startLocal->format('Ymd')}";
                if (!PaymentRequest::where('idempotency_key', $idk)->exists()) {
                    $this->info("Balance request for booking {$b->reference} (remain ".number_format($remainC/100,2).")");
                    if (!$dry) {
                        if (empty($b->portal_token)) {
                            $b->forceFill(['portal_token' => Str::random(48)])->save();
                        }
                        PaymentRequest::create([
                            'booking_id'      => $b->id,
                            'type'            => 'balance',
                            'amount'          => null, // remaining balance
                            'currency'        => $b->currency ?? 'NZD',
                            'due_at'          => $dueAt,
                            'status'          => 'pending',
                            'idempotency_key' => $idk,
                            'source_system'   => 'automation',
                            'meta'            => ['created_via' => 'automation'],
                        ]);
                        // (optional) email the guest here, or run a queue job
                        $created++;
                    }
                }
            }

            // BOND automation
            if ($bondDays >= 0 && $bondC > 0 && $startLocal->isSameDay($bondTargetDate)) {
                $dueAt = $startLocal->copy()->subDays($bondDays)->setTime((int)$h, (int)$m, 0);
                $idk = "auto:bond:b{$b->id}:{$startLocal->format('Ymd')}";
                if (!PaymentRequest::where('idempotency_key', $idk)->exists()) {
                    $this->info("Bond request for booking {$b->reference} (bond ".number_format($bondC/100,2).")");
                    if (!$dry) {
                        if (empty($b->portal_token)) {
                            $b->forceFill(['portal_token' => Str::random(48)])->save();
                        }
                        PaymentRequest::create([
                            'booking_id'      => $b->id,
                            'type'            => 'bond',
                            'amount'          => $bondC,
                            'currency'        => $b->currency ?? 'NZD',
                            'due_at'          => $dueAt,
                            'status'          => 'pending',
                            'idempotency_key' => $idk,
                            'source_system'   => 'automation',
                            'meta'            => ['created_via' => 'automation'],
                        ]);
                        // (optional) email the guest here, or run a queue job
                        $created++;
                    }
                }
            }
        }

        $this->info("Done. Created {$created} requests".($dry?' (dry run)':'').'.');
        return self::SUCCESS;
    }
}
