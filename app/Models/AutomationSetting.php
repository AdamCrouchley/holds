<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AutomationSetting extends Model
{
    protected $fillable = [
        'active','send_balance_days_before','send_bond_days_before','send_at_local','timezone','meta'
    ];
    protected $casts = ['meta' => 'array'];
}
