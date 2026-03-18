<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\Turn14DropshipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TestTurn14Pricing extends Command
{
    protected $signature = 'test:turn14-pricing {--sku=} {--item-id=} {--find-products}';
    protected $description = 'Test Turn14 pricing to verify retail price is used instead of purchase_cost';

    public function handle()
    {
        $this->info("🔍 Turn14 Pricing Test");
        $this->info("======================\n");

        $turn14Service = app(Turn14DropshipService::class);

        // Test connection first
        $this->info("Testing Turn14 API connection...");
        $connectionTest = $turn14Service->testConnection();

        if (!$connectionTest['success']) {
            $this->error("❌ Turn14 API connection failed");
            $this->error("Environment: " . $connectionTest['environment']);
            $this->error("API URL: " . $connectionTest['api_url']);
            if (isset($connectionTest['error'])) {
                $this->error("Error: " . $connectionTest['error']);
            }
            return 1;
        }

        $this->info("✅ Turn14 API connection successful");
        $this->info("Environment: " . $connectionTest['environment']);
        $this->info("API URL: " . $connectionTest['api_url']);
        $this->newLine();

        // Check if user wants to find products
        if ($this->option('find-products')) {
            $this->findTurn14Products();
            return 0;
        }

        // Check for SKU or Item ID
        $sku = $this->option('sku');
        $itemId = $this->option('item-id');

        if (!$sku && !$itemId) {
            // Try to find a product from catalog
            $this->info("No SKU or Item ID provided. Searching turn14_catalog table...\n");

            try {
                $catalogItem = DB::table('turn14_catalog')
                    ->whereNotNull('item_id')
                    ->first();

                if ($catalogItem) {
                    $this->info("Found catalog item:");
                    $this->table(['Field', 'Value'], [
                        ['Item ID', $catalogItem->item_id],
                        ['Part Number', $catalogItem->part_number ?? 'N/A'],
                        ['Brand', $catalogItem->brand ?? 'N/A'],
                        ['Product Name', $catalogItem->product_name ?? 'N/A'],
                    ]);

                    $itemId = $catalogItem->item_id;
                    $this->info("\nUsing Item ID: {$itemId}");
                    $this->newLine();
                } else {
                    $this->warn("No products found in turn14_catalog table");
                    $this->info("\nOptions:");
                    $this->info("1. Test with a specific SKU: --sku=YOUR_SKU");
                    $this->info("2. Test with a Turn14 Item ID: --item-id=ITEM_ID");
                    $this->info("3. Search for products: --find-products");
                    return 1;
                }
            } catch (\Exception $e) {
                $this->error("turn14_catalog table doesn't exist or is empty");
                $this->info("\nPlease provide a SKU or Item ID:");
                $this->info("  --sku=YOUR_SKU");
                $this->info("  --item-id=TURN14_ITEM_ID");
                return 1;
            }
        }

        // Test pricing
        if ($sku) {
            $this->testWithSku($turn14Service, $sku);
        } elseif ($itemId) {
            $this->testWithItemId($turn14Service, $itemId);
        }

        return 0;
    }

    private function testWithSku($turn14Service, $sku)
    {
        $this->info("Testing Turn14 pricing for SKU: {$sku}\n");

        $availability = $turn14Service->checkAvailability($sku);

        if ($availability && $availability['available']) {
            $this->info("✅ Product available in Turn14:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', 'Yes'],
                    ['Current Price Used', '$' . number_format($availability['price'] ?? 0, 2)],
                    ['Inventory', $availability['inventory'] ?? 0],
                    ['Turn14 Item ID', $availability['turn14_item_id'] ?? 'N/A'],
                    ['Source', $availability['source'] ?? 'N/A'],
                ]
            );

            $this->displayPricingInfo();
            $this->checkLogForPriceFields();

        } elseif ($availability) {
            $this->warn("⚠️  Product found but not available:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', 'No'],
                    ['Price', '$' . number_format($availability['price'] ?? 0, 2)],
                    ['Inventory', $availability['inventory'] ?? 0],
                    ['Turn14 Item ID', $availability['turn14_item_id'] ?? 'N/A'],
                ]
            );
        } else {
            $this->error("❌ No Turn14 data found for SKU: {$sku}");
            $this->info("\nPossible reasons:");
            $this->info("1. SKU not mapped in turn14_sku_mapping table");
            $this->info("2. Product not available in Turn14 catalog");
        }
    }

    private function testWithItemId($turn14Service, $itemId)
    {
        $this->info("Testing Turn14 pricing for Item ID: {$itemId}\n");

        // Create a temporary SKU mapping to test
        try {
            $tempSku = 'TEST-' . $itemId;

            // Check inventory endpoint directly
            $inventory = $turn14Service->checkInventory($itemId);

            $this->info("Inventory Check:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', $inventory['available'] ? 'Yes' : 'No'],
                    ['Quantity', $inventory['quantity'] ?? 0],
                    ['Source', $inventory['source'] ?? 'N/A'],
                ]
            );

            // Now test pricing using the availability check
            // We need to temporarily insert into mapping table
            $mappingExists = DB::table('turn14_sku_mapping')
                ->where('turn14_item_id', $itemId)
                ->exists();

            if (!$mappingExists) {
                $this->warn("\nNo SKU mapping found for Item ID: {$itemId}");
                $this->info("To test pricing, this item needs to be in turn14_sku_mapping table");
            }

            $this->displayPricingInfo();
            $this->checkLogForPriceFields();

        } catch (\Exception $e) {
            $this->error("Error testing Item ID: " . $e->getMessage());
        }
    }

    private function findTurn14Products()
    {
        $this->info("Searching for Turn14 products in database...\n");

        // Check catalog table
        try {
            $catalogCount = DB::table('turn14_catalog')->count();
            $this->info("turn14_catalog: {$catalogCount} items");

            if ($catalogCount > 0) {
                $samples = DB::table('turn14_catalog')
                    ->select('item_id', 'part_number', 'brand', 'product_name')
                    ->limit(10)
                    ->get();

                $this->info("\nSample Turn14 catalog items:");
                $tableData = $samples->map(function($item) {
                    return [
                        $item->item_id ?? 'N/A',
                        $item->part_number ?? 'N/A',
                        $item->brand ?? 'N/A',
                        substr($item->product_name ?? 'N/A', 0, 40)
                    ];
                })->toArray();

                $this->table(
                    ['Item ID', 'Part Number', 'Brand', 'Product Name'],
                    $tableData
                );

                $firstItem = $samples->first();
                $this->newLine();
                $this->info("To test pricing with a catalog item:");
                $this->info("  php artisan test:turn14-pricing --item-id={$firstItem->item_id}");
            }
        } catch (\Exception $e) {
            $this->warn("turn14_catalog table doesn't exist");
        }

        // Check mapping table
        try {
            $mappingCount = DB::table('turn14_sku_mapping')->count();
            $this->info("\nturn14_sku_mapping: {$mappingCount} items");

            if ($mappingCount > 0) {
                $samples = DB::table('turn14_sku_mapping')
                    ->limit(5)
                    ->get();

                $this->info("\nSample Turn14 SKU mappings:");
                $tableData = $samples->map(function($item) {
                    return [
                        $item->our_sku ?? 'N/A',
                        $item->turn14_item_id ?? 'N/A',
                    ];
                })->toArray();

                $this->table(['Our SKU', 'Turn14 Item ID'], $tableData);

                $firstMapping = $samples->first();
                $this->newLine();
                $this->info("To test pricing with a mapped SKU:");
                $this->info("  php artisan test:turn14-pricing --sku={$firstMapping->our_sku}");
            }
        } catch (\Exception $e) {
            $this->warn("turn14_sku_mapping table doesn't exist");
        }
    }

    private function displayPricingInfo()
    {
        $this->newLine();
        $this->info("💡 Turn14 Price Field Priority:");
        $this->info("   1. retail_price (MSRP/Retail - what customers pay) ✅");
        $this->info("   2. map_price (Minimum Advertised Price)");
        $this->info("   3. jobber_price (Intermediate wholesale)");
        $this->info("   4. purchase_cost (Dealer cost) ❌");
        $this->newLine();
        $this->info("📊 Current Configuration:");
        $this->info("   File: Turn14DropshipService.php:499");
        $this->info("   Code: retail_price ?? map_price ?? jobber_price ?? purchase_cost");
    }

    private function checkLogForPriceFields()
    {
        $this->newLine();
        $this->info("📝 To see ALL price fields returned by Turn14 API:");
        $this->info("   tail -f storage/logs/laravel.log | grep 'Turn14 Price Fields Available'");
        $this->newLine();
        $this->info("This will show:");
        $this->info("   • retail_price (what we should use)");
        $this->info("   • purchase_cost (dealer cost - don't use)");
        $this->info("   • All other available price fields");
    }
}
