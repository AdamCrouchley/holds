<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKey extends Model
{
    protected $fillable = ['name', 'key', 'scopes', 'active', 'notes'];
    protected $casts    = ['scopes' => 'array', 'active' => 'bool'];
}
