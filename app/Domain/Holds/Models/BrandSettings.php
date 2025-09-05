<?php

namespace App\Domain\Holds\Models;

use Illuminate\Database\Eloquent\Model;

class BrandSettings extends Model
{
    protected $fillable = [
        'brand_id','logo_path','from_email','from_name','reply_to','bcc_all','currency','timezone','sms_enabled'
    ];
    protected $casts = ['sms_enabled'=>'boolean'];
}
