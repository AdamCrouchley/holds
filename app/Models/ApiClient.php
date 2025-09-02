<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiClient extends Model
{
    protected $fillable = ['name','token','scopes','user_id','enabled'];
    protected $casts = ['scopes' => 'array', 'enabled' => 'boolean'];
}
