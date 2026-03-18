<?php

namespace App\Services\Dropship;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class HelmetHouseDropshipService
{
    protected $baseUrl;
    protected $apiToken;
    protected $dealerNumber;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('HELMET_HOUSE_API_BASE_URL', 'https://dealer.helmethouse.com/cgi-bin/hhapi.pl'), '/');
        $this->apiToken = env('HELMET_HOUSE_API_TOKEN');
        $this->dealerNumber = env('HELMET_HOUSE_DEALER_NUMBER');
    }

    public function checkAvailability($sku)
    {
        if (!$this->apiToken || !$this->dealerNumber) {
            return null;
        }

        // Get the Helmet House SKU (either direct match or from mapping)
        $helmetHouseSku = $this->getHelmetHouseSku($sku);
        if (!$helmetHouseSku) {
            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'helmet_house_sku' => null,
                'name' => null,
                'source' => 'helmet_house'
            ];
        }

        try {
            Log::info('Helmet House API Request', [
                'url' => $this->baseUrl . '?req=part',
                'sku' => $sku,
                'dealer_number' => $this->dealerNumber,
                'has_token' => !empty($this->apiToken)
            ]);

            $response = Http::withHeaders([
                'api-key' => $this->apiToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->timeout(15)
              ->retry(2, 1000)
              ->post($this->baseUrl . '?req=part', [
                'dealer_number' => $this->dealerNumber,
                'part_number' => $helmetHouseSku
              ]);

            Log::info('Helmet House API Response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body()
            ]);

            $available = false;
            $price = 0;
            $inventory = 0;
            $name = null;

            if ($response->successful()) {
                $data = $response->json();
                
                // Accept both Active and Closeout items
                if (isset($data['status']) && in_array($data['status'], ['Active', 'Closeout'])) {
                    $inventory = (int) ($data['quantity_available'] ?? 0);
                    $price = (float) ($data['retail_price'] ?? $data['map_price'] ?? $data['price'] ?? 0);
                    $available = $inventory > 0 && $price > 0;
                    $name = $sku;
                    
                    return [
                        'available' => $available,
                        'price' => $price,
                        'inventory' => $inventory,
                        'helmet_house_sku' => $sku,
                        'name' => $name,
                        'map_price' => (float) ($data['map_price'] ?? 0),
                        'retail_price' => (float) ($data['retail_price'] ?? 0),
                        'weight' => (float) ($data['weight'] ?? 0),
                        'eta' => $data['eta'] ?? null,
                        'warehouses' => [
                            'east' => [
                                'quantity' => (int) ($data['quantity_available_east'] ?? 0),
                                'plus_flag' => ($data['quantity_plus_flag_east'] ?? 'false') === 'true'
                            ],
                            'west' => [
                                'quantity' => (int) ($data['quantity_available_west'] ?? 0),
                                'plus_flag' => ($data['quantity_plus_flag_west'] ?? 'false') === 'true'
                            ]
                        ],
                        'source' => 'helmet_house'
                    ];
                }
            }

            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'helmet_house_sku' => null,
                'name' => null,
                'source' => 'helmet_house'
            ];

        } catch (Exception $e) {
            Log::warning('Helmet House API error', [
                'sku' => $sku,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'helmet_house_sku' => null,
                'name' => null,
                'error' => 'API temporarily unavailable',
                'source' => 'helmet_house'
            ];
        }
    }

    public function createOrder($cartItems, $shippingInfo, $dealerNumber = null)
    {
        if (!$this->apiToken) {
            throw new Exception('Helmet House API token not configured');
        }

        $dealerNumber = $dealerNumber ?? $this->dealerNumber;

        if (!$dealerNumber) {
            throw new Exception('Helmet House dealer number not configured');
        }

        $isHoldOrder = env('HELMET_HOUSE_TEST_MODE', false);
        $poNumber = $isHoldOrder
            ? 'H' . substr(time(), -6) . rand(100, 999)
            : 'M' . substr(time(), -6) . rand(100, 999);

        if ($isHoldOrder) {
            Log::channel('helmet_house_orders')->info('Helmet House TEST Order - Will be held in CRM', [
                'po_number' => $poNumber,
                'dealer_number' => $dealerNumber,
                'items_count' => count($cartItems),
                'note' => 'Test order will be held in Helmet House CRM and can be deleted'
            ]);
        }

        Log::channel('helmet_house_orders')->info('Helmet House Order Creation Started', [
            'po_number' => $poNumber,
            'dealer_number' => $dealerNumber,
            'items_count' => count($cartItems),
            'customer' => $shippingInfo['ship_name'] ?? 'Unknown',
            'email' => $shippingInfo['email'] ?? 'Unknown',
            'cart_items' => $cartItems
        ]);

        try {
            // Build line items with correct structure per API docs
            $lineItems = [];
            $lineNumber = 1;
            foreach ($cartItems as $item) {
                $lineItems[] = [
                    'line_number' => $lineNumber++,  // Sequential line number (REQUIRED)
                    'part_number' => $item['sku'],   // HH Part Number (REQUIRED)
                    'quantity' => (int) $item['quantity'], // Quantity ordered (REQUIRED)
                    'price' => (float) ($item['price'] ?? 0) // Unit price (optional)
                ];
            }

            // Build order payload with CORRECT field names per API documentation
            $orderPayload = [
                'dealer_number' => (string) $dealerNumber,                    // REQUIRED
                'order_type' => 'DS',                                         // REQUIRED: DS=Drop ship
                'purchase_order_number' => (string) $poNumber,                // REQUIRED (max 10 chars)
                'shipping_method' => 'Ground',                                // REQUIRED: Ground, 1 Day, 2 Day, or 3 Day
                'validate_price' => false,                                    // REQUIRED: false=Accept HH calculated price
                'ship_to_address' => [                                        // REQUIRED for Drop Ship
                    'name' => $shippingInfo['ship_name'] ?? '',              // REQUIRED
                    'address_line_1' => $shippingInfo['ship_address1'] ?? '', // REQUIRED
                    'address_line_2' => $shippingInfo['ship_address2'] ?? '', // Optional
                    'city' => $shippingInfo['ship_city'] ?? '',               // REQUIRED
                    'state' => $shippingInfo['ship_state'] ?? '',             // REQUIRED
                    'postal_code' => $shippingInfo['ship_zip'] ?? '',         // REQUIRED
                    'country' => 'US'                                         // Optional (defaults to US)
                ],
                'line_items' => $lineItems                                    // REQUIRED
            ];

            Log::channel('helmet_house_orders')->info('Helmet House Order Payload', [
                'po_number' => $poNumber,
                'payload' => $orderPayload,
                'url' => $this->baseUrl . '?req=order',
                'dealer_number' => $dealerNumber,
                'order_type' => 'DS',
                'payload_json' => json_encode($orderPayload)
            ]);

            // API endpoint as per documentation
            $url = $this->baseUrl . '?req=order';

            Log::channel('helmet_house_orders')->info('Helmet House Order Request Details', [
                'po_number' => $poNumber,
                'url' => $url,
                'method' => 'POST',
                'headers' => [
                    'api-key' => substr($this->apiToken, 0, 10) . '...',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'note' => 'Using corrected field names per API documentation'
            ]);

            $response = Http::withHeaders([
                'api-key' => $this->apiToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->timeout(30)
              ->post($url, $orderPayload);

            Log::channel('helmet_house_orders')->info('Helmet House Order Response', [
                'po_number' => $poNumber,
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                // Check if response is empty array - this means order inquiry, not creation
                if (empty($responseData) || $responseData === []) {
                    Log::channel('helmet_house_orders')->error('Helmet House Order Failed - Empty Response', [
                        'po_number' => $poNumber,
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'error' => 'API returned empty array - likely missing required fields'
                    ]);

                    return [
                        'success' => false,
                        'error' => 'API returned empty response. Check logs for details.'
                    ];
                }

                // API returns array of orders, get first element
                $orderData = is_array($responseData) && isset($responseData[0]) ? $responseData[0] : $responseData;

                // Check for API error response (per docs: status, status_reason fields indicate error)
                if (isset($orderData['status']) && $orderData['status'] !== '0') {
                    Log::channel('helmet_house_orders')->error('Helmet House Order API Error', [
                        'po_number' => $poNumber,
                        'status_code' => $orderData['status'],
                        'status_reason' => $orderData['status_reason'] ?? 'Unknown error',
                        'response' => $orderData
                    ]);

                    return [
                        'success' => false,
                        'error' => 'API Error ' . $orderData['status'] . ': ' . ($orderData['status_reason'] ?? 'Unknown error')
                    ];
                }

                // Success response per API docs
                Log::channel('helmet_house_orders')->info('Helmet House Order Created Successfully', [
                    'po_number' => $poNumber,
                    'reference_number' => $orderData['reference_number'] ?? $orderData['invoice_no'] ?? null,
                    'order_total' => $orderData['order_total'] ?? 0,
                    'date_ordered' => $orderData['date_ordered'] ?? null,
                    'shipping_method' => $orderData['shipping_method'] ?? 'Ground'
                ]);

                return [
                    'success' => true,
                    'po_number' => $poNumber,
                    'helmet_house_order_id' => $orderData['reference_number'] ?? $orderData['invoice_no'] ?? null,
                    'reference_number' => $orderData['reference_number'] ?? $orderData['invoice_no'] ?? null,
                    'status_code' => $response->status(),
                    'status_message' => 'Order submitted successfully',
                    'order_total' => $orderData['order_total'] ?? array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cartItems)),
                    'date_ordered' => $orderData['date_ordered'] ?? null,
                    'shipping_method' => $orderData['shipping_method'] ?? 'Ground',
                    'test_mode' => $isHoldOrder
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            Log::channel('helmet_house_orders')->error('Helmet House Order Creation Failed', [
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

    public function testConnection()
    {
        try {
            $testSku = '5642210800';
            
            $response = Http::withHeaders([
                'api-key' => $this->apiToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ])->timeout(10)->post($this->baseUrl . '?req=part', [
                'dealer_number' => $this->dealerNumber,
                'part_number' => $testSku
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'has_data' => $response->successful() && !empty($response->json()),
                'response_body' => $response->body()
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Alternative order creation method using form data instead of JSON
     * Some CGI scripts expect application/x-www-form-urlencoded data
     */
    public function createOrderFormEncoded($cartItems, $shippingInfo, $dealerNumber = null)
    {
        if (!$this->apiToken) {
            throw new Exception('Helmet House API token not configured');
        }

        $dealerNumber = $dealerNumber ?? $this->dealerNumber;

        if (!$dealerNumber) {
            throw new Exception('Helmet House dealer number not configured');
        }

        $isHoldOrder = env('HELMET_HOUSE_TEST_MODE', false);
        $poNumber = $isHoldOrder
            ? 'H' . substr(time(), -6) . rand(100, 999)
            : 'M' . substr(time(), -6) . rand(100, 999);

        Log::channel('helmet_house_orders')->info('Helmet House Order (Form-Encoded) - Starting', [
            'po_number' => $poNumber,
            'dealer_number' => $dealerNumber,
            'items_count' => count($cartItems)
        ]);

        try {
            // Build form data - flatten the nested structure
            $formData = [
                'dealer_number' => (string) $dealerNumber,
                'po_number' => (string) $poNumber,
                'order_type' => 'DS',
                'ship_name' => $shippingInfo['ship_name'] ?? '',
                'ship_address1' => $shippingInfo['ship_address1'] ?? '',
                'ship_address2' => $shippingInfo['ship_address2'] ?? '',
                'ship_city' => $shippingInfo['ship_city'] ?? '',
                'ship_state' => $shippingInfo['ship_state'] ?? '',
                'ship_zip' => $shippingInfo['ship_zip'] ?? '',
                'ship_country' => 'US',
                'ship_phone' => $shippingInfo['phone'] ?? $shippingInfo['ship_phone'] ?? '',
                'ship_email' => $shippingInfo['email'] ?? ''
            ];

            // Add line items as indexed fields
            foreach ($cartItems as $index => $item) {
                $formData["line_items[{$index}][part_number]"] = $item['sku'];
                $formData["line_items[{$index}][quantity]"] = $item['quantity'];
                $formData["line_items[{$index}][price]"] = $item['price'] ?? 0;
            }

            Log::channel('helmet_house_orders')->info('Helmet House Order (Form-Encoded) Payload', [
                'po_number' => $poNumber,
                'form_data' => $formData,
                'url' => $this->baseUrl . '?req=order&type=DS'
            ]);

            $response = Http::withHeaders([
                'api-key' => $this->apiToken,
                'Accept' => 'application/json'
            ])->timeout(30)
              ->asForm()  // Send as application/x-www-form-urlencoded
              ->post($this->baseUrl . '?req=order&type=DS', $formData);

            Log::channel('helmet_house_orders')->info('Helmet House Order (Form-Encoded) Response', [
                'po_number' => $poNumber,
                'status' => $response->status(),
                'body' => $response->body(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                if (empty($responseData) || $responseData === []) {
                    return [
                        'success' => false,
                        'error' => 'Form-encoded request also returned empty response'
                    ];
                }

                return [
                    'success' => true,
                    'po_number' => $poNumber,
                    'helmet_house_order_id' => $responseData['order_id'] ?? null,
                    'reference_number' => $responseData['reference_number'] ?? null,
                    'status_code' => $response->status(),
                    'status_message' => $responseData['message'] ?? 'Order submitted successfully (form-encoded)',
                    'order_total' => array_sum(array_map(fn($item) => $item['price'] * $item['quantity'], $cartItems))
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            Log::channel('helmet_house_orders')->error('Helmet House Order (Form-Encoded) Failed', [
                'po_number' => $poNumber,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    protected function getHelmetHouseSku($ourSku)
    {
        try {
            // First check if mapping table exists and has this SKU
            $mapping = DB::table('helmet_house_sku_mapping')
                ->where('our_sku', $ourSku)
                ->where('is_active', true)
                ->first();

            if ($mapping) {
                return $mapping->helmet_house_sku;
            }

        } catch (Exception $e) {
            // Mapping table doesn't exist yet, continue
        }

        // If no mapping found, try the SKU directly (for testing/setup)
        // This allows us to test Helmet House SKUs before creating mappings
        return $ourSku;
    }
}
