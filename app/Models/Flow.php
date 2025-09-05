<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Flow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'hold_amount_cents',
        'auto_renew_days',
        'auto_release_days',
        'allow_partial_capture',
        'auto_capture_on_damage',
        'auto_cancel_if_no_capture',
        'auto_cancel_after_days',
        'required_fields',
        'comms',
        'webhooks',
        'tags',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'required_fields'         => 'array',
        'comms'                   => 'array',
        'webhooks'                => 'array',
        'tags'                    => 'array',
        'hold_amount_cents'       => 'integer',
        'auto_renew_days'         => 'integer',
        'auto_release_days'       => 'integer',
        'allow_partial_capture'   => 'boolean',
        'auto_capture_on_damage'  => 'boolean',
        'auto_cancel_if_no_capture' => 'boolean',
        'auto_cancel_after_days'  => 'integer',
    ];

    /**
     * Dollars virtual attribute for the UI.
     */
    protected function holdAmountDollars(): Attribute
    {
        return Attribute::get(function () {
            $cents = $this->attributes['hold_amount_cents'] ?? null;
            return $cents === null ? null : $cents / 100;
        })->set(function ($value) {
            // Accept strings like "1,000.50" or "$1,000.50"
            $norm = preg_replace('/[^0-9.\-]/', '', (string) $value);
            $this->attributes['hold_amount_cents'] = (int) round(((float) $norm) * 100);
        });
    }

    /**
     * Relationships
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
