<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    protected $fillable = [
        'booking_id',
        'customer_id',
        'amount_cents',
        'currency',
        'status', // authorized|captured|released|canceled|failed
        'stripe_payment_intent',
        'stripe_payment_method',
        'last4',
        'card_brand',
        'authorized_at',
        'captured_at',
        'released_at',
        'canceled_at',
        'failure_code',
        'failure_message',
        'meta',
    ];

    protected $casts = [
        'authorized_at' => 'datetime',
        'captured_at'   => 'datetime',
        'released_at'   => 'datetime',
        'canceled_at'   => 'datetime',
        'meta'          => 'array',
    ];

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
