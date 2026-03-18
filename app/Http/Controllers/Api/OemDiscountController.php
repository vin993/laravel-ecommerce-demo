<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OemDiscountSetting;

class OemDiscountController extends Controller
{
    public function getSettings()
    {
        $settings = OemDiscountSetting::current();
        
        return response()->json([
            'enabled' => $settings->enabled,
            'percentage' => floatval($settings->percentage)
        ]);
    }
}
