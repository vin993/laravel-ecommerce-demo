<?php

namespace App\Services\Dropship;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PartsUnlimitedDropshipService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('PARTS_UNLIMITED_API_BASE_URL', 'https://api.parts-unlimited.com/api'), '/');
        $this->apiKey = env('PARTS_UNLIMITED_API_KEY');
    }

    public function checkAvailability($sku)
    {
        if (!$this->apiKey) {
            return null;
        }

        try {
            Log::info('Parts Unlimited API Request', [
                'url' => "{$this->baseUrl}/v1/parts/pricing/{$sku}",
                'sku' => $sku,
                'has_api_key' => !empty($this->apiKey)
            ]);

            // Get both pricing and inventory in parallel
            $pricingResponse = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(15)
              ->retry(2, 1000)
              ->get("{$this->baseUrl}/v1/parts/pricing/{$sku}");

            $inventoryResponse = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(15)
              ->retry(2, 1000)
              ->get("{$this->baseUrl}/v1/parts/inventory/{$sku}");

            Log::info('Parts Unlimited API Response', [
                'pricing_status' => $pricingResponse->status(),
                'inventory_status' => $inventoryResponse->status(),
                'pricing_successful' => $pricingResponse->successful(),
                'inventory_successful' => $inventoryResponse->successful()
            ]);

            $available = false;
            $price = 0;
            $inventory = 0;
            $name = null;

            // Process pricing response
            if ($pricingResponse->successful()) {
                $pricingData = $pricingResponse->json();

                if (isset($pricingData['pricing']) && !empty($pricingData['pricing'])) {
                    $part = $pricingData['pricing'][0];

                    $price = $part['retailPrice'] ?? $part['basePrice'] ?? $part['dealerPrice'] ?? 0;
                    $name = $part['partNumber'] ?? null;

                    if ($price > 0 && $part['found'] === true) {
                        $available = true;
                    }
                }
            }

            // Process inventory response
            if ($inventoryResponse->successful()) {
                $inventoryData = $inventoryResponse->json();
                
                if (isset($inventoryData['availability']) && !empty($inventoryData['availability'])) {
                    $part = $inventoryData['availability'][0];
                    $inventory = 0;
                    
                    // Sum up inventory from all warehouses
                    if (isset($part['warehouses'])) {
                        foreach ($part['warehouses'] as $warehouse) {
                            $qty = (int) ($warehouse['quantity'] ?? 0);
                            if ($qty > 0) {
                                $inventory += $qty;
                            }
                        }
                    }
                    
                    // Only mark as available if we have both price and inventory
                    if ($inventory > 0 && $price > 0) {
                        $available = true;
                    } else if ($inventory <= 0) {
                        $available = false;
                    }
                }
            }

            return [
                'available' => $available,
                'price' => $price,
                'inventory' => $inventory,
                'parts_unlimited_sku' => $sku,
                'name' => $name,
                'source' => 'parts_unlimited'
            ];

        } catch (Exception $e) {
            Log::warning('Parts Unlimited API error', [
                'sku' => $sku,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'parts_unlimited_sku' => null,
                'name' => null,
                'error' => 'API temporarily unavailable'
            ];
        }
    }

    public function createOrder($cartItems, $shippingInfo, $dealerNumber = null)
    {
        if (!$this->apiKey) {
            throw new Exception('Parts Unlimited API key not configured');
        }
        
        // Check if we're in test mode
        $testMode = env('PARTS_UNLIMITED_TEST_MODE', false);
        
        if ($testMode) {
            Log::channel('parts_unlimited_orders')->info('Parts Unlimited TEST MODE - Order NOT submitted to API', [
                'test_mode' => true,
                'items_count' => count($cartItems),
                'customer' => $shippingInfo['ship_name'] ?? 'Unknown',
                'note' => 'This is a test order simulation - no real order placed'
            ]);
            
            return [
                'success' => true,
                'po_number' => 'TEST-PU-' . time(),
                'reference_number' => 'TEST-REF-' . rand(100000, 999999),
                'status_code' => 200,
                'status_message' => 'TEST ORDER - Not submitted to Parts Unlimited API',
                'order_total' => array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cartItems)),
                'test_mode' => true
            ];
        }

        // Create PO number max 20 chars: MP-PU-XXXXXXXXXX (17 chars)
        $poNumber = 'MP-PU-' . substr(time(), -6) . rand(1000, 9999);
        $dealerNumber = $dealerNumber ?? env('PARTS_UNLIMITED_DEALER_NUMBER');

        if (!$dealerNumber) {
            throw new Exception('Parts Unlimited dealer number not configured');
        }

        Log::channel('parts_unlimited_orders')->info('Parts Unlimited Order Creation Started', [
            'po_number' => $poNumber,
            'dealer_number' => $dealerNumber,
            'items_count' => count($cartItems),
            'customer' => $shippingInfo['ship_name'] ?? 'Unknown',
            'email' => $shippingInfo['email'] ?? 'Unknown',
            'cart_items' => $cartItems
        ]);

        try {
            // Prepare line items
            $lineItems = [];
            foreach ($cartItems as $index => $item) {
                $lineItems[] = [
                    'line_number' => $index + 1,
                    'part_number' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'] ?? 0
                ];
            }

            // Prepare shipping address
            $shipToAddress = [
                'name' => $shippingInfo['ship_name'] ?? '',
                'address_line_1' => $shippingInfo['ship_address1'] ?? '',
                'address_line_2' => $shippingInfo['ship_address2'] ?? '',
                'city' => $shippingInfo['ship_city'] ?? '',
                'state' => $shippingInfo['ship_state'] ?? '',
                'postal_code' => $shippingInfo['ship_zip'] ?? '',
                'country' => 'US'
            ];

            // Create order payload
            $orderPayload = [
                'dealer_number' => $dealerNumber,
                'purchase_order_number' => $poNumber,
                'shipping_method' => 'ground',
                'validate_price' => false, // Set to false for initial testing
                'cancellation_policy' => 'back_order',
                'ship_to_address' => $shipToAddress,
                'line_items' => $lineItems
            ];

            Log::channel('parts_unlimited_orders')->info('Parts Unlimited Order Payload', [
                'po_number' => $poNumber,
                'payload' => $orderPayload
            ]);

            // Submit order
            Log::channel('parts_unlimited_orders')->info('Sending order to Parts Unlimited API', [
                'po_number' => $poNumber,
                'url' => "{$this->baseUrl}/v2/orders/dropship",
                'payload' => $orderPayload
            ]);
            
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->timeout(30)
              ->post("{$this->baseUrl}/v2/orders/dropship", $orderPayload);

            Log::channel('parts_unlimited_orders')->info('Parts Unlimited API Response', [
                'po_number' => $poNumber,
                'http_status' => $response->status(),
                'response_headers' => $response->headers(),
                'response_body' => $response->body(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                $success = isset($responseData['status_code']) && 
                          in_array($responseData['status_code'], [200, 201, 202, 203]);

                if ($success) {
                    Log::channel('parts_unlimited_orders')->info('Parts Unlimited Order Successful', [
                        'po_number' => $poNumber,
                        'reference_number' => $responseData['reference_number'] ?? null,
                        'status_code' => $responseData['status_code'],
                        'order_total' => $responseData['order_total'] ?? 0
                    ]);

                    return [
                        'success' => true,
                        'po_number' => $poNumber,
                        'reference_number' => $responseData['reference_number'] ?? null,
                        'status_code' => $responseData['status_code'],
                        'status_message' => $responseData['status_message'] ?? 'Order placed successfully',
                        'order_total' => $responseData['order_total'] ?? 0
                    ];
                } else {
                    $errorMessage = $responseData['status_message'] ?? 'Order failed';
                    
                    Log::channel('parts_unlimited_orders')->error('Parts Unlimited Order Failed', [
                        'po_number' => $poNumber,
                        'status_code' => $responseData['status_code'] ?? 'unknown',
                        'error_message' => $errorMessage
                    ]);

                    return [
                        'success' => false,
                        'error' => "Order failed: {$errorMessage} (Code: " . ($responseData['status_code'] ?? 'unknown') . ")"
                    ];
                }
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            Log::channel('parts_unlimited_orders')->error('Parts Unlimited Order Creation Failed', [
                'po_number' => $poNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'cart_items' => $cartItems,
                'shipping_info' => $shippingInfo
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getOrders($startDate, $endDate, $pageNum = 1)
    {
        try {
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/v2/orders/dropship", [
                'order_date_start' => $startDate,
                'order_date_end' => $endDate,
                'ship_date_start' => $startDate,
                'ship_date_end' => $endDate,
                'page_num' => $pageNum
            ]);

            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function testConnection()
    {
        try {
            // Test with a common motorcycle part SKU
            $testSku = 'b10es'; // NGK spark plug - commonly available
            
            $response = Http::withHeaders([
                'api-key' => $this->apiKey,
                'Accept' => 'application/json',
            ])->timeout(10)->get("{$this->baseUrl}/v1/parts/pricing/{$testSku}");

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'has_data' => $response->successful() && !empty($response->json())
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}