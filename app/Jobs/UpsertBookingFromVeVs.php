<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class UpsertBookingFromVeVs implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<string,mixed> */
    public array $row;

    /** @param array<string,mixed> $row */
    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function handle(): void
    {
        $row = $this->row;

        // ---- identify reference ------------------------------------------------
        $reference = $this->firstNonEmpty($row, ['ref_id','Reference','reference','BookingRef','uuid','booking_id']);
        if (!$reference) {
            // fabricate a stable ref from the source row id/uuid to avoid collisions
            $reference = 'BK-' . ($row['uuid'] ?? $row['id'] ?? uniqid());
        }

        // ---- ensure / upsert customer -----------------------------------------
        $customer = $this->ensureCustomer($row, $reference);

        // ---- map booking attributes -------------------------------------------
        $attrs = [
            'customer_id'    => $customer?->id,
            'vehicle'        => $this->firstNonEmpty($row, ['car_label','car','vehicle','vehicle_name']),
            'start_at'       => $this->firstNonEmpty($row, ['from','start','pickup_date']),
            'end_at'         => $this->firstNonEmpty($row, ['to','end','actual_dropoff_datetime']),
            'total_amount'   => $this->moneyToCents($this->firstNonEmpty($row, ['total_price','TotalAmount','grand_total','GrandTotal','total'])),
            'deposit_amount' => $this->moneyToCents($this->firstNonEmpty($row, ['required_deposit','DepositAmount','deposit'])),
            'hold_amount'    => $this->moneyToCents($this->firstNonEmpty($row, ['security_deposit','SecurityDeposit','bond','Bond','hold','hold_amount'])) ?: 0,
            'currency'       => $this->firstNonEmpty($row, ['currency','Currency']) ?: 'NZD',
            'status'         => $this->mapStatus($this->firstNonEmpty($row, ['status','Status'])),
            'meta'           => [
                'source'        => 'vevs',
                'customer'      => [
                    'name'  => $customer?->full_name ?? trim(($customer?->first_name . ' ' . $customer?->last_name) ?: ''),
                    'email' => $customer?->email,
                    'phone' => $this->firstNonEmpty($row, ['c_phone','phone']),
                ],
                'raw_keys'      => array_keys($row),
            ],
        ];

        // strip null/empty string (except integers like 0)
        $attrs = array_filter($attrs, static function ($v) {
            return !is_null($v) && $v !== '';
        });

        // ---- keep only real booking columns to avoid SQL errors ----------------
        $bookingCols = Schema::getColumnListing((new Booking)->getTable());
        $attrs       = array_intersect_key($attrs, array_flip($bookingCols));

        // ---- upsert by reference ----------------------------------------------
        Booking::updateOrCreate(
            ['reference' => (string) $reference],
            $attrs
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $row */
    private function ensureCustomer(array $row, string $reference): ?Customer
    {
        $email = $this->firstNonEmpty($row, ['c_email','email','customer_email']);
        $name  = $this->firstNonEmpty($row, ['c_name','c_driver_name','customer_name','name']);

        // Some feeds send placeholder "unknown+...@example.invalid"
        $isPlaceholder = is_string($email) && str_ends_with(strtolower($email), '@example.invalid');

        if (!$email) {
            // create a stable, unique placeholder per booking to avoid collisions
            $email = 'unknown+' . $reference . '@example.invalid';
            $isPlaceholder = true;
        }

        [$first, $last] = $this->splitName($name);

        $payload = [
            'email'      => $email,
            'first_name' => $first ?: null,
            'last_name'  => $last ?: null,
            'full_name'  => $name ?: trim($first . ' ' . $last) ?: null,
            'meta'       => [
                'source'   => 'vevs',
                'phone'    => $this->firstNonEmpty($row, ['c_phone','phone']),
                'licence'  => $this->firstNonEmpty($row, ['c_licence','licence']),
                'company'  => $this->firstNonEmpty($row, ['c_company','company']),
            ],
        ];

        // Only include non-null keys we actually have columns for
        $cols    = Schema::getColumnListing((new Customer)->getTable());
        $payload = array_intersect_key(array_filter($payload, fn($v) => $v !== null && $v !== ''), array_flip($cols));

        // Use email as the stable key (even if placeholder). If your schema enforces unique email,
        // this will update the same placeholder record if the reference repeats.
        /** @var Customer|Model $customer */
        $customer = Customer::updateOrCreate(
            ['email' => $email],
            $payload
        );

        // If we later learn the real email on another sync run, update it.
        if ($isPlaceholder) {
            $realEmail = $this->firstNonEmpty($row, ['c_email','email','customer_email']);
            if ($realEmail && !str_ends_with(strtolower($realEmail), '@example.invalid')) {
                $customer->email = $realEmail;
                $customer->save();
            }
        }

        return $customer;
    }

    /** @return array{0:string,1:string} */
    private function splitName(?string $name): array
    {
        $name = trim((string) $name);
        if ($name === '') return ['', ''];

        // Compress whitespace
        $name = preg_replace('/\s+/', ' ', $name);

        $parts = explode(' ', $name);
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }

        $first = array_shift($parts);
        $last  = implode(' ', $parts);

        return [trim($first), trim($last)];
    }

    /** @param array<string,mixed> $arr */
    private function firstNonEmpty(array $arr, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (Arr::has($arr, $k)) {
                $v = Arr::get($arr, $k);
                if (is_string($v) && trim($v) !== '') return trim($v);
                if (is_numeric($v)) return (string) $v;
            }
        }
        return null;
    }

    private function moneyToCents(?string $v): int
    {
        if ($v === null || $v === '') return 0;

        // Normalize: strip currency symbols & commas
        $s = preg_replace('/[^0-9.\-]/', '', (string) $v);
        if ($s === '' || $s === '.' || $s === '-.' || $s === '-') return 0;

        // If it has a decimal point, treat as dollars; else treat as integer dollars
        if (str_contains($s, '.')) {
            $float = (float) $s;
            return (int) round($float * 100);
        }
        return (int) ((int) $s * 100);
    }

    private function mapStatus(?string $v): ?string
    {
        if (!$v) return 'pending';
        $v = strtolower(trim($v));
        return match ($v) {
            'confirmed','paid','complete','completed','captured','succeeded','collected' => 'paid',
            'cancel','cancelled','canceled'                                              => 'cancelled',
            default                                                                       => 'pending',
        };
    }
}
