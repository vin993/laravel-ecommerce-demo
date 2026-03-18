<?php

namespace App\Services\Dropship;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Exception;

class WpsDropshipService
{
    protected $baseUrl;
    protected $token;

    public function __construct()
    {
        $this->baseUrl = rtrim(env('WPS_API_BASE_URL', 'https://api.wps-inc.com'), '/');
        $this->token = env('WPS_API_TOKEN');
    }

    public function checkAvailability($sku)
    {
        if (!$this->token) {
            return null;
        }

        // Check cache first (5 minute TTL)
        $cacheKey = "wps_availability_{$sku}";

        return Cache::remember($cacheKey, 300, function() use ($sku) {
            try {
                // Debug: Log the request details
                Log::info('WPS API Request', [
                    'url' => "{$this->baseUrl}/items",
                    'sku' => $sku,
                    'has_token' => !empty($this->token),
                    'token_length' => strlen($this->token ?? ''),
                    'params' => ['filter[sku]' => $sku]
                ]);

                // First, search for the item by SKU using the items endpoint
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                ])->timeout(5)
                  ->retry(1, 500)
                  ->get("{$this->baseUrl}/items", [
                    'filter[sku]' => $sku
                ]);
            
            // Debug: Log the response
            Log::info('WPS API Response', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->body(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $items = $responseData['data'] ?? [];
                
                // Validate API response structure
                if (!is_array($items)) {
                    Log::warning('WPS API returned invalid data structure', [
                        'sku' => $sku,
                        'response' => $responseData
                    ]);
                    return $this->getEmptyAvailabilityResponse('Invalid API response');
                }
                
                if (!empty($items)) {
                    $item = $items[0]; // Get first matching item

                    Log::info('WPS Price Fields Available', [
                        'sku' => $sku,
                        'all_fields' => array_keys($item),
                        'standard_dealer_price' => $item['standard_dealer_price'] ?? 'not set',
                        'list_price' => $item['list_price'] ?? 'not set',
                        'msrp' => $item['msrp'] ?? 'not set',
                        'retail_price' => $item['retail_price'] ?? 'not set',
                    ]);

                    // Validate item structure
                    if (!isset($item['id']) || !isset($item['name'])) {
                        Log::warning('WPS API returned incomplete item data', [
                            'sku' => $sku,
                            'item' => $item
                        ]);
                        return $this->getEmptyAvailabilityResponse('Incomplete product data');
                    }

                    // Now get inventory for this item
                    $inventoryResponse = $this->getInventory($item['id']);

                    $inventory = 0;
                    $available = false;

                    if ($inventoryResponse && isset($inventoryResponse['quantity'])) {
                        $inventory = $inventoryResponse['quantity'];
                        $available = $inventory > 0;
                    } else {
                        // If inventory call fails, use product status
                        $status = $item['status'] ?? '';
                        $available = in_array($status, ['STK', 'STOCK']);
                        $inventory = $available ? 1 : 0; // Assume 1 if in stock
                    }

                    $price = $item['list_price'] ?? $item['msrp'] ?? $item['standard_dealer_price'] ?? 0;

                    return [
                        'available' => $available,
                        'price' => $price,
                        'inventory' => $inventory,
                        'wps_item_id' => $item['id'] ?? null,
                        'name' => $item['name'] ?? null,
                        'list_price' => $item['list_price'] ?? 0,
                        'drop_ship_eligible' => $item['drop_ship_eligible'] ?? false
                    ];
                }
            }
            
            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'wps_item_id' => null,
                'name' => null
            ];

            } catch (Exception $e) {
                Log::warning('WPS API error', [
                    'sku' => $sku,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                // Return a structured response even on error for better UX
                return [
                    'available' => false,
                    'price' => 0,
                    'inventory' => 0,
                    'wps_item_id' => null,
                    'name' => null,
                    'error' => 'API temporarily unavailable'
                ];
            }
        });
    }
    
    protected function getInventory($itemId)
    {
        // Cache inventory for 5 minutes
        $cacheKey = "wps_inventory_{$itemId}";

        return Cache::remember($cacheKey, 300, function() use ($itemId) {
            try {
                // Try filter approach first
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                ])->timeout(5)->get("{$this->baseUrl}/inventory", [
                    'filter[item_id]' => $itemId
                ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $inventoryData = $responseData['data'] ?? [];
                
                if (!empty($inventoryData)) {
                    $totalQuantity = 0;
                    
                    foreach ($inventoryData as $inventoryRecord) {
                        // WPS inventory structure has individual warehouse quantities
                        if (isset($inventoryRecord['total'])) {
                            // Use the total field if available
                            $totalQuantity += $inventoryRecord['total'];
                        } else {
                            // Sum individual warehouse quantities
                            $warehouses = [
                                'ca_warehouse', 'ga_warehouse', 'id_warehouse', 
                                'in_warehouse', 'pa_warehouse', 'pa2_warehouse', 'tx_warehouse'
                            ];
                            
                            foreach ($warehouses as $warehouse) {
                                $totalQuantity += $inventoryRecord[$warehouse] ?? 0;
                            }
                        }
                    }
                    
                    return ['quantity' => $totalQuantity];
                }
            } else {
                // Try direct entity endpoint as fallback
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Accept' => 'application/json',
                ])->timeout(10)->get("{$this->baseUrl}/inventory/{$itemId}");
                
                if ($response->successful()) {
                    $responseData = $response->json();
                    $inventoryData = $responseData['data'] ?? [];
                    
                    if (!empty($inventoryData)) {
                        // Handle single inventory record
                        if (isset($inventoryData['quantity'])) {
                            return ['quantity' => $inventoryData['quantity']];
                        }
                        // Handle multiple inventory records
                        elseif (is_array($inventoryData)) {
                            $totalQuantity = 0;
                            foreach ($inventoryData as $warehouse) {
                                $totalQuantity += $warehouse['quantity'] ?? 0;
                            }
                            return ['quantity' => $totalQuantity];
                        }
                    }
                }
            }
            } catch (Exception $e) {
                Log::warning('WPS Inventory API error', [
                    'item_id' => $itemId,
                    'error' => $e->getMessage()
                ]);
            }

            return null;
        });
    }
    
    public function getAvailableWarehouses($identifier)
    {
        try {
            // If identifier looks like an item_id (numeric), use it directly
            // Otherwise, assume it's a SKU and use sku filter
            $filterParam = is_numeric($identifier) ? 
                ['filter[item_id]' => $identifier] :
                ['filter[sku]' => $identifier];
            
            Log::info('WPS Warehouse Lookup', [
                'identifier' => $identifier,
                'filter' => $filterParam
            ]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->timeout(10)->get("{$this->baseUrl}/inventory", $filterParam);

            if ($response->successful()) {
                $responseData = $response->json();
                $inventoryData = $responseData['data'] ?? [];
                
                if (!empty($inventoryData)) {
                    $inventory = $inventoryData[0];
                    $warehouses = [];
                    
                    // Check each warehouse for inventory
                    $warehouseMap = [
                        'ca_warehouse' => ['code' => 'CA', 'name' => 'Fresno, CA'],
                        'ga_warehouse' => ['code' => 'GA', 'name' => 'Midway, GA'], 
                        'id_warehouse' => ['code' => 'ID', 'name' => 'Boise, ID'],
                        'in_warehouse' => ['code' => 'IN', 'name' => 'Ashley, IN'],
                        'pa_warehouse' => ['code' => 'PA', 'name' => 'Elizabethtown, PA'],
                        'pa2_warehouse' => ['code' => 'PA2', 'name' => 'Elizabethtown, PA (2)'],
                        'tx_warehouse' => ['code' => 'TX', 'name' => 'Midlothian, TX']
                    ];
                    
                    foreach ($warehouseMap as $field => $info) {
                        $qty = $inventory[$field] ?? 0;
                        if ($qty > 0) {
                            $warehouses[] = [
                                'code' => $info['code'],
                                'name' => $info['name'],
                                'quantity' => $qty
                            ];
                        }
                    }
                    
                    return $warehouses;
                }
            }
        } catch (Exception $e) {
            Log::warning('WPS Get Warehouses Error', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        // Fallback to default warehouses if API fails
        return [
            ['code' => 'ID', 'name' => 'Boise, ID', 'quantity' => 1],
            ['code' => 'TX', 'name' => 'Midlothian, TX', 'quantity' => 1]
        ];
    }
    
    private function getEmptyAvailabilityResponse($error = null)
    {
        return [
            'available' => false,
            'price' => 0,
            'inventory' => 0,
            'wps_item_id' => null,
            'name' => null,
            'error' => $error
        ];
    }

    public function createOrder($cartItems, $shippingInfo, $warehouseSelections = [])
    {
        if (!$this->token) {
            throw new Exception('WPS API token not configured');
        }

        $poNumber = 'MADD-' . time() . '-' . rand(1000, 9999);
        
        // Determine the primary warehouse (most common selection or default)
        $defaultWarehouse = $this->determinePrimaryWarehouse($warehouseSelections);
        
        // Log order start
        Log::channel('wps_orders')->info('WPS Order Creation Started', [
            'po_number' => $poNumber,
            'items_count' => count($cartItems),
            'customer' => $shippingInfo['ship_name'] ?? 'Unknown',
            'email' => $shippingInfo['email'] ?? 'Unknown',
            'default_warehouse' => $defaultWarehouse,
            'warehouse_selections' => $warehouseSelections,
            'cart_items' => $cartItems
        ]);

        try {
            // Step 1: Create Cart with the primary warehouse
            $shippingInfoWithWarehouse = array_merge($shippingInfo, [
                'default_warehouse' => $defaultWarehouse
            ]);
            
            Log::channel('wps_orders')->info('Creating WPS Cart', [
                'po_number' => $poNumber,
                'warehouse' => $defaultWarehouse
            ]);
            $cartResponse = $this->createWpsCart($poNumber, $shippingInfoWithWarehouse);
            
            if (!$cartResponse['success']) {
                Log::channel('wps_orders')->error('WPS Cart Creation Failed', [
                    'po_number' => $poNumber,
                    'error' => $cartResponse['error']
                ]);
                throw new Exception('Failed to create WPS cart: ' . $cartResponse['error']);
            }
            
            Log::channel('wps_orders')->info('WPS Cart Created Successfully', [
                'po_number' => $poNumber,
                'cart_response' => $cartResponse['data']
            ]);

            // Step 2: Add Items with warehouse preferences
            $itemResults = [];
            foreach ($cartItems as $index => $item) {
                $itemWarehouse = $warehouseSelections[$index] ?? $defaultWarehouse;
                
                Log::channel('wps_orders')->info('Adding Item to WPS Cart', [
                    'po_number' => $poNumber,
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'preferred_warehouse' => $itemWarehouse
                ]);
                
                $itemResult = $this->addItemToCart($poNumber, $item, $itemWarehouse);
                $itemResults[] = [
                    'sku' => $item['sku'],
                    'warehouse' => $itemWarehouse,
                    'success' => $itemResult
                ];
            }
            
            Log::channel('wps_orders')->info('All Items Added to WPS Cart', [
                'po_number' => $poNumber,
                'item_results' => $itemResults
            ]);

            // Step 3: Submit Order
            Log::channel('wps_orders')->info('Submitting WPS Order', ['po_number' => $poNumber]);
            $orderResponse = $this->submitOrder($poNumber);
            
            if (!$orderResponse['success']) {
                Log::channel('wps_orders')->error('WPS Order Submission Failed', [
                    'po_number' => $poNumber,
                    'error' => $orderResponse['error']
                ]);
                throw new Exception('Failed to submit WPS order: ' . $orderResponse['error']);
            }
            
            Log::channel('wps_orders')->info('WPS Order Submitted Successfully', [
                'po_number' => $poNumber,
                'wps_order_number' => $orderResponse['order_number'],
                'order_response' => $orderResponse
            ]);

            return [
                'success' => true,
                'po_number' => $poNumber,
                'order_number' => $orderResponse['order_number']
            ];

        } catch (Exception $e) {
            Log::channel('wps_orders')->error('WPS Order Creation Failed', [
                'po_number' => $poNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'cart_items' => $cartItems,
                'shipping_info' => $shippingInfo,
                'warehouse_selections' => $warehouseSelections
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function determinePrimaryWarehouse($warehouseSelections)
    {
        if (empty($warehouseSelections)) {
            return 'ID'; // Default warehouse
        }
        
        // Find the most common warehouse selection
        $warehouseCounts = array_count_values($warehouseSelections);
        arsort($warehouseCounts);
        
        return array_key_first($warehouseCounts) ?: 'ID';
    }

    protected function createWpsCart($poNumber, $shippingInfo)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/carts", [
                'po_number' => $poNumber,
                'ship_name' => $shippingInfo['ship_name'] ?? '',
                'ship_address1' => $shippingInfo['ship_address1'] ?? '',
                'ship_address2' => $shippingInfo['ship_address2'] ?? '',
                'ship_address3' => $shippingInfo['ship_address3'] ?? '',
                'ship_city' => $shippingInfo['ship_city'] ?? '',
                'ship_state' => $shippingInfo['ship_state'] ?? '',
                'ship_zip' => $shippingInfo['ship_zip'] ?? '',
                'ship_phone' => $shippingInfo['ship_phone'] ?? '',
                'email' => $shippingInfo['email'] ?? '',
                'default_warehouse' => $shippingInfo['default_warehouse'] ?? 'ID',
                'ship_via' => $shippingInfo['ship_via'] ?? 'BEST',
                'pay_type' => 'OO',
                'hold_order' => config('wps.hold_order', false),
                'allow_backorder' => true,
                'multiple_warehouse' => true,
                'comment1' => 'Madd Parts Dropship Order',
                'comment2' => 'Auto-generated via API'
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::channel('wps_orders')->info('WPS Create Cart API Response', [
                    'po_number' => $poNumber,
                    'status' => $response->status(),
                    'response' => $responseData
                ]);
                
                return ['success' => true, 'data' => $responseData];
            }
            
            Log::channel('wps_orders')->error('WPS Create Cart API Failed', [
                'po_number' => $poNumber,
                'status' => $response->status(),
                'response_body' => $response->body()
            ]);

            return [
                'success' => false, 
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function addItemToCart($poNumber, $item, $warehouse = null)
    {
        try {
            $requestData = [
                'item_sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'note' => 'Madd Parts Order'
            ];
            
            // Add warehouse if specified
            if ($warehouse) {
                $requestData['warehouse'] = $warehouse;
            }
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/carts/{$poNumber}/items", $requestData);
            
            $responseData = $response->json();
            
            Log::channel('wps_orders')->info('WPS Add Item API Response', [
                'po_number' => $poNumber,
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'warehouse' => $warehouse,
                'status' => $response->status(),
                'response' => $responseData
            ]);
            
            return $response->successful();
            
        } catch (Exception $e) {
            Log::error('WPS Add Item Error', [
                'po_number' => $poNumber,
                'sku' => $item['sku'],
                'warehouse' => $warehouse,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function submitOrder($poNumber)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->post("{$this->baseUrl}/orders", [
                'po_number' => $poNumber
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                
                Log::channel('wps_orders')->info('WPS Submit Order API Response', [
                    'po_number' => $poNumber,
                    'status' => $response->status(),
                    'response' => $responseData
                ]);
                
                return [
                    'success' => true,
                    'order_number' => $responseData['order_number'] ?? null,
                    'full_response' => $responseData
                ];
            }
            
            Log::channel('wps_orders')->error('WPS Submit Order API Failed', [
                'po_number' => $poNumber,
                'status' => $response->status(),
                'response_body' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getOrderStatus($poNumber)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}/orders/{$poNumber}");

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
}