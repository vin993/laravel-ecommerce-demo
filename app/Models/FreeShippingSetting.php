<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FreeShippingSetting extends Model
{
    protected $fillable = [
        'threshold',
        'header_text',
        'enabled',
        'flat_rate_enabled',
        'flat_rate_amount'
    ];

    protected $casts = [
        'threshold' => 'decimal:2',
        'flat_rate_amount' => 'decimal:2',
        'enabled' => 'boolean',
        'flat_rate_enabled' => 'boolean'
    ];

    public static function current()
    {
        return self::first() ?? new self([
            'threshold' => 75.00,
            'header_text' => 'FREE SHIPPING on orders $75 and up!',
            'enabled' => true,
            'flat_rate_enabled' => false,
            'flat_rate_amount' => 9.99
        ]);
    }
}
