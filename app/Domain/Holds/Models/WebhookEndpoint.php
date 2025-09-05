<?php

namespace App\Domain\Holds\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    protected $fillable = ['brand_id','url','secret','events','active'];
    protected $casts = ['events'=>'array','active'=>'boolean'];
}
