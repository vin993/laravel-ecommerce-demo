<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\WpsDropshipService;
use Webkul\Product\Models\Product;

class TestWpsDropship extends Command
{
    protected $signature = 'test:wps-dropship {--sku=}';
    protected $description = 'Test WPS dropship integration';

    public function handle()
    {
        $wpsService = app(WpsDropshipService::class);
        
        $testSku = $this->option('sku');
        
        if ($testSku) {
            $this->testSingleSku($wpsService, $testSku);
        } else {
            $this->findWpsProducts($wpsService);
        }
        
        return 0;
    }
    
    private function testSingleSku($wpsService, $testSku)
    {
        $this->info("Testing WPS availability for SKU: {$testSku}");
        
        $availability = $wpsService->checkAvailability($testSku);
        
        if ($availability) {
            $this->info('WPS Response:');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', $availability['available'] ? 'Yes' : 'No'],
                    ['Price', '$' . number_format($availability['price'] ?? 0, 2)],
                    ['Inventory', $availability['inventory'] ?? 0],
                    ['WPS Item ID', $availability['wps_item_id'] ?? 'N/A'],
                    ['Name', $availability['name'] ?? 'N/A']
                ]
            );
        } else {
            $this->warn('No WPS availability data found for this SKU');
        }
    }
    
    private function findWpsProducts($wpsService)
    {
        $this->info('Searching for products available in both your database and WPS...');
        
        // Get simple products (not configurable parent products)
        $products = Product::where('type', 'simple')
            ->where('sku', 'not like', '%-parent')
            ->limit(20)
            ->get();
            
        if ($products->isEmpty()) {
            $this->error('No simple products found in database');
            return;
        }
        
        $this->info("Testing {$products->count()} products for WPS availability...");
        
        $foundProducts = [];
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();
        
        $testedSkus = [];
        
        foreach ($products as $product) {
            $testedSkus[] = $product->sku;
            $availability = $wpsService->checkAvailability($product->sku);
            
            if ($availability && $availability['available']) {
                $foundProducts[] = [
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'wps_price' => $availability['price'] ?? 0,
                    'wps_inventory' => $availability['inventory'] ?? 0,
                    'wps_item_id' => $availability['wps_item_id'] ?? 'N/A'
                ];
            }
            
            $bar->advance();
            usleep(100000); // Small delay to avoid overwhelming API
        }
        
        $bar->finish();
        $this->line('');
        
        if (empty($foundProducts)) {
            $this->warn('No products found that are available in both your database and WPS');
            
            $this->info('');
            $this->info('Sample SKUs tested:');
            foreach (array_slice($testedSkus, 0, 5) as $sku) {
                $this->line("  - {$sku}");
            }
            
            $this->info('');
            $this->info('This could mean:');
            $this->info('1. SKU formats don\'t match between your database and WPS');
            $this->info('2. WPS doesn\'t carry the same products');
            $this->info('3. WPS API credentials or connection issues');
            
            // Test WPS API with a known working SKU format to verify connection
            $this->info('');
            $this->info('Testing WPS API connection with sample SKU formats...');
            $testSkus = ['TEST123', 'WPS001', $testedSkus[0] ?? 'NOSKU'];
            
            foreach ($testSkus as $testSku) {
                $this->info("Testing: {$testSku}");
                $result = $wpsService->checkAvailability($testSku);
                if ($result !== null) {
                    $this->info('  → WPS API responded (SKU not found, but API is working)');
                    break;
                } else {
                    $this->warn('  → No response from WPS API');
                }
            }
            
            // Test some common motorcycle part SKU patterns
            $this->info('');
            $this->info('Testing common motorcycle part SKU patterns...');
            $commonSkus = [
                'BT-303-16',    // Battery
                'EBC-FA131',    // Brake pads
                'NGK-CR8E',     // Spark plug
                'K&N-KN-204',   // Air filter
                'PUROLATOR-L10241',  // Oil filter
                'WISECO-4608M',      // Piston
                'COMETIC-C8543',     // Gasket
            ];
            
            foreach ($commonSkus as $testSku) {
                $this->info("Testing common SKU: {$testSku}");
                $result = $wpsService->checkAvailability($testSku);
                if ($result && isset($result['available']) && $result['available']) {
                    $this->info("  → Found available product! SKU: {$testSku}");
                    $this->info("      Price: $" . number_format($result['price'] ?? 0, 2));
                    $this->info("      Inventory: " . ($result['inventory'] ?? 0));
                    $this->info("      You can test cart functionality with: php artisan test:wps-dropship --sku={$testSku}");
                    break;
                } elseif ($result !== null) {
                    $this->line("  → API responded but product not available");
                } else {
                    $this->line("  → No API response");
                }
                usleep(200000); // Longer delay for testing
            }
            
            // Test known WPS SKUs from the API response
            $this->info('');
            $this->info('Testing known WPS SKUs for inventory...');
            $wpsSkus = [
                '015-01001', '015-01002', '015-01003', '015-01004', '015-01005',
                '015-01010', '015-01011', '015-01012', '015-01013'
            ];
            
            foreach ($wpsSkus as $testSku) {
                $result = $wpsService->checkAvailability($testSku);
                if ($result && isset($result['available']) && $result['available']) {
                    $this->info("  ✓ Found WPS product with inventory! SKU: {$testSku}");
                    $this->info("      Name: " . ($result['name'] ?? 'N/A'));
                    $this->info("      Price: $" . number_format($result['price'] ?? 0, 2));
                    $this->info("      Inventory: " . ($result['inventory'] ?? 0));
                    $this->info("      Test cart with: php artisan test:wps-dropship --sku={$testSku}");
                    break;
                } elseif ($result !== null && isset($result['name'])) {
                    $this->line("  - {$testSku}: " . substr($result['name'], 0, 40) . " (No inventory)");
                } else {
                    $this->line("  - {$testSku}: No response");
                }
                usleep(100000);
            }
        } else {
            $this->info("Found {count($foundProducts)} products available in both systems:");
            
            $tableData = array_map(function($product) {
                return [
                    $product['sku'],
                    substr($product['name'], 0, 30) . (strlen($product['name']) > 30 ? '...' : ''),
                    '$' . number_format($product['wps_price'], 2),
                    $product['wps_inventory'],
                    $product['wps_item_id']
                ];
            }, array_slice($foundProducts, 0, 10));
            
            $this->table(
                ['SKU', 'Product Name', 'WPS Price', 'WPS Inventory', 'WPS Item ID'],
                $tableData
            );
            
            if (count($foundProducts) > 10) {
                $this->info('... and ' . (count($foundProducts) - 10) . ' more products');
            }
            
            // Suggest testing with one of these SKUs
            $firstMatch = $foundProducts[0];
            $this->info("");
            $this->info("You can test with a specific SKU using:");
            $this->info("php artisan test:wps-dropship --sku={$firstMatch['sku']}");
        }
    }
}