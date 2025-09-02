<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    protected $fillable = [
        'booking_id',
        'booking_reference', // keep during transition
        'amount',
        'status',
        // other columns...
    ];

    public function booking()
    {
        return $this->belongsTo(\App\Models\Booking::class, 'booking_id', 'id');
    }
}
