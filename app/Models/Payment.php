<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        // Foreign keys
        'booking_id',
        'job_id',
        'customer_id',

        // Linking reference (booking/job reference string)
        'reference',

        // Money (stored as cents)
        'amount_cents',   // integer cents
        'currency',       // e.g., NZD

        // Status
        'status',         // pending | succeeded | failed | canceled

        // Classification
        'type',           // booking_deposit | booking_balance | post_hire | bond_hold | bond_capture | refund, etc.
        'purpose',        // optional free-form reason/purpose
        'mechanism',      // card | bank_transfer | cash | etc.

        // PSP / Stripe references
        'stripe_payment_intent_id',
        'stripe_payment_method_id',
        'stripe_charge_id',

        // Extra details
        'details',        // json
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'amount_cents' => 'integer',
        'details'      => 'array',
    ];

    /**
     * Model boot hooks.
     */
    protected static function booted(): void
    {
        static::saving(function (Payment $payment) {
            // If booking not chosen but we have a reference, try to auto-link.
            if (! $payment->booking_id && $payment->reference) {
                $bookingId = Booking::where('reference', $payment->reference)->value('id');

                if (! $bookingId) {
                    // Fallback: find a Job with the same reference and use its booking_id
                    $bookingId = Job::where('reference', $payment->reference)->value('booking_id');
                }

                if ($bookingId) {
                    $payment->booking_id = $bookingId;
                }
            }
        });
    }

    /**
     * Relationships.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Helpers.
     */
    public function getAmountFormattedAttribute(): string
    {
        $cur = $this->currency ?: 'NZD';
        return $cur . ' ' . number_format(($this->amount_cents ?? 0) / 100, 2);
    }

    /**
     * Quick status checks.
     */
    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
