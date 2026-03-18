<?php

return [
    /*
    |--------------------------------------------------------------------------
    | OEM Parts Discount Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the discount settings for OEM parts from PartStream.
    | These settings can be overridden in the .env file.
    |
    */

    // Enable or disable OEM parts discount
    'oem_discount_enabled' => env('OEM_DISCOUNT_ENABLED', true),

    // Discount percentage to apply to OEM parts (default: 15%)
    'oem_discount_percentage' => env('OEM_DISCOUNT_PERCENTAGE', 15),
];
