<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationEvent extends Model
{
    protected $fillable = ['communication_id','event','occurred_at','payload'];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function communication(): BelongsTo
    {
        return $this->belongsTo(Communication::class);
    }
}
