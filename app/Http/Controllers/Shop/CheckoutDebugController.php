<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CheckoutDebugController extends Controller
{
    public function testLog(Request $request)
    {
        $debugInfo = [
            'timestamp' => now()->toDateTimeString(),
            'request_data' => $request->all(),
            'session_data' => [
                'has_cart_items' => session()->has('cart_items'),
                'cart_count' => count(session('cart_items', [])),
                'has_shipping' => session()->has('checkout_shipping'),
                'has_ari_items' => session()->has('ari_cart_items'),
                'ari_count' => count(session('ari_cart_items', [])),
            ]
        ];

        try {
            \Log::info('Checkout debug test', $debugInfo);
            $logStatus = 'Log written successfully';
        } catch (\Exception $e) {
            $logStatus = 'Log failed: ' . $e->getMessage();
        }

        try {
            Storage::disk('public')->put(
                'checkout_debug.json',
                json_encode($debugInfo, JSON_PRETTY_PRINT)
            );
            $fileStatus = 'File written successfully';
        } catch (\Exception $e) {
            $fileStatus = 'File write failed: ' . $e->getMessage();
        }

        return response()->json([
            'success' => true,
            'log_status' => $logStatus,
            'file_status' => $fileStatus,
            'debug_info' => $debugInfo
        ]);
    }

    public function processPaymentDebug(Request $request)
    {
        $debugData = [
            'timestamp' => now()->toDateTimeString(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'has_payment_intent_id' => $request->has('payment_intent_id'),
            'payment_intent_id' => $request->input('payment_intent_id'),
            'all_inputs' => $request->all(),
            'session' => [
                'has_checkout_shipping' => session()->has('checkout_shipping'),
                'has_cart_items' => session()->has('cart_items'),
                'cart_count' => count(session('cart_items', [])),
                'ari_count' => count(session('ari_cart_items', [])),
            ]
        ];

        try {
            Storage::disk('public')->put(
                'payment_debug_' . now()->format('Y-m-d_H-i-s') . '.json',
                json_encode($debugData, JSON_PRETTY_PRINT)
            );
        } catch (\Exception $e) {
            $debugData['file_error'] = $e->getMessage();
        }

        return response()->json([
            'success' => true,
            'message' => 'Debug data captured',
            'data' => $debugData
        ]);
    }
}
