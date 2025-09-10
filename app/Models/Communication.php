<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Communication extends Model
{
    protected $fillable = [
        'job_id','booking_id','deposit_id',
        'channel','type','to_email','subject',
        'provider','provider_message_id','smtp_message_id',
        'status','meta',
    ];

    protected $casts = ['meta' => 'array'];

    public function events(): HasMany
    {
        return $this->hasMany(CommunicationEvent::class);
    }
}
