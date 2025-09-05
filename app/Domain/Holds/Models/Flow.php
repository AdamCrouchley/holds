<?php

namespace App\Domain\Holds\Models;

use Illuminate\Database\Eloquent\Model;

class Flow extends Model
{
    protected $fillable = [
        'brand_id','name','bond_amount_cents','currency','auto_renew','auto_release_hours','capture_rule'
    ];
}
