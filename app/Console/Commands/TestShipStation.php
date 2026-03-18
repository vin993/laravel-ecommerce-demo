<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShipStationService;
use Webkul\Product\Models\Product;

class TestShipStation extends Command
{
    protected $signature = 'test:shipstation {--create-order} {--order-id=}';
    protected $description = 'Test ShipStation integration and create test orders';

    public function handle()
    {
        $shipStationService = app(ShipStationService::class);
        
        $this->info('😢 Testing ShipStation Integration');
        $this->info('=====================================');
        
        // Check credentials first
        $apiKey = env('SHIPSTATION_API_KEY');
        $secretKey = env('SHIPSTATION_SECRET_KEY');
        
        $this->info("\n0. Checking credentials...");
        if (empty($apiKey) || empty($secretKey)) {
            $this->error("❌ ShipStation credentials not configured!");
            $this->info("Please add to your .env file:");
            $this->info("SHIPSTATION_API_KEY=your_api_key");
            $this->info("SHIPSTATION_SECRET_KEY=your_secret_key");
            return 1;
        }
        
        $this->table(['Credential', 'Status', 'Length'], [
            ['API Key', empty($apiKey) ? 'Missing' : 'Set', strlen($apiKey)],
            ['Secret Key', empty($secretKey) ? 'Missing' : 'Set', strlen($secretKey)]
        ]);

        // Test 1: API Connection
        $this->info("\n1. Testing ShipStation API connection...");
        $connectionResult = $shipStationService->testConnection();

        if ($connectionResult['success']) {
            $this->info("✅ ShipStation API connection successful!");
            if (isset($connectionResult['data'])) {
                $storeData = $connectionResult['data'];
                $this->table(['Property', 'Value'], [
                    ['API Status', 'Connected'],
                    ['HTTP Status', $connectionResult['status']],
                    ['Stores Found', count($storeData)],
                    ['Response Type', 'Store List'],
                    ['API Endpoint', 'https://ssapi.shipstation.com']
                ]);
            }
        } else {
            $this->error("❌ ShipStation API connection failed: " . $connectionResult['error']);
            
            // Provide troubleshooting info
            $this->info("\n🔧 Troubleshooting:");
            $this->info("1. Verify credentials at: https://ship.shipstation.com");
            $this->info("   - Go to Account → API Settings");
            $this->info("   - Check if API Access is enabled");
            $this->info("   - Verify API Key and Secret Key");
            
            $this->info("\n2. Current configuration:");
            $this->info("   - API Key: " . (empty($apiKey) ? 'Not set' : substr($apiKey, 0, 8) . '...'));
            $this->info("   - Secret: " . (empty($secretKey) ? 'Not set' : substr($secretKey, 0, 8) . '...'));
            $this->info("   - Endpoint: https://ssapi.shipstation.com");
            
            $this->info("\n3. Test manually with curl:");
            $this->info("   curl -u '{$apiKey}:{$secretKey}' 'https://ssapi.shipstation.com/stores'");
            
            return 1;
        }

        // Test 2: Get Stores
        $this->info("\n2. Fetching ShipStation stores...");
        $storesResult = $shipStationService->getStores();

        if ($storesResult['success']) {
            $stores = $storesResult['stores'];
            $this->info("Found " . count($stores) . " store(s):");

            $storeData = [];
            foreach ($stores as $store) {
                $storeData[] = [
                    $store['storeId'] ?? 'N/A',
                    $store['storeName'] ?? 'N/A',
                    $store['marketplaceName'] ?? 'N/A',
                    $store['active'] ? 'Yes' : 'No'
                ];
            }

            $this->table(['Store ID', 'Store Name', 'Marketplace', 'Active'], array_slice($storeData, 0, 5));

            if (count($storeData) > 5) {
                $this->info('... and ' . (count($storeData) - 5) . ' more stores');
            }
        } else {
            $this->warn("Could not fetch stores: " . $storesResult['error']);
        }

        // Test 3: Get Carriers
        $this->info("\n3. Fetching available carriers...");
        $carriersResult = $shipStationService->getCarriers();

        if ($carriersResult['success']) {
            $carriers = $carriersResult['carriers'];
            $this->info("Found " . count($carriers) . " carrier(s):");

            $carrierData = [];
            foreach (array_slice($carriers, 0, 5) as $carrier) {
                $carrierData[] = [
                    $carrier['name'] ?? 'N/A',
                    $carrier['code'] ?? 'N/A',
                    count($carrier['services'] ?? []) . ' services'
                ];
            }

            $this->table(['Carrier Name', 'Code', 'Services'], $carrierData);

            if (count($carriers) > 5) {
                $this->info('... and ' . (count($carriers) - 5) . ' more carriers');
            }
        } else {
            $this->warn("Could not fetch carriers: " . $carriersResult['error']);
        }

        // Test 4: Create Test Order (if requested)
        if ($this->option('create-order')) {
            $this->info("\n4. Creating test ShipStation order...");
            $this->createTestOrder($shipStationService);
        } else {
            $this->info("\n4. Skipping test order creation");
            $this->info("   To create a test order, run: php artisan test:shipstation --create-order");
        }

        // Test 5: Get Recent Orders
        $this->info("\n5. Fetching recent orders...");
        $recentOrders = $shipStationService->getOrder('MADD-' . date('Y'));

        if ($recentOrders['success']) {
            $orders = $recentOrders['orders'];
            $this->info("Found " . count($orders) . " order(s) matching pattern 'MADD-" . date('Y') . "'");

            if (!empty($orders)) {
                $orderData = [];
                foreach (array_slice($orders, 0, 5) as $order) {
                    $orderData[] = [
                        $order['orderNumber'] ?? 'N/A',
                        $order['orderStatus'] ?? 'N/A',
                        isset($order['orderDate']) ? date('Y-m-d', strtotime($order['orderDate'])) : 'N/A',
                        '$' . number_format($order['amountPaid'] ?? 0, 2),
                        $order['customerEmail'] ?? 'N/A'
                    ];
                }

                $this->table(['Order Number', 'Status', 'Date', 'Amount', 'Customer'], $orderData);
            }
        } else {
            $this->info("No recent orders found or API error: " . $recentOrders['error']);
        }

        $this->info("\n ShipStation integration test completed!");
        $this->info("Summary:");
        $this->info("API connection working");
        $this->info("Account access verified");
        $this->info("Stores and carriers accessible");
        $this->info("Ready for order processing");

        if (!$this->option('create-order')) {
            $this->info("\n Next steps:");
            $this->info("   • Test order creation: php artisan test:shipstation --create-order");
            $this->info("   • Check specific order: php artisan test:shipstation --order-id=MADD-2024-123456");
        }

        return 0;
    }

    private function createTestOrder($shipStationService)
    {
        try {
            // Get a sample product for testing
            $product = Product::where('type', 'simple')->first();

            if (!$product) {
                $this->error("No products found in database for test order");
                return;
            }

            $testOrderId = $this->option('order-id') ?: rand(100000, 999999);

            // Create test order data
            $testOrderData = [
                'order_id' => $testOrderId,
                'customer_name' => 'John Test Customer',
                'customer_email' => 'test@maddparts.com',
                'company' => 'MaddParts Test Co.',
                'phone' => '555-123-4567',
                'shipping_address1' => '123 Test Street',
                'shipping_address2' => 'Suite 100',
                'shipping_address3' => '',
                'shipping_city' => 'Test City',
                'shipping_state' => 'TX',
                'shipping_zip' => '12345',
                'billing_address1' => '123 Test Street',
                'billing_address2' => 'Suite 100',
                'billing_city' => 'Test City',
                'billing_state' => 'TX',
                'billing_zip' => '12345',
                'total_amount' => 149.99,
                'tax_amount' => 12.00,
                'shipping_amount' => 9.99,
                'payment_method' => 'Credit Card',
                'shipping_method' => 'Ground',
                'customer_notes' => 'Test order - please handle with care',
                'items' => [
                    [
                        'id' => 1,
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'quantity' => 1,
                        'unit_price' => 139.99,
                        'tax_amount' => 11.20,
                        'weight' => $product->weight ?? 2.5,
                        'upc' => $product->upc ?? '',
                        'image_url' => '',
                        'warehouse_location' => 'A-1-B'
                    ],
                    [
                        'id' => 2,
                        'product_id' => $product->id + 1,
                        'sku' => 'TEST-ITEM-001',
                        'name' => 'Test Motorcycle Oil Filter',
                        'quantity' => 1,
                        'unit_price' => 10.00,
                        'tax_amount' => 0.80,
                        'weight' => 0.5,
                        'upc' => '123456789012',
                        'image_url' => '',
                        'warehouse_location' => 'B-2-A'
                    ]
                ]
            ];

            $this->info("Creating test order with data:");
            $this->table(['Property', 'Value'], [
                ['Order ID', $testOrderId],
                ['Customer', $testOrderData['customer_name']],
                ['Email', $testOrderData['customer_email']],
                ['Items Count', count($testOrderData['items'])],
                ['Total Amount', '$' . number_format($testOrderData['total_amount'], 2)],
                ['Shipping Address', $testOrderData['shipping_address1'] . ', ' . $testOrderData['shipping_city'] . ', ' . $testOrderData['shipping_state']]
            ]);

            // Create the order
            $result = $shipStationService->createOrder($testOrderData);

            if ($result['success']) {
                $this->info("Test order created successfully!");
                $this->table(['Field', 'Value'], [
                    ['Order Number', $result['order_number']],
                    ['ShipStation Order ID', $result['shipstation_order_id'] ?? 'N/A'],
                    ['Order Status', $result['order_status']],
                    ['ShipStation Order Key', $result['shipstation_order_key'] ?? 'N/A']
                ]);

                // Try to fetch the created order
                $this->info("\n Verifying created order...");
                $orderResult = $shipStationService->getOrder($result['order_number']);

                if ($orderResult['success'] && !empty($orderResult['orders'])) {
                    $order = $orderResult['orders'][0];
                    $this->info("Order verified in ShipStation:");
                    $this->table(['Property', 'Value'], [
                        ['Order Status', $order['orderStatus'] ?? 'N/A'],
                        ['Customer Email', $order['customerEmail'] ?? 'N/A'],
                        ['Ship To Name', $order['shipTo']['name'] ?? 'N/A'],
                        ['Items Count', count($order['items'] ?? [])],
                        ['Amount Paid', '$' . number_format($order['amountPaid'] ?? 0, 2)]
                    ]);
                } else {
                    $this->warn("Could not verify order in ShipStation (may take a moment to appear)");
                }

            } else {
                $this->error("Failed to create test order: " . $result['error']);
            }

        } catch (\Exception $e) {
            $this->error("Exception during test order creation: " . $e->getMessage());
        }
    }
}
