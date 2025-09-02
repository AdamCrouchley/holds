<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    protected $fillable = [
        'booking_id','type','amount','currency','due_at','status',
        'idempotency_key','source_system','source_id','meta'
    ];
    protected $casts = ['meta' => 'array','due_at' => 'datetime'];

    public function booking() { return $this->belongsTo(\App\Models\Booking::class); }
}
