<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\Turn14DropshipService;
use Illuminate\Support\Facades\DB;

class FindTurn14WithInventory extends Command
{
    protected $signature = 'find:turn14-inventory {--limit=10}';
    protected $description = 'Find Turn14 products with actual inventory for testing';

    public function handle()
    {
        $this->info("🔍 Searching for Turn14 products with inventory...\n");

        $turn14Service = app(Turn14DropshipService::class);
        $limit = (int) $this->option('limit');

        // Get mapped SKUs
        $mappings = DB::table('turn14_sku_mapping')
            ->limit(50)  // Test more SKUs to find one with inventory
            ->get();

        if ($mappings->isEmpty()) {
            $this->error("No Turn14 SKU mappings found");
            return 1;
        }

        $this->info("Testing {$mappings->count()} mapped SKUs for inventory...\n");

        $bar = $this->output->createProgressBar($mappings->count());
        $bar->start();

        $productsWithInventory = [];

        foreach ($mappings as $mapping) {
            $availability = $turn14Service->checkAvailability($mapping->our_sku);

            if ($availability && $availability['available'] && $availability['inventory'] > 0) {
                $productsWithInventory[] = [
                    'our_sku' => $mapping->our_sku,
                    'turn14_item_id' => $availability['turn14_item_id'] ?? $mapping->turn14_item_id,
                    'price' => $availability['price'] ?? 0,
                    'inventory' => $availability['inventory'] ?? 0,
                ];

                // Stop after finding enough products
                if (count($productsWithInventory) >= $limit) {
                    break;
                }
            }

            $bar->advance();
            usleep(100000); // Small delay to avoid overwhelming API
        }

        $bar->finish();
        $this->newLine(2);

        if (empty($productsWithInventory)) {
            $this->warn("❌ No Turn14 products with inventory found in the first {$mappings->count()} SKUs tested");
            $this->info("\nThis could mean:");
            $this->info("• Turn14 is in test mode with no real inventory");
            $this->info("• Products are out of stock");
            $this->info("• Need to test more SKUs");
            return 1;
        }

        $this->info("✅ Found " . count($productsWithInventory) . " Turn14 products with inventory:\n");

        $tableData = array_map(function($product) {
            return [
                $product['our_sku'],
                $product['turn14_item_id'],
                '$' . number_format($product['price'], 2),
                $product['inventory'],
            ];
        }, $productsWithInventory);

        $this->table(
            ['Our SKU', 'Turn14 Item ID', 'Price', 'Inventory'],
            $tableData
        );

        $firstProduct = $productsWithInventory[0];
        $this->newLine();
        $this->info("🧪 To test pricing with one of these products:");
        $this->info("  sudo -u www-data php artisan test:turn14-pricing --sku={$firstProduct['our_sku']}");
        $this->newLine();
        $this->info("Then check the logs to verify retail_price is being used:");
        $this->info("  sudo tail -100 storage/logs/laravel.log | grep -A 10 'Turn14 Price Fields Available'");

        return 0;
    }
}
