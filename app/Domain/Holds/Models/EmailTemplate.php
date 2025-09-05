<?php

namespace App\Domain\Holds\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = ['brand_id','event','subject','body_html','body_text','locale'];
}
