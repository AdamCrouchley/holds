<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'booking_id',
        'customer_id',

        // Money
        'amount',                     // integer cents
        'currency',                   // e.g., NZD

        // Status
        'status',                     // pending | succeeded | failed | canceled

        // Classification
        'type',                       // booking_deposit | booking_balance | post_hire | bond_hold | bond_capture | refund, etc.
        'purpose',                    // optional free-form reason/purpose
        'mechanism',                  // e.g., card | bank_transfer | cash

        // Stripe references
        'stripe_payment_intent_id',
        'stripe_payment_method_id',
        'stripe_charge_id',

        // Extra details
        'details',                    // json
    ];

    /**
     * Casts.
     */
    protected $casts = [
        'amount'  => 'integer',
        'details' => 'array',
    ];

    /**
     * Relationships.
     */
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Helpers.
     */
    public function getAmountFormattedAttribute(): string
    {
        $cur = $this->currency ?: 'NZD';
        return $cur . ' ' . number_format(($this->amount ?? 0) / 100, 2);
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
