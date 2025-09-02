<?php

namespace App\Actions\Importers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UpsertDreamDrives
{
    /** $row is one reservation object from the feed */
    public function importReservation(array $row): Booking
    {
        return DB::transaction(function () use ($row) {

            // ---- Customer ----
            $custId   = (string)($row['CustomerId'] ?? $row['customer_id'] ?? $row['customer']['id'] ?? '');
            $fullName = trim(($row['CustomerFirst'] ?? '').' '.($row['CustomerLast'] ?? '')) ?: ($row['CustomerName'] ?? null);
            $email    = $row['CustomerEmail'] ?? $row['Email'] ?? $row['customer']['email'] ?? null;
            $phone    = $row['CustomerPhone'] ?? $row['Phone'] ?? $row['customer']['phone'] ?? null;

            $customer = Customer::updateOrCreate(
                ['source_system' => 'dreamdrives', 'source_id' => $custId ?: md5($email ?? json_encode($row))],
                [
                    'name'              => $fullName,
                    'email'             => $email,
                    'phone'             => $phone,
                    'source_updated_at' => $this->ts($row['CustomerUpdatedAt'] ?? null),
                ]
            );

            // ---- Booking ----
            $srcId     = (string)($row['Id'] ?? $row['ReservationId'] ?? $row['ReservationID'] ?? $row['id'] ?? Str::uuid());
            $reference = $row['Reference'] ?? $row['ResNo'] ?? ('DD-'.$srcId);

            $start = $this->ts($row['StartAt'] ?? $row['PickupAt'] ?? $row['PickUpDate'] ?? $row['StartDate'] ?? null);
            $end   = $this->ts($row['EndAt']   ?? $row['ReturnAt'] ?? $row['DropOffDate'] ?? $row['EndDate']   ?? null);

            $vehicleName = $row['VehicleName'] ?? $row['Vehicle'] ?? ($row['vehicle']['name'] ?? null);
            $vehicleId   = $row['VehicleId'] ?? $row['VehicleID'] ?? ($row['vehicle']['id'] ?? null);

            $total       = $this->cents($row['Total'] ?? $row['TotalAmount'] ?? 0);
            $deposit     = $this->cents($row['Deposit'] ?? $row['DepositAmount'] ?? 0);
            $hold        = $this->cents($row['Bond'] ?? $row['BondAmount'] ?? $row['HoldAmount'] ?? 0);
            $currency    = strtoupper($row['Currency'] ?? 'NZD');

            $booking = Booking::updateOrCreate(
                ['source_system' => 'dreamdrives', 'source_id' => $srcId],
                [
                    'customer_id'       => $customer->id,
                    'reference'         => $reference,
                    'status'            => $this->mapStatus($row['Status'] ?? $row['ReservationStatus'] ?? null),
                    'vehicle'           => $vehicleName,
                    'vehicle_id'        => $vehicleId,
                    'currency'          => $currency,
                    'start_at'          => $start,
                    'end_at'            => $end,
                    'total_amount'      => $total,
                    'deposit_amount'    => $deposit,
                    'hold_amount'       => $hold,
                    'source_system'     => 'dreamdrives',
                    'source_id'         => $srcId,
                    'source_updated_at' => $this->ts($row['UpdatedAt'] ?? $row['ModifiedAt'] ?? $row['CreatedAt'] ?? null),
                    'meta'              => ['source_raw' => $row],
                ]
            );

            if (empty($booking->portal_token)) {
                $booking->forceFill(['portal_token' => Str::random(48)])->save();
            }

            // ---- Payments (if present) ----
            foreach (($row['Payments'] ?? $row['payments'] ?? []) as $p) {
                $pid = (string)($p['Id'] ?? $p['id'] ?? Str::uuid());
                Payment::updateOrCreate(
                    ['source_system' => 'dreamdrives', 'source_id' => $pid],
                    [
                        'booking_id'        => $booking->id,
                        'amount'            => $this->cents($p['Amount'] ?? $p['amount'] ?? 0),
                        'currency'          => strtoupper($p['Currency'] ?? $currency),
                        'status'            => $this->mapPaymentStatus($p['Status'] ?? null),
                        'method'            => $p['Method'] ?? $p['method'] ?? 'card',
                        'type'              => $this->inferType($p),
                        'paid_at'           => $this->ts($p['PaidAt'] ?? $p['created_at'] ?? null),
                        'external_ref'      => $p['ExternalRef'] ?? $p['external_ref'] ?? null,
                        'source_updated_at' => $this->ts($p['UpdatedAt'] ?? null),
                        'meta'              => ['source_raw' => $p],
                    ]
                );
            }

            return $booking->refresh();
        });
    }

    private function cents($v): int
    {
        if (is_string($v) && str_contains($v, '.')) return (int) round(((float)$v) * 100);
        return (int) $v;
    }

    private function ts($v): ?Carbon
    {
        if (!$v) return null;
        return Carbon::parse($v)->tz(config('services.dreamdrives.tz', 'Pacific/Auckland'));
    }

    private function mapStatus(?string $s): ?string
    {
        return match (strtolower((string)$s)) {
            'quote','pending'       => 'pending',
            'confirmed','active'    => 'confirmed',
            'completed','finished'  => 'completed',
            'cancelled','canceled'  => 'cancelled',
            default                 => $s,
        };
    }

    private function mapPaymentStatus(?string $s): ?string
    {
        return match (strtolower((string)$s)) {
            'paid','succeeded','captured' => 'succeeded',
            'pending','requires_action'   => 'pending',
            'refunded'                    => 'refunded',
            'failed'                      => 'failed',
            default                       => $s,
        };
    }

    private function inferType(array $p): string
    {
        $t = strtolower((string)($p['Type'] ?? $p['type'] ?? ''));
        if (in_array($t, ['deposit','balance','posthire','refund'])) return $t;
        $d = strtolower((string)($p['Description'] ?? $p['description'] ?? ''));
        return str_contains($d, 'deposit') ? 'deposit'
             : (str_contains($d, 'refund') ? 'refund'
             : (str_contains($d, 'post') ? 'posthire' : 'balance'));
    }
}
