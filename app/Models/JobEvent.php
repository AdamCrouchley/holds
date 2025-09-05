<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class JobEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'type',
        'amount_cents',
        'payload',
        'user_id',
        'occurred_at',
    ];

    protected $casts = [
        'payload'      => 'array',
        'amount_cents' => 'integer',
        'occurred_at'  => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function job()
    {
        return $this->belongsTo(Job::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
