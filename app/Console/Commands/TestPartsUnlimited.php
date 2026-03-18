<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\PartsUnlimitedDropshipService;
use Webkul\Product\Models\Product;

class TestPartsUnlimited extends Command
{
    protected $signature = 'test:parts-unlimited {--sku=} {--test-order} {--live-order}';
    protected $description = 'Test Parts Unlimited API integration';

    public function handle()
    {
        $partsUnlimitedService = app(PartsUnlimitedDropshipService::class);
        
        // Test connection first
        $this->info('Testing Parts Unlimited API connection...');
        $connectionTest = $partsUnlimitedService->testConnection();
        
        if (!$connectionTest['success']) {
            $this->error('❌ Parts Unlimited API connection failed');
            if (isset($connectionTest['error'])) {
                $this->error('Error: ' . $connectionTest['error']);
            }
            return 1;
        }
        
        $this->info('✅ Parts Unlimited API connection successful');
        
        $testSku = $this->option('sku');
        
        if ($testSku) {
            $this->testSingleSku($partsUnlimitedService, $testSku);
        } else {
            $this->findPartsUnlimitedProducts($partsUnlimitedService);
        }
        
        if (($this->option('test-order') || $this->option('live-order')) && $testSku) {
            $isLiveOrder = $this->option('live-order');
            $this->testOrderCreation($partsUnlimitedService, $testSku, $isLiveOrder);
        }
        
        return 0;
    }
    
    private function testSingleSku($partsUnlimitedService, $testSku)
    {
        $this->info("Testing Parts Unlimited availability for SKU: {$testSku}");
        
        $availability = $partsUnlimitedService->checkAvailability($testSku);
        
        if ($availability) {
            $this->info('Parts Unlimited Response:');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', $availability['available'] ? 'Yes' : 'No'],
                    ['Price', '$' . number_format($availability['price'] ?? 0, 2)],
                    ['Inventory', $availability['inventory'] ?? 0],
                    ['Parts Unlimited SKU', $availability['parts_unlimited_sku'] ?? 'N/A'],
                    ['Name', $availability['name'] ?? 'N/A'],
                    ['Source', $availability['source'] ?? 'N/A']
                ]
            );
            
            if (isset($availability['error'])) {
                $this->warn('Note: ' . $availability['error']);
            }
        } else {
            $this->warn('No Parts Unlimited availability data found for this SKU');
        }
    }
    
    private function findPartsUnlimitedProducts($partsUnlimitedService)
    {
        $this->info('Searching for products available in both your database and Parts Unlimited...');
        
        // Get simple products
        $products = Product::where('type', 'simple')
            ->where('sku', 'not like', '%-parent')
            ->limit(20)
            ->get();
            
        if ($products->isEmpty()) {
            $this->error('No simple products found in database');
            return;
        }
        
        $this->info("Testing {$products->count()} products for Parts Unlimited availability...");
        
        $foundProducts = [];
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();
        
        $testedSkus = [];
        
        foreach ($products as $product) {
            $testedSkus[] = $product->sku;
            $availability = $partsUnlimitedService->checkAvailability($product->sku);
            
            if ($availability && $availability['available']) {
                $foundProducts[] = [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'pu_price' => $availability['price'] ?? 0,
                    'pu_inventory' => $availability['inventory'] ?? 0,
                    'pu_sku' => $availability['parts_unlimited_sku'] ?? 'N/A'
                ];
            }
            
            $bar->advance();
            usleep(200000); // Delay to avoid overwhelming API
        }
        
        $bar->finish();
        $this->line('');
        
        if (empty($foundProducts)) {
            $this->warn('No products found that are available in both your database and Parts Unlimited');
            
            $this->info('');
            $this->info('Sample SKUs tested:');
            foreach (array_slice($testedSkus, 0, 5) as $sku) {
                $this->line("  - {$sku}");
            }
            
            $this->info('');
            $this->info('Testing common motorcycle part SKU patterns...');
            $commonSkus = [
                'b10es',        // NGK spark plug
                'b11es',        // NGK spark plug
                'dpr8ea-9',     // NGK spark plug
                'cr8e',         // NGK spark plug
                'k&n-kn-204',   // K&N air filter
                'ebc-fa131',    // EBC brake pads
                'bt-303-16',    // Battery
                'purolator-l10241', // Oil filter
            ];
            
            foreach ($commonSkus as $testSku) {
                $this->info("Testing common SKU: {$testSku}");
                $result = $partsUnlimitedService->checkAvailability($testSku);
                if ($result && isset($result['available']) && $result['available']) {
                    $this->info("  → Found available product! SKU: {$testSku}");
                    $this->info("      Price: $" . number_format($result['price'] ?? 0, 2));
                    $this->info("      Inventory: " . ($result['inventory'] ?? 0));
                    $this->info("      You can test with: php artisan test:parts-unlimited --sku={$testSku}");
                    break;
                } elseif ($result !== null) {
                    $this->line("  → API responded but product not available");
                } else {
                    $this->line("  → No API response");
                }
                usleep(300000); // Longer delay for testing
            }
            
        } else {
            $this->info("Found " . count($foundProducts) . " products available in both systems:");
            
            $tableData = array_map(function($product) {
                return [
                    $product['sku'],
                    substr($product['name'], 0, 30) . (strlen($product['name']) > 30 ? '...' : ''),
                    '$' . number_format($product['pu_price'], 2),
                    $product['pu_inventory'],
                    $product['pu_sku']
                ];
            }, array_slice($foundProducts, 0, 10));
            
            $this->table(
                ['SKU', 'Product Name', 'PU Price', 'PU Inventory', 'PU SKU'],
                $tableData
            );
            
            if (count($foundProducts) > 10) {
                $this->info('... and ' . (count($foundProducts) - 10) . ' more products');
            }
            
            // Suggest testing with one of these SKUs
            $firstMatch = $foundProducts[0];
            $this->info("");
            $this->info("You can test with a specific SKU using:");
            $this->info("php artisan test:parts-unlimited --sku={$firstMatch['sku']}");
            $this->info("Or test order creation with:");
            $this->info("php artisan test:parts-unlimited --sku={$firstMatch['sku']} --test-order");
        }
    }
    
    private function testOrderCreation($partsUnlimitedService, $testSku, $isLiveOrder = false)
    {
        if ($isLiveOrder) {
            $this->error("\n🚨 LIVE ORDER MODE - This will create a REAL order!");
            $this->warn("⚠️  Real product will be ordered and shipped");
            $this->warn("⚠️  Real money transaction will occur");
            
            if (!$this->confirm('Do you want to proceed with LIVE order creation?', false)) {
                $this->info("Order cancelled by user");
                return;
            }
        } else {
            $this->info("\n🧪 Testing Parts Unlimited order creation (TEST MODE)...");
        }
        
        // First check if the SKU is available
        $availability = $partsUnlimitedService->checkAvailability($testSku);
        
        if (!$availability || !$availability['available']) {
            $this->error("❌ Cannot test order creation - SKU not available in Parts Unlimited");
            return;
        }
        
        $this->info("✅ SKU available for order test");
        
        // Create test order data
        $testCartItems = [
            [
                'sku' => $testSku,
                'quantity' => 1,
                'price' => $availability['price'],
                'name' => $availability['name'] ?? 'Test Product'
            ]
        ];
        
        $testShippingInfo = [
            'ship_name' => $isLiveOrder ? 'Madd Parts Test Order' : 'Test Customer',
            'ship_address1' => '123 Test Street',
            'ship_address2' => 'Suite 100',
            'ship_city' => 'Test City',
            'ship_state' => 'TX',
            'ship_zip' => '75001',
            'email' => 'test@maddparts.com'
        ];
        
        if ($isLiveOrder) {
            $this->info("Creating LIVE order with Parts Unlimited...");
            // Force live mode by temporarily overriding the environment
            $originalTestMode = env('PARTS_UNLIMITED_TEST_MODE');
            putenv('PARTS_UNLIMITED_TEST_MODE=false');
            
            $orderResult = $partsUnlimitedService->createOrder($testCartItems, $testShippingInfo);
            
            // Restore original test mode
            putenv('PARTS_UNLIMITED_TEST_MODE=' . ($originalTestMode ? 'true' : 'false'));
        } else {
            $this->info("Creating test order with Parts Unlimited...");
            $orderResult = $partsUnlimitedService->createOrder($testCartItems, $testShippingInfo);
        }
        
        if ($orderResult['success']) {
            if ($isLiveOrder && !isset($orderResult['test_mode'])) {
                $this->info("🎉 LIVE ORDER CREATED SUCCESSFULLY!");
                $this->warn("⚠️  This is a REAL order that will be fulfilled");
            } else {
                $this->info("✅ Order created successfully!");
                if (isset($orderResult['test_mode'])) {
                    $this->warn("(This was a test order simulation)");
                }
            }
            
            $this->table(['Field', 'Value'], [
                ['PO Number', $orderResult['po_number']],
                ['Reference Number', $orderResult['reference_number'] ?? 'N/A'],
                ['Status Code', $orderResult['status_code'] ?? 'N/A'],
                ['Status Message', $orderResult['status_message'] ?? 'N/A'],
                ['Order Total', '$' . number_format($orderResult['order_total'] ?? 0, 2)],
                ['Mode', isset($orderResult['test_mode']) ? 'TEST' : 'LIVE']
            ]);
            
            if ($isLiveOrder && !isset($orderResult['test_mode'])) {
                $this->info("\n📋 Next Steps:");
                $this->info("1. Check your Parts Unlimited dealer portal");
                $this->info("2. Look for PO Number: " . $orderResult['po_number']);
                $this->info("3. Reference Number: " . ($orderResult['reference_number'] ?? 'N/A'));
                $this->info("4. Monitor shipping status");
            }
        } else {
            $this->error("❌ Order creation failed: " . $orderResult['error']);
        }
    }
}