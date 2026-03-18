<?php

namespace App\Services\Dropship;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Exception;

class Turn14DropshipService
{
    protected $apiUrl;
    protected $clientId;
    protected $clientSecret;
    protected $timeout;
    protected $environment;

    public function __construct()
    {
        $this->environment = config('turn14.environment', 'testing');

        $baseUrl = config('turn14.api_url');
        if ($this->environment === 'testing' && !str_contains($baseUrl, 'apitest')) {
            $baseUrl = str_replace('api.turn14.com', 'apitest.turn14.com', $baseUrl);
        }

        $this->apiUrl = rtrim($baseUrl, '/');
        $this->clientId = config('turn14.client_id');
        $this->clientSecret = config('turn14.client_secret');
        $this->timeout = config('turn14.timeout', 30);
    }

    public function getAccessToken()
    {
        $cacheKey = 'turn14_access_token_' . $this->environment;

        return Cache::remember($cacheKey, 3500, function () {
            try {
                Log::info('Turn14 Getting Access Token', [
                    'url' => $this->apiUrl . '/v1/token',
                    'environment' => $this->environment,
                    'client_id' => substr($this->clientId, 0, 10) . '...'
                ]);

                $response = Http::timeout($this->timeout)
                    ->post($this->apiUrl . '/v1/token', [
                        'grant_type' => 'client_credentials',
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                    ]);

                Log::info('Turn14 Token Response', [
                    'status' => $response->status(),
                    'successful' => $response->successful(),
                    'body' => $response->body()
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['access_token'];
                }

                Log::channel('turn14_orders')->error('Turn14 Token Error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'environment' => $this->environment,
                    'api_url' => $this->apiUrl
                ]);

                throw new \Exception('Failed to get Turn14 access token: HTTP ' . $response->status());
            } catch (\Exception $e) {
                Log::channel('turn14_orders')->error('Turn14 Token Exception', [
                    'message' => $e->getMessage(),
                    'environment' => $this->environment,
                    'api_url' => $this->apiUrl
                ]);
                throw $e;
            }
        });
    }

    public function checkInventory(string $itemId)
    {
        try {
            $token = $this->getAccessToken();

            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->get($this->apiUrl . '/v1/inventory/' . $itemId);

            if ($response->successful()) {
                $data = $response->json();

                if (!empty($data['data']) && isset($data['data'][0])) {
                    $item = $data['data'][0];
                    $inventory = $item['attributes']['inventory'] ?? [];

                    $totalStock = 0;
                    foreach ($inventory as $locationStock) {
                        $totalStock += (int) $locationStock;
                    }

                    return [
                        'available' => $totalStock > 0,
                        'quantity' => $totalStock,
                        'inventory' => $inventory,
                        'source' => 'turn14_api'
                    ];
                }

                return [
                    'available' => false,
                    'quantity' => 0,
                    'source' => 'turn14_api'
                ];
            }

            Log::channel('turn14_orders')->warning('Turn14 Inventory Check Failed', [
                'item_id' => $itemId,
                'status' => $response->status()
            ]);

            return [
                'available' => false,
                'quantity' => 0,
                'source' => 'turn14_api'
            ];
        } catch (\Exception $e) {
            Log::channel('turn14_orders')->error('Turn14 Inventory Exception', [
                'item_id' => $itemId,
                'message' => $e->getMessage()
            ]);

            return [
                'available' => false,
                'quantity' => 0,
                'source' => 'turn14_api'
            ];
        }
    }

    public function getShippingQuote($items, $shippingAddress)
    {
        try {
            $token = $this->getAccessToken();

            $quoteItems = [];
            foreach ($items as $item) {
                $turn14ItemId = $item['turn14_item_id'] ?? null;

                if (!$turn14ItemId && isset($item['sku'])) {
                    $turn14ItemId = $this->getTurn14ItemId($item['sku']);
                }

                if ($turn14ItemId) {
                    $quoteItems[] = [
                        'item_identifier' => $turn14ItemId,
                        'item_identifier_type' => 'item_id',
                        'quantity' => $item['quantity'] ?? 1
                    ];
                }
            }

            if (empty($quoteItems)) {
                return [
                    'success' => false,
                    'rate' => 0,
                    'error' => 'No valid Turn14 items found'
                ];
            }

            $payload = [
                'data' => [
                    'environment' => $this->environment,
                    'po_number' => 'QUOTE-' . time(),
                    'sales_source' => 2,
                    'locations' => [
                        [
                            'location' => 'default',
                            'combine_in_out_stock' => false,
                            'items' => $quoteItems
                        ]
                    ],
                    'recipient' => [
                        'company' => $shippingAddress['company'] ?? '',
                        'name' => $shippingAddress['ship_name'] ?? $shippingAddress['name'] ?? '',
                        'address' => $shippingAddress['ship_address1'] ?? $shippingAddress['address'] ?? '',
                        'address_2' => $shippingAddress['ship_address2'] ?? $shippingAddress['address_2'] ?? '',
                        'city' => $shippingAddress['ship_city'] ?? $shippingAddress['city'] ?? '',
                        'state' => $shippingAddress['ship_state'] ?? $shippingAddress['state'] ?? '',
                        'country' => $shippingAddress['country'] ?? 'US',
                        'zip' => $shippingAddress['ship_zip'] ?? $shippingAddress['zip'] ?? '',
                        'email_address' => $shippingAddress['email'] ?? 'noreply@example.com',
                        'phone_number' => $shippingAddress['ship_phone'] ?? $shippingAddress['phone'] ?? '000-000-0000',
                        'is_shop_address' => false
                    ]
                ]
            ];

            Log::info('Turn14 Shipping Quote Request', $payload);

            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->post($this->apiUrl . '/v1/quote', $payload);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Turn14 Shipping Quote Response', $data);

                $shippingOptions = [];
                $lowestCost = null;

                if (isset($data['data']['attributes']['shipment'])) {
                    foreach ($data['data']['attributes']['shipment'] as $shipment) {
                        if (isset($shipment['shipping']) && is_array($shipment['shipping'])) {
                            foreach ($shipment['shipping'] as $shippingOption) {
                                $cost = $shippingOption['cost'] ?? 0;

                                if ($lowestCost === null || $cost < $lowestCost) {
                                    $lowestCost = $cost;
                                }

                                $shippingOptions[] = [
                                    'code' => $shippingOption['shipping_code'] ?? null,
                                    'cost' => $cost,
                                    'days' => $shippingOption['days_in_transit'] ?? null,
                                    'eta' => $shippingOption['verbose_eta'] ?? null
                                ];
                            }
                        }
                    }
                }

                return [
                    'success' => true,
                    'rate' => $lowestCost ?? 0,
                    'quote_id' => $data['data']['id'] ?? null,
                    'shipping_options' => $shippingOptions,
                    'method' => 'Turn14 Ground Shipping'
                ];
            }

            Log::error('Turn14 Shipping Quote Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'rate' => 0,
                'error' => 'Failed to get shipping quote: ' . $response->body()
            ];

        } catch (\Exception $e) {
            Log::error('Turn14 Shipping Quote Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'rate' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    public function createOrder(array $orderData)
    {
        $testMode = config('turn14.test_mode', false);

        if ($testMode) {
            Log::channel('turn14_orders')->info('Turn14 TEST MODE - Order NOT submitted to API', [
                'test_mode' => true,
                'po_number' => $orderData['po_number'],
                'items_count' => count($orderData['items'] ?? []),
                'note' => 'This is a test order simulation - no real order placed'
            ]);

            return [
                'success' => true,
                'order_id' => 'TEST-' . rand(100000, 999999),
                'po_number' => $orderData['po_number'],
                'test_mode' => true,
                'message' => 'TEST ORDER - Not submitted to Turn14 API'
            ];
        }

        Log::channel('turn14_orders')->info('Turn14 Order Creation Started', $orderData);

        try {
            $token = $this->getAccessToken();

            $payload = $this->buildOrderPayload($orderData);

            Log::channel('turn14_orders')->info('Turn14 Order Payload', $payload);

            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->post($this->apiUrl . '/v1/order', $payload);

            if ($response->successful()) {
                $data = $response->json();

                Log::channel('turn14_orders')->info('Turn14 Order Submitted Successfully', [
                    'po_number' => $orderData['po_number'],
                    'turn14_order_id' => $data['data']['id'] ?? null,
                    'response' => $data
                ]);

                return [
                    'success' => true,
                    'order_id' => $data['data']['id'] ?? null,
                    'po_number' => $orderData['po_number'],
                    'response' => $data
                ];
            }

            Log::channel('turn14_orders')->error('Turn14 Order Failed', [
                'po_number' => $orderData['po_number'],
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status()
            ];
        } catch (\Exception $e) {
            Log::channel('turn14_orders')->error('Turn14 Order Exception', [
                'po_number' => $orderData['po_number'],
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    protected function buildOrderPayload(array $orderData)
    {
        $locations = [];

        foreach ($orderData['items'] as $item) {
            $location = $item['location'] ?? '01';

            if (!isset($locations[$location])) {
                $locations[$location] = [
                    'location' => $location,
                    'combine_in_out_stock' => false,
                    'items' => [],
                    'shipping' => [
                        'shipping_code' => $item['shipping_code'] ?? 3,
                        'saturday_delivery' => false,
                        'signature_required' => false
                    ]
                ];
            }

            $locations[$location]['items'][] = [
                'item_identifier' => $item['item_id'],
                'item_identifier_type' => 'item_id',
                'quantity' => $item['quantity']
            ];
        }

        $payload = [
            'data' => [
                'environment' => $this->environment,
                'po_number' => $orderData['po_number'],
                'locations' => array_values($locations),
                'acknowledge_prop_65' => true,
                'acknowledge_epa' => true,
                'acknowledge_carb' => true,
                'payment' => [
                    'payment_method' => config('turn14.payment_method', 'open_account')
                ],
                'recipient' => [
                    'company' => $orderData['shipping']['company'] ?? '',
                    'name' => $orderData['shipping']['name'],
                    'address' => $orderData['shipping']['address'],
                    'address_2' => $orderData['shipping']['address_2'] ?? '',
                    'city' => $orderData['shipping']['city'],
                    'state' => $orderData['shipping']['state'],
                    'country' => $orderData['shipping']['country'] ?? 'US',
                    'zip' => $orderData['shipping']['zip'],
                    'email_address' => $orderData['shipping']['email'] ?? '',
                    'phone_number' => $orderData['shipping']['phone'],
                    'is_shop_address' => false
                ]
            ]
        ];

        return $payload;
    }

    public function getOrderStatus(int $orderId)
    {
        try {
            $token = $this->getAccessToken();

            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->get($this->apiUrl . '/v1/orders/' . $orderId);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (\Exception $e) {
            Log::channel('turn14_orders')->error('Turn14 Get Order Status Exception', [
                'order_id' => $orderId,
                'message' => $e->getMessage()
            ]);

            return null;
        }
    }

    public function checkAvailability($sku)
    {
        if (!$this->clientId || !$this->clientSecret) {
            return null;
        }

        $turn14ItemId = $this->getTurn14ItemId($sku);
        if (!$turn14ItemId) {
            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'turn14_item_id' => null,
                'source' => 'turn14'
            ];
        }

        try {
            Log::info('Turn14 API Request', [
                'url' => "{$this->apiUrl}/v1/inventory/{$turn14ItemId}",
                'our_sku' => $sku,
                'turn14_item_id' => $turn14ItemId,
                'has_credentials' => !empty($this->clientId) && !empty($this->clientSecret)
            ]);

            $token = $this->getAccessToken();

            $response = Http::timeout($this->timeout)
                ->withToken($token)
                ->retry(2, 1000)
                ->get("{$this->apiUrl}/v1/inventory/{$turn14ItemId}");

            Log::info('Turn14 API Response', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body' => $response->body()
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (!empty($data['data']) && isset($data['data'][0])) {
                    $item = $data['data'][0];
                    $inventory = $item['attributes']['inventory'] ?? [];

                    $totalStock = 0;
                    foreach ($inventory as $locationStock) {
                        $totalStock += (int) $locationStock;
                    }

                    $price = 0;
                    try {
                        $priceResponse = Http::timeout($this->timeout)
                            ->withToken($token)
                            ->get("{$this->apiUrl}/v1/pricing/{$turn14ItemId}");

                        if ($priceResponse->successful()) {
                            $priceData = $priceResponse->json();
                            $attributes = $priceData['data']['attributes'] ?? [];

                            $purchaseCost = $attributes['purchase_cost'] ?? 0;
                            $pricelists = $attributes['pricelists'] ?? [];

                            Log::info('Turn14 Price Data', [
                                'turn14_item_id' => $turn14ItemId,
                                'our_sku' => $sku,
                                'purchase_cost' => $purchaseCost,
                                'has_map' => $attributes['has_map'] ?? false,
                                'pricelists' => $pricelists,
                            ]);

                            $price = $this->extractSellingPrice($pricelists, $purchaseCost);

                            Log::info('Turn14 Final Price Selected', [
                                'turn14_item_id' => $turn14ItemId,
                                'our_sku' => $sku,
                                'final_price' => $price
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Turn14 pricing fetch failed', ['item_id' => $turn14ItemId, 'error' => $e->getMessage()]);
                    }

                    return [
                        'available' => $totalStock > 0,
                        'price' => $price,
                        'inventory' => $totalStock,
                        'turn14_item_id' => $item['id'] ?? $turn14ItemId,
                        'locations' => $inventory,
                        'source' => 'turn14_api'
                    ];
                }
            }

            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'turn14_item_id' => null,
                'source' => 'turn14_api'
            ];

        } catch (Exception $e) {
            Log::warning('Turn14 API error', [
                'our_sku' => $sku,
                'turn14_item_id' => $turn14ItemId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'available' => false,
                'price' => 0,
                'inventory' => 0,
                'turn14_item_id' => null,
                'error' => 'API temporarily unavailable'
            ];
        }
    }

    public function testConnection()
    {
        try {
            Log::info('Turn14 Testing Connection', [
                'api_url' => $this->apiUrl,
                'environment' => $this->environment
            ]);

            $token = $this->getAccessToken();

            $response = Http::timeout(10)
                ->withToken($token)
                ->get("{$this->apiUrl}/v1/brands");

            Log::info('Turn14 Test Connection Response', [
                'status' => $response->status(),
                'body' => $response->body(),
                'headers' => $response->headers()
            ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'has_data' => $response->successful() && !empty($response->json()),
                'token_valid' => !empty($token),
                'environment' => $this->environment,
                'api_url' => $this->apiUrl,
                'response_body' => $response->body()
            ];

        } catch (Exception $e) {
            Log::error('Turn14 Test Connection Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'environment' => $this->environment,
                'api_url' => $this->apiUrl
            ];
        }
    }

    protected function getTurn14ItemId($ourSku)
    {
        try {
            $mapping = DB::table('turn14_sku_mapping')
                ->where('our_sku', $ourSku)
                ->where('is_active', true)
                ->first();

            if ($mapping) {
                return $mapping->turn14_item_id;
            }

        } catch (Exception $e) {
            Log::warning('Turn14 SKU mapping check failed', [
                'our_sku' => $ourSku,
                'error' => $e->getMessage()
            ]);
        }

        $itemId = $this->discoverTurn14Item($ourSku);
        if ($itemId) {
            return $itemId;
        }

        // For variants, try to find mapping using base SKU
        $itemId = $this->findMappingForVariant($ourSku);
        if ($itemId) {
            return $itemId;
        }

        // For configurable products with -PARENT suffix, try base SKU
        if (str_ends_with($ourSku, '-PARENT')) {
            $baseSku = str_replace('-PARENT', '', $ourSku);
            $mapping = DB::table('turn14_sku_mapping')
                ->where('our_sku', $baseSku)
                ->where('is_active', true)
                ->first();

            if ($mapping) {
                Log::info('Turn14 found mapping for configurable product using base SKU', [
                    'configurable_sku' => $ourSku,
                    'base_sku' => $baseSku,
                    'turn14_item_id' => $mapping->turn14_item_id
                ]);
                
                return $mapping->turn14_item_id;
            }
        }

        return null;
    }

    protected function discoverTurn14Item($ourSku)
    {
        try {
            $catalogItem = DB::table('turn14_catalog')
                ->where('mfr_part_number', $ourSku)
                ->orWhere('part_number', $ourSku)
                ->first();

            if ($catalogItem) {
                $this->ensureMappingTableExists();

                DB::table('turn14_sku_mapping')->insert([
                    'our_sku' => $ourSku,
                    'turn14_item_id' => $catalogItem->item_id,
                    'turn14_part_number' => $catalogItem->part_number,
                    'mfr_part_number' => $catalogItem->mfr_part_number,
                    'product_name' => $catalogItem->product_name,
                    'brand' => $catalogItem->brand,
                    'is_active' => true,
                    'notes' => 'Auto-discovered on page visit',
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                Log::info('Turn14 auto-discovered and mapped', [
                    'our_sku' => $ourSku,
                    'turn14_item_id' => $catalogItem->item_id
                ]);

                return $catalogItem->item_id;
            }

        } catch (Exception $e) {
            Log::warning('Turn14 auto-discovery failed', [
                'our_sku' => $ourSku,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    protected function findMappingForVariant($variantSku)
    {
        try {
            // Check if this is a variant product
            $product = DB::table('products')->where('sku', $variantSku)->first();
            if (!$product || !$product->parent_id) {
                return null;
            }

            // Get parent product
            $parent = DB::table('products')->where('id', $product->parent_id)->first();
            if (!$parent) {
                return null;
            }

            // Try to extract base SKU from parent SKU (remove -PARENT suffix)
            $baseSku = str_replace('-PARENT', '', $parent->sku);
            
            // Check if base SKU has Turn14 mapping
            $mapping = DB::table('turn14_sku_mapping')
                ->where('our_sku', $baseSku)
                ->where('is_active', true)
                ->first();

            if ($mapping) {
                Log::info('Turn14 found mapping for variant using base SKU', [
                    'variant_sku' => $variantSku,
                    'parent_sku' => $parent->sku,
                    'base_sku' => $baseSku,
                    'turn14_item_id' => $mapping->turn14_item_id
                ]);
                
                return $mapping->turn14_item_id;
            }

        } catch (Exception $e) {
            Log::warning('Turn14 variant mapping check failed', [
                'variant_sku' => $variantSku,
                'error' => $e->getMessage()
            ]);
        }

        return null;
    }

    public function ensureMappingTableExists()
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable('turn14_sku_mapping')) {
                DB::statement("
                    CREATE TABLE turn14_sku_mapping (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        our_sku VARCHAR(100) NOT NULL,
                        turn14_item_id VARCHAR(50) NOT NULL,
                        turn14_part_number VARCHAR(100) NULL,
                        mfr_part_number VARCHAR(100) NULL,
                        product_name VARCHAR(255) NULL,
                        brand VARCHAR(100) NULL,
                        is_active BOOLEAN DEFAULT 1,
                        notes TEXT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_our_sku (our_sku),
                        KEY idx_turn14_item_id (turn14_item_id),
                        KEY idx_active (is_active)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");

                Log::info('Turn14 SKU mapping table created successfully');
                return true;
            }

            return true;

        } catch (Exception $e) {
            Log::error('Failed to create Turn14 SKU mapping table', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function extractSellingPrice(array $pricelists, float $purchaseCost): float
    {
        if (empty($pricelists)) {
            Log::warning('Turn14 No Pricelists Available', [
                'purchase_cost' => $purchaseCost,
                'message' => 'Cannot determine selling price - no pricelists provided by API'
            ]);
            return 0;
        }

        $priceMap = [];
        foreach ($pricelists as $priceItem) {
            $name = strtolower($priceItem['name'] ?? '');
            $price = (float) ($priceItem['price'] ?? 0);
            if ($price > 0) {
                $priceMap[$name] = $price;
            }
        }

        $priorityOrder = ['retail', 'msrp', 'map', 'jobber'];

        foreach ($priorityOrder as $priceType) {
            if (isset($priceMap[$priceType])) {
                Log::info('Turn14 Price Found in Pricelists', [
                    'price_type' => $priceType,
                    'price' => $priceMap[$priceType]
                ]);
                return $priceMap[$priceType];
            }
        }

        if (!empty($priceMap)) {
            $firstPrice = reset($priceMap);
            Log::info('Turn14 Using First Available Price', [
                'price_type' => key($priceMap),
                'price' => $firstPrice
            ]);
            return $firstPrice;
        }

        Log::warning('Turn14 No Valid Prices in Pricelists', [
            'pricelists' => $pricelists,
            'message' => 'Pricelists array exists but contains no valid prices'
        ]);

        return 0;
    }
}
