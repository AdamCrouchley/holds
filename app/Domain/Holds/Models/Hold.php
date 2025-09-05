<?php

namespace App\Domain\Holds\Models;

use Illuminate\Database\Eloquent\Model;

class Hold extends Model
{
    protected $fillable = [
        'job_id','provider_id','status','amount_cents','currency','last_renewed_at','expires_at','captured_amount_cents','failure_code','failure_msg','timeline_json'
    ];

    protected $casts = [
        'last_renewed_at' => 'datetime',
        'expires_at'      => 'datetime',
        'timeline_json'   => 'array',
    ];
}
