<?php
// app/Models/JobAccessToken.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class JobAccessToken extends Model
{
    protected $fillable = ['job_id','token','purpose','expires_at','used_at'];
    protected $casts = ['expires_at'=>'datetime','used_at'=>'datetime'];

    protected static function booted() {
        static::creating(function ($m) {
            $m->token = $m->token ?? (string) Str::uuid();
        });
    }

    public function job() { return $this->belongsTo(Job::class); }

    public function isValid(): bool {
        return is_null($this->used_at) && (is_null($this->expires_at) || now()->lt($this->expires_at));
    }
}
