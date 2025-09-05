<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

class Job extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Mass-assignable attributes.
     * (Adjust to match your migrations.)
     */
    protected $fillable = [
        // Foreign keys / ownership
        'flow_id',
        'brand_id',
        'booking_id',
        'vehicle_id',
        'created_by',

        // Customer & timing
        'external_reference',
        'customer_name',
        'customer_email',
        'customer_phone',
        'billing_address',
        'start_at',
        'end_at',
        'actual_completed_at',

        // Money (stored in cents)
        'charge_amount_cents',
        'hold_amount_cents',
        'captured_amount_cents',
        'authorized_amount_cents',

        // PSP fields
        'psp',
        'psp_authorization_id',
        'psp_payment_method_id',
        'psp_customer_id',

        // Misc
        'status',
        'meta',
        'comms_log',
        'updated_by',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'billing_address'         => 'array',
        'meta'                    => 'array',
        'comms_log'               => 'array',
        'start_at'                => 'datetime',
        'end_at'                  => 'datetime',
        'actual_completed_at'     => 'datetime',
        'charge_amount_cents'     => 'integer',
        'hold_amount_cents'       => 'integer',
        'captured_amount_cents'   => 'integer',
        'authorized_amount_cents' => 'integer',
    ];

    /**
     * Computed attributes for JSON/array output.
     */
    protected $appends = [
        'paid_amount_cents',
        'remaining_amount_cents',
    ];

    /* =========================================================================
     | Relationships
     |=========================================================================*/

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(JobEvent::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'job_id');
    }

    /* =========================================================================
     | Aggregates
     |=========================================================================*/

    public function getPaidAmountCentsAttribute(): int
    {
        return (int) $this->payments()
            ->where('status', 'succeeded')
            ->sum('amount_cents');
    }

    public function getRemainingAmountCentsAttribute(): int
    {
        $charge = (int) ($this->charge_amount_cents ?? 0);
        return max($charge - $this->paid_amount_cents, 0);
    }

    /* =========================================================================
     | Accessors
     |=========================================================================*/

    public function chargeAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) ($this->charge_amount_cents ?? 0),
        );
    }

    public function holdAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => (int) ($this->hold_amount_cents ?? 0),
        );
    }

    /* =========================================================================
     | URL Helpers
     |=========================================================================*/

    /**
     * Generate a signed payment URL for this job.
     * Link is valid for 7 days.
     */
    public function payUrl(?int $amountCents = null): string
    {
        $params = ['job' => $this->getKey()];

        if (! is_null($amountCents)) {
            $params['amount_cents'] = $amountCents;
        }

        return URL::temporarySignedRoute(
            'portal.pay.job',
            now()->addDays(7),
            $params
        );
    }
}
