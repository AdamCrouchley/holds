<?php

namespace App\Actions\Importers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Carbon\Carbon;

class UpsertDreamDrives
{
    /** Cache table columns (fewer Schema hits) */
    private array $colCache = [];

    /**
     * Import one Dream Drives reservation row (assoc array).
     * Idempotent for both customers and bookings.
     */
    public function importReservation(array $row): Booking
    {
        return DB::transaction(function () use ($row) {

            // ---------------- Customer (names + de-dupe) ----------------
            $custId = (string)($row['CustomerId'] ?? $row['customer_id'] ?? $row['customer']['id'] ?? '');

            // Names: prefer c_fname / c_lname from DreamDrives
            $first = $this->clean($row['c_fname']       ?? $row['CustomerFirst'] ?? $row['customer']['first_name'] ?? null);
            $last  = $this->clean($row['c_lname']       ?? $row['CustomerLast']  ?? $row['customer']['last_name']  ?? null);
            $name  = $this->buildFullName($first, $last)
                   ?: $this->clean($row['CustomerName'] ?? $row['c_driver_name'] ?? null)
                   ?: 'Dream Drives Guest';

            $emailRaw = $row['CustomerEmail'] ?? $row['Email'] ?? $row['c_email'] ?? ($row['customer']['email'] ?? null);
            $phone    = $this->clean($row['CustomerPhone'] ?? $row['Phone'] ?? $row['c_phone'] ?? ($row['customer']['phone'] ?? null));
            $email    = $this->safeEmail($emailRaw, $custId, $row);

            // Find existing customer by email first (strongest real-world key)
            $customer = null;
            if ($this->has('customers', 'email') && $email !== '') {
                $customer = Customer::where('email', $email)->first();
            }
            // Else fall back to (source_system, source_id) if table supports it
            if (!$customer && $this->has('customers', 'source_system') && $this->has('customers', 'source_id')) {
                $sourceId = ($custId !== '' ? $custId : md5($email));
                $customer = Customer::where('source_system', 'dreamdrives')->where('source_id', $sourceId)->first();
            }

            // Prepare write
            $customerWrite = [
                // names: we include all common shapes; trimToExisting() keeps only what your table really has.
                'name'        => $name,
                'first_name'  => $first,
                'last_name'   => $last,
                'given_name'  => $first,
                'family_name' => $last,
                'email'       => $email,
                'phone'       => $phone,
                'source_system'     => $this->has('customers','source_system') ? 'dreamdrives' : null,
                'source_id'         => $this->has('customers','source_id')     ? ($custId !== '' ? $custId : md5($email)) : null,
                'source_updated_at' => $this->ts($row['CustomerUpdatedAt'] ?? null),
            ];
            $customerWrite = $this->trimToExisting('customers', $customerWrite);

            if ($customer) {
                $customer->fill($customerWrite)->save();
            } else {
                // Choose identifier for updateOrCreate to avoid dupes
                $ident = [];
                if ($this->has('customers', 'email') && $email !== '') {
                    $ident = ['email' => $email];
                } elseif ($this->has('customers', 'source_system') && $this->has('customers', 'source_id')) {
                    $ident = ['source_system' => 'dreamdrives', 'source_id' => ($custId !== '' ? $custId : md5($email))];
                } elseif ($this->has('customers', 'phone') && $phone) {
                    $ident = ['phone' => $phone];
                } else {
                    // Last-resort: create new row
                    $ident = ['email' => $email]; // will be in write-set
                }
                $customer = Customer::updateOrCreate($ident, $customerWrite);
            }

            // ---------------- Booking (strict dates + de-dupe) ----------------
            $srcId     = (string)($row['Id'] ?? $row['ReservationId'] ?? $row['ReservationID'] ?? $row['uuid'] ?? $row['id'] ?? Str::uuid());
            $reference = $row['Reference'] ?? $row['ResNo'] ?? $row['ref_id'] ?? ('DD-' . $srcId);

            // Required dates: DreamDrives uses 'from' / 'to'
            [$start, $end] = $this->fromToStrict($row);

            // Vehicle
            $vehicleName = $this->clean($row['VehicleName'] ?? $row['Vehicle'] ?? ($row['vehicle']['name'] ?? null));
            $vehicleId   = $this->clean($row['VehicleId']   ?? $row['VehicleID'] ?? $row['car_id'] ?? ($row['vehicle']['id'] ?? null));

            // Money
            $currency = strtoupper($row['Currency'] ?? ($row['currency']['value'] ?? 'NZD'));
            $total    = $this->cents($row['Total'] ?? $row['TotalAmount'] ?? $row['total_price'] ?? 0);
            $deposit  = $this->cents($row['Deposit'] ?? $row['DepositAmount'] ?? $row['required_deposit'] ?? 0);
            $hold     = $this->cents($row['Bond'] ?? $row['BondAmount'] ?? $row['HoldAmount'] ?? $row['security_deposit'] ?? 0);

            $status   = $this->mapStatus($row['Status'] ?? $row['ReservationStatus'] ?? $row['status'] ?? null);

            $attrs = [
                'customer_id'       => $customer->id,
                'reference'         => $reference,
                'status'            => $status,
                'currency'          => $currency,
                'start_at'          => $start,
                'end_at'            => $end,
                'total_amount'      => $total,
                'deposit_amount'    => $deposit,
                'hold_amount'       => $hold,
                'meta'              => ['source_raw' => $row],
                'source_system'     => $this->has('bookings','source_system') ? 'dreamdrives' : null,
                'source_id'         => $this->has('bookings','source_id')     ? $srcId : null,
                'source_updated_at' => $this->ts($row['UpdatedAt'] ?? $row['ModifiedAt'] ?? $row['CreatedAt'] ?? $row['created'] ?? null),
            ];
            if ($this->has('bookings','vehicle'))    $attrs['vehicle']    = $vehicleName;
            if ($this->has('bookings','vehicle_id')) $attrs['vehicle_id'] = $vehicleId;
            if ($this->has('bookings','brand'))      $attrs['brand']      = 'dreamdrives';

            $attrs = $this->trimToExisting('bookings', $attrs);

            // Prefer identifying by source keys when schema supports it; else by reference.
            $hasSrcKeys = $this->has('bookings','source_system') && $this->has('bookings','source_id');
            $ident = $hasSrcKeys ? ['source_system'=>'dreamdrives','source_id'=>$srcId] : ['reference'=>$reference];

            try {
                $booking = Booking::updateOrCreate($ident, $attrs);
            } catch (QueryException $e) {
                // If a unique(reference) collision happens, merge into that record.
                if (str_contains($e->getMessage(), 'UNIQUE') && str_contains($e->getMessage(), 'bookings.reference')) {
                    $booking = Booking::where('reference', $reference)->first();
                    if (!$booking) throw $e;
                    $merge = $attrs;
                    if ($hasSrcKeys) {
                        $merge['source_system'] = 'dreamdrives';
                        $merge['source_id']     = $srcId;
                    }
                    $booking->fill($merge)->save();
                } else {
                    throw $e;
                }
            }

            // Ensure portal token if supported
            if ($this->has('bookings', 'portal_token') && empty($booking->portal_token)) {
                $booking->forceFill(['portal_token' => Str::random(48)])->save();
            }

            // ---------------- Payments (light de-dupe) ----------------
            $payments = ($row['Payments'] ?? $row['payments'] ?? []);
            if (is_array($payments)) {
                foreach ($payments as $p) {
                    if (!is_array($p)) continue;
                    $pid = (string)($p['Id'] ?? $p['id'] ?? Str::uuid());

                    $pay = [
                        'booking_id'        => $booking->id,
                        'amount'            => $this->cents($p['Amount'] ?? $p['amount'] ?? 0),
                        'currency'          => strtoupper($p['Currency'] ?? $currency),
                        'status'            => $this->mapPaymentStatus($p['Status'] ?? $p['status'] ?? null),
                        'method'            => $p['Method'] ?? $p['method'] ?? 'card',
                        'type'              => $this->inferType($p),
                        'paid_at'           => $this->ts($p['PaidAt'] ?? $p['created_at'] ?? null),
                        'external_ref'      => $p['ExternalRef'] ?? $p['external_ref'] ?? null,
                        'source_system'     => $this->has('payments','source_system') ? 'dreamdrives' : null,
                        'source_id'         => $this->has('payments','source_id')     ? $pid : null,
                        'source_updated_at' => $this->ts($p['UpdatedAt'] ?? null),
                        'meta'              => ['source_raw' => $p],
                    ];
                    $pay = $this->trimToExisting('payments', $pay);

                    // Prefer source keys if table supports; else try to avoid dupes by (booking_id + external_ref)
                    if ($this->has('payments','source_system') && $this->has('payments','source_id') && $pay['source_id'] ?? null) {
                        Payment::updateOrCreate(
                            ['source_system' => 'dreamdrives', 'source_id' => $pid],
                            $pay
                        );
                    } elseif ($this->has('payments','external_ref') && ($pay['external_ref'] ?? null)) {
                        Payment::updateOrCreate(
                            ['booking_id' => $booking->id, 'external_ref' => $pay['external_ref']],
                            $pay
                        );
                    } else {
                        // Last resort: avoid exact-duplicate by (booking_id, amount, paid_at, method)
                        Payment::updateOrCreate(
                            [
                                'booking_id' => $booking->id,
                                'amount'     => $pay['amount'] ?? 0,
                                'paid_at'    => $pay['paid_at'] ?? null,
                                'method'     => $pay['method'] ?? 'card',
                            ],
                            $pay
                        );
                    }
                }
            }

            return $booking->refresh();
        });
    }

    // ---------------- Helpers ----------------

    private function clean(?string $v): ?string
    {
        if ($v === null) return null;
        $t = trim($v);
        return $t === '' ? null : $t;
    }

    private function buildFullName(?string $first, ?string $last): ?string
    {
        $parts = array_values(array_filter([$this->clean($first), $this->clean($last)]));
        return empty($parts) ? null : implode(' ', $parts);
    }

    private function has(string $table, string $column): bool
    {
        if (!isset($this->colCache[$table])) {
            $this->colCache[$table] = Schema::getColumnListing($table);
        }
        return in_array($column, $this->colCache[$table], true);
    }

    private function trimToExisting(string $table, array $data): array
    {
        if (!isset($this->colCache[$table])) {
            $this->colCache[$table] = Schema::getColumnListing($table);
        }
        return array_intersect_key($data, array_flip($this->colCache[$table]));
    }

    private function cents($v): int
    {
        if (is_string($v)) $v = preg_replace('/[^\d\.\-]/', '', $v);
        return (int) round(((float) $v) * 100);
    }

    private function ts($v): ?Carbon
    {
        if (!$v) return null;
        return Carbon::parse($v)->tz(config('services.dreamdrives.tz', 'Pacific/Auckland'));
    }

    /** STRICT DreamDrives pickup/return parsing */
    private function fromToStrict(array $row): array
    {
        $tz   = config('services.dreamdrives.tz', 'Pacific/Auckland');
        $from = $row['from'] ?? $row['From'] ?? null;
        $to   = $row['to']   ?? $row['To']   ?? null;

        $start = $this->parseExact($from, $tz);
        $end   = $this->parseExact($to,   $tz);
        if (!$start || !$end) {
            throw new \InvalidArgumentException('Missing start/end datetime (from/to) in feed row.');
        }
        return [$start, $end];
    }

    private function parseExact(?string $v, string $tz): ?Carbon
    {
        if (!$v) return null;
        $v = trim($v);
        try {
            return Carbon::createFromFormat('Y-m-d H:i:s', $v, $tz)->tz($tz);
        } catch (\Throwable) {}
        try {
            return Carbon::parse($v, $tz)->tz($tz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function mapStatus(?string $s): ?string
    {
        return match (strtolower((string) $s)) {
            'quote','pending','unpaid'                  => 'pending',
            'confirmed','active','be_collected','paid'  => 'paid',
            'completed','finished','returned'           => 'paid',
            'cancelled','canceled'                      => 'cancelled',
            default                                     => 'pending',
        };
    }

    private function mapPaymentStatus(?string $s): ?string
    {
        return match (strtolower((string)$s)) {
            'paid','succeeded','captured','completed' => 'succeeded',
            'pending','requires_action'               => 'pending',
            'refunded'                                => 'refunded',
            'failed'                                  => 'failed',
            default                                   => 'succeeded',
        };
    }

    private function inferType(array $p): string
    {
        $t = strtolower((string)($p['Type'] ?? $p['type'] ?? ''));
        if (in_array($t, ['deposit','balance','posthire','refund'], true)) return $t;

        $d = strtolower((string)($p['Description'] ?? $p['description'] ?? ''));
        return str_contains($d, 'deposit') ? 'deposit'
             : (str_contains($d, 'refund') ? 'refund'
             : (str_contains($d, 'post') ? 'posthire' : 'balance'));
    }

    /** Deterministic placeholder email for NOT NULL schemas */
    private function safeEmail(?string $email, ?string $custId, array $row): string
    {
        $email = $this->clean($email);
        if ($email !== null && $email !== '') return $email;

        $seed = $custId ?: substr(
            sha1(json_encode([
                $this->clean($row['CustomerName'] ?? null),
                $this->clean($row['CustomerFirst'] ?? $row['c_fname'] ?? null),
                $this->clean($row['CustomerLast']  ?? $row['c_lname'] ?? null),
                $this->clean($row['CustomerPhone'] ?? $row['c_phone'] ?? null),
                $row['Id'] ?? $row['ReservationId'] ?? $row['uuid'] ?? null,
            ])),
            0,
            16
        );

        return "noemail+{$seed}@dreamdrives.invalid";
    }
}
