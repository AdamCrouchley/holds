<?php

namespace App\Domain\Holds\Models;

use Illuminate\Database\Eloquent\Model;

class Job extends Model
{
    protected $fillable = [
        'flow_id','customer_id','reference','start_at','finish_at','item_ref','status'
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'finish_at'=> 'datetime',
    ];
}
