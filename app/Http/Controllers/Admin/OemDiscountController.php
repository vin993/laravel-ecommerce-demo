<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OemDiscountSetting;
use Illuminate\Http\Request;

class OemDiscountController extends Controller
{
    /**
     * Display OEM discount settings form
     */
    public function index()
    {
        $settings = OemDiscountSetting::current();

        return view('admin.oem-discount.index', compact('settings'));
    }

    /**
     * Update OEM discount settings
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'percentage' => 'required|numeric|min:0|max:100',
            'enabled' => 'boolean'
        ]);

        $settings = OemDiscountSetting::first();

        if ($settings) {
            $settings->update([
                'percentage' => $validated['percentage'],
                'enabled' => $request->has('enabled')
            ]);
        } else {
            OemDiscountSetting::create([
                'percentage' => $validated['percentage'],
                'enabled' => $request->has('enabled')
            ]);
        }

        return redirect()
            ->route('admin.oem-discount.index')
            ->with('success', 'OEM discount settings updated successfully');
    }
}
