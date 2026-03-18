<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FreeShippingSetting;
use Illuminate\Http\Request;

class FreeShippingController extends Controller
{
    public function index()
    {
        $settings = FreeShippingSetting::current();

        return view('admin.free-shipping.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'threshold' => 'required|numeric|min:0',
            'header_text' => 'required|string|max:255',
            'flat_rate_amount' => 'required|numeric|min:0',
            'enabled' => 'boolean',
            'flat_rate_enabled' => 'boolean'
        ]);

        $settings = FreeShippingSetting::first();

        if ($settings) {
            $settings->update([
                'threshold' => $validated['threshold'],
                'header_text' => $validated['header_text'],
                'flat_rate_amount' => $validated['flat_rate_amount'],
                'enabled' => $request->has('enabled'),
                'flat_rate_enabled' => $request->has('flat_rate_enabled')
            ]);
        } else {
            FreeShippingSetting::create([
                'threshold' => $validated['threshold'],
                'header_text' => $validated['header_text'],
                'flat_rate_amount' => $validated['flat_rate_amount'],
                'enabled' => $request->has('enabled'),
                'flat_rate_enabled' => $request->has('flat_rate_enabled')
            ]);
        }

        return redirect()
            ->route('admin.free-shipping.index')
            ->with('success', 'Shipping settings updated successfully');
    }
}
