<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OemDiscountSetting extends Model
{
    protected $fillable = [
        'enabled',
        'percentage'
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'percentage' => 'decimal:2'
    ];

    /**
     * Get current OEM discount settings
     * Returns first record or creates default if none exists
     */
    public static function current()
    {
        return self::first() ?? new self([
            'enabled' => true,
            'percentage' => 15.00
        ]);
    }
}
