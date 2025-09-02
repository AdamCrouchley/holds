<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Columns (typical):
 * @property int|null    $customer_id
 * @property int|null    $vehicle_id
 * @property string|null $vehicle
 * @property string      $reference
 * @property string|null $status
 * @property \Illuminate\Support\Carbon|null $start_at
 * @property \Illuminate\Support\Carbon|null $end_at
 * @property int|null    $total_amount
 * @property int|null    $deposit_amount
 * @property int|null    $hold_amount
 * @property string|null $currency
 * @property string|null $portal_token
 * @property array|null  $meta
 * @property string|null $stripe_payment_intent_id
 * @property string|null $brand
 * @property string|null $source_system
 * @property string|null $source_id
 * @property \Illuminate\Support\Carbon|null $source_updated_at
 * @property \Illuminate\Support\Carbon|null $bond_authorized_at
 * @property \Illuminate\Support\Carbon|null $bond_captured_at
 * @property \Illuminate\Support\Carbon|null $bond_released_at
 *
 * Computed:
 * @property-read string $portal_url
 * @property-read int    $amount_paid
 * @property-read int    $balance_due
 * @property-read int    $deposit_paid
 * @property-read int    $deposit_due
 * @property-read int    $deposit_paid_so_far
 * @property-read string $total_formatted
 * @property-read string $deposit_formatted
 * @property-read string $hold_formatted
 * @property-read string $amount_paid_formatted
 * @property-read string $balance_due_formatted
 * @property-read string $deposit_paid_formatted
 * @property-read string $deposit_paid_so_far_formatted
 * @property-read string $deposit_due_formatted
 * @property-read string $customer_name
 * @property-read string $customer_real_name
 * @property-read string $car_label
 * @property-read string $brand_label
 */
class Booking extends Model
{
    use HasFactory;

    /** @var array<int,string> */
    protected $fillable = [
        'customer_id',
        'vehicle_id',
        'vehicle', // fallback string label
        'reference',
        'status',
        'start_at',
        'end_at',
        'total_amount',
        'deposit_amount',
        'hold_amount',
        'currency',
        'portal_token',                 // <— explicitly fillable as requested
        'stripe_payment_intent_id',
        'meta',
        'paid_amount',

        // brand/source
        'brand',
        'source_system',
        'source_id',
        'source_updated_at',
    ];

    /** Casts */
    protected $casts = [
        'start_at'             => 'datetime',
        'end_at'               => 'datetime',
        'total_amount'         => 'integer',
        'deposit_amount'       => 'integer',
        'hold_amount'          => 'integer',
        'meta'                 => 'array',
        'bond_authorized_at'   => 'datetime',
        'bond_captured_at'     => 'datetime',
        'bond_released_at'     => 'datetime',
        'source_updated_at'    => 'datetime',
    ];

    /**
     * Always eager-load customer; vehicle added conditionally if the class exists.
     * @var array<int,string>
     */
    protected $with = ['customer'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        if (class_exists(\App\Models\Vehicle::class)) {
            $this->with = array_values(array_unique(array_merge($this->with, ['vehicle'])));
        }
    }

    /** @var array<int,string> */
    protected $appends = [
        'portal_url',
        'total_formatted',
        'deposit_formatted',
        'hold_formatted',
        'amount_paid_formatted',
        'balance_due_formatted',
        'deposit_paid_formatted',
        'deposit_due_formatted',
        'deposit_paid_so_far_formatted',
        'customer_name',
        'customer_real_name',
        'car_label',
        'brand_label',
    ];

    public function getPaidAmountDollarsAttribute(): string
{
    return number_format(($this->paid_amount ?? 0) / 100, 2, '.', '');
}

public function getRemainingDueAttribute(): int
{
    $total = (int) ($this->total_amount ?? 0);
    $paid  = (int) ($this->paid_amount ?? 0);
    return max($total - $paid, 0);
}

    /* =========================================================================
     | Boot & token handling (matches your snippet)
     |=========================================================================*/

    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            // Reference (human-ish id)
            if (empty($booking->reference)) {
                do {
                    $candidate = 'BK-' . now()->format('ymdHis') . '-' . Str::upper(Str::random(3));
                } while (self::where('reference', $candidate)->exists());
                $booking->reference = $candidate;
            }

            // Currency default
            if (empty($booking->currency)) {
                $booking->currency = 'NZD';
            }

            // Infer brand from source_system if not provided
            if (Schema::hasColumn($booking->getTable(), 'brand') && empty($booking->brand)) {
                $booking->brand = self::inferBrandFromSource($booking->source_system);
            }

            // === Your request: create a 40-char token if empty ===
            if (empty($booking->portal_token)) {
                $booking->portal_token = Str::random(40);
            }
        });

        static::saving(function (self $booking) {
            // Backfill brand if missing
            if (Schema::hasColumn($booking->getTable(), 'brand') && empty($booking->brand)) {
                $booking->brand = self::inferBrandFromSource($booking->source_system);
            }

            // Ensure a 40-char token exists prior to save (idempotent)
            $booking->ensurePortalToken();
        });
    }

    /** Ensure we have a token and persist if missing (as requested). */
    public function ensurePortalToken(): self
    {
        if (empty($this->portal_token)) {
            $this->portal_token = Str::random(40);
            // Avoid recursion in unsaved "creating": save only if the model exists or is dirty outside creating
            if ($this->exists) {
                $this->save();
            }
        }
        return $this;
    }

    /* =========================================================================
     | Relationships
     |=========================================================================*/

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Deposits relation (prefers booking_id, falls back to booking_reference). */
    public function deposits(): HasMany
    {
        if (Schema::hasTable('deposits') && Schema::hasColumn('deposits', 'booking_id')) {
            return $this->hasMany(\App\Models\Deposit::class, 'booking_id', 'id');
        }

        return $this->hasMany(\App\Models\Deposit::class, 'booking_reference', 'reference');
    }

    /** Vehicle is optional. */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Vehicle::class);
    }

    /* =========================================================================
     | Accessors / Labels
     |=========================================================================*/

    public function getCustomerNameAttribute(): string
    {
        $first = trim((string) data_get($this->customer, 'first_name', ''));
        $last  = trim((string) data_get($this->customer, 'last_name', ''));
        $full  = trim($first . ' ' . $last);
        if ($full !== '') return $full;

        return trim((string) data_get($this->meta, 'customer.name', ''));
    }

    public function getCustomerRealNameAttribute(): string
    {
        $fullFromModel = trim((string) data_get($this->customer, 'full_name', ''));
        if ($fullFromModel !== '') return $fullFromModel;

        $basic = $this->customer_name;
        if ($basic !== '') return $basic;

        $email = (string) data_get($this->customer, 'email', '');
        return $email ?: '';
    }

    /** Human label for the car being hired. */
    public function getCarLabelAttribute(): string
    {
        if (class_exists(\App\Models\Vehicle::class) && $this->relationLoaded('vehicle') && $this->vehicle) {
            $v = $this->vehicle;
            $display = trim((string) data_get($v, 'display_name', ''));
            if ($display !== '') return $display;

            $parts = array_filter([
                trim((string) data_get($v, 'make', '')),
                trim((string) data_get($v, 'model', '')),
            ]);
            $label = trim(implode(' ', $parts));

            $plate = trim((string) data_get($v, 'plate', ''));
            if ($plate !== '') {
                $label = $label !== '' ? ($label . ' • ' . $plate) : $plate;
            }

            if ($label !== '') return $label;
        }

        return (string) ($this->vehicle ?? '');
    }

    /** Nice label for brand (used in badges/tooltips). */
    public function getBrandLabelAttribute(): string
    {
        $brand = strtolower((string) ($this->brand ?? $this->source_system ?? ''));
        return $brand === 'jimny' ? 'Jimny' : 'Dream Drives';
    }

    /** === Your request: portal URL accessor that guarantees a token === */
    public function getPortalUrlAttribute(): string
    {
        $this->ensurePortalToken();
        // You asked for this exact call signature:
        return route('portal.pay.token', $this->portal_token);
    }

    /* =========================================================================
     | Money (raw cents)
     |=========================================================================*/

    public function getAmountPaidAttribute(): int
    {
        return $this->sumCentsFrom('payments', 'amount', $this->paidStatuses());
    }

    public function getDepositPaidAttribute(): int
    {
        $sum = 0;

        if (
            Schema::hasTable('deposits') &&
            Schema::hasColumn('deposits', 'booking_id') &&
            Schema::hasColumn('deposits', 'amount')
        ) {
            $dq = DB::table('deposits')->where('booking_id', $this->id);

            if (Schema::hasColumn('deposits', 'status')) {
                $dq->whereIn('status', $this->paidStatuses());
            }

            $sum += (int) $dq->sum('amount');
            if ($sum > 0) {
                return $sum;
            }
        }

        if (Schema::hasTable('payments')) {
            $pq = DB::table('payments');

            if (Schema::hasColumn('payments', 'booking_reference')) {
                $pq->where('booking_reference', $this->reference);
            } elseif (Schema::hasColumn('payments', 'booking_id')) {
                $pq->where('booking_id', $this->id);
            }

            if (Schema::hasColumn('payments', 'purpose')) {
                $pq->where('purpose', 'deposit');
            } elseif (Schema::hasColumn('payments', 'type')) {
                $pq->where('type', 'deposit');
            }

            if (Schema::hasColumn('payments', 'status')) {
                $pq->whereIn('status', $this->paidStatuses());
            }

            $sum += (int) $pq->sum('amount');
        }

        return $sum;
    }

    public function getDepositPaidSoFarAttribute(): int
    {
        return (int) $this->deposit_paid;
    }

    public function getBalanceDueAttribute(): int
    {
        $total = (int) ($this->total_amount ?? 0);
        $paid  = (int) ($this->amount_paid ?? 0);
        return max($total - $paid, 0);
    }

    public function getDepositDueAttribute(): int
    {
        $required = (int) ($this->deposit_amount ?? 0);
        $paid     = (int) ($this->deposit_paid ?? 0);
        return max($required - $paid, 0);
    }

    /* =========================================================================
     | Money (formatted)
     |=========================================================================*/

    public function getTotalFormattedAttribute(): string
    {
        return $this->formatCents($this->total_amount);
    }

    public function getDepositFormattedAttribute(): string
    {
        return $this->formatCents($this->deposit_amount);
    }

    public function getHoldFormattedAttribute(): string
    {
        return $this->formatCents($this->hold_amount);
    }

    public function getAmountPaidFormattedAttribute(): string
    {
        return $this->formatCents($this->amount_paid);
    }

    public function getDepositPaidFormattedAttribute(): string
    {
        return $this->formatCents($this->deposit_paid);
    }

    public function getDepositPaidSoFarFormattedAttribute(): string
    {
        return $this->formatCents($this->deposit_paid_so_far);
    }

    public function getDepositDueFormattedAttribute(): string
    {
        return $this->formatCents($this->deposit_due);
    }

    public function getBalanceDueFormattedAttribute(): string
    {
        return $this->formatCents($this->balance_due);
    }

    /* =========================================================================
     | Scopes
     |=========================================================================*/

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('start_at', '>=', now())->orderBy('start_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('start_at', '<=', now())->where('end_at', '>=', now());
    }

    /** Bookings missing a deposit amount OR missing customer first+last name. */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('deposit_amount')
              ->orWhere('deposit_amount', 0)
              ->orWhereHas('customer', function (Builder $cq) {
                  $cq->whereNull('first_name')->whereNull('last_name');
              });
        });
    }

    /** Filter by brand (jimny / dreamdrives). */
    public function scopeBrand(Builder $query, string $brand): Builder
    {
        return $query->where(function (Builder $q) use ($brand) {
            $q->when(Schema::hasColumn($this->getTable(), 'brand'), function (Builder $qb) use ($brand) {
                $qb->where('brand', $brand);
            })->orWhere(function (Builder $qb) use ($brand) {
                $map = $brand === 'jimny' ? ['jimny', 'vevs_jimny'] : ['dreamdrives', 'dd', ''];
                $qb->whereIn('source_system', $map);
            });
        });
    }

    /* =========================================================================
     | Internals
     |=========================================================================*/

    /** @return array<int,string> */
    protected function paidStatuses(): array
    {
        return ['succeeded', 'paid', 'captured', 'completed'];
    }

    protected function sumCentsFrom(string $relation, string $column = 'amount', ?array $statuses = null): int
    {
        if (!method_exists($this, $relation)) {
            return 0;
        }

        $rel = $this->{$relation}();

        try {
            if ($statuses && Schema::hasColumn($rel->getModel()->getTable(), 'status')) {
                $rel = $rel->whereIn('status', $statuses);
            }
        } catch (\Throwable) {
            // ignore if table/column metadata cannot be inspected
        }

        try {
            return (int) $rel->sum($column);
        } catch (\Throwable) {
            return 0;
        }
    }

    private static function inferBrandFromSource(?string $source): ?string
    {
        $s = strtolower((string) $source);
        return match ($s) {
            'jimny', 'vevs_jimny'      => 'jimny',
            'dreamdrives', 'dd', ''    => 'dreamdrives', // default to dreamdrives if unknown/empty
            default                    => $s ?: null,
        };
    }

    private function formatCents(?int $cents): string
    {
        $cents = (int) ($cents ?? 0);
        return '$' . number_format($cents / 100, 2);
    }
}
