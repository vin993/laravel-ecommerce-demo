<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\Turn14DropshipService;

class CheckTurn14Pricing extends Command
{
    protected $signature = 'check:turn14-pricing {--sku=}';
    protected $description = 'Verify Turn14 is using correct retail pricing instead of purchase_cost';

    public function handle()
    {
        $testSku = $this->option('sku');

        if (!$testSku) {
            $this->error("Please provide a SKU to test:");
            $this->info("  php artisan check:turn14-pricing --sku=YOUR_SKU");
            $this->info("\nTo find Turn14 SKUs, run:");
            $this->info("  SELECT our_sku FROM turn14_sku_mapping LIMIT 10;");
            return 1;
        }

        $this->info("Checking Turn14 pricing for SKU: {$testSku}");
        $this->info("=====================================\n");

        $turn14Service = app(Turn14DropshipService::class);

        $this->info("📋 Fetching product details from Turn14 API...\n");

        $availability = $turn14Service->checkAvailability($testSku);

        if ($availability && $availability['available']) {
            $this->info("✅ Product found in Turn14:");
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

            $this->info("\n💡 Price Field Details:");
            $this->info("   • retail_price = MSRP/Retail price (what customers should pay)");
            $this->info("   • map_price = Minimum Advertised Price");
            $this->info("   • jobber_price = Intermediate wholesale price");
            $this->info("   • purchase_cost = Dealer cost (wholesale)");

            $this->info("\n📊 Current Configuration (Turn14DropshipService.php:499):");
            $this->info("   Priority: retail_price → map_price → jobber_price → purchase_cost");

            $this->info("\n📝 To see ALL price fields from Turn14 API:");
            $this->info("   tail -f storage/logs/laravel.log | grep 'Turn14 Price Fields Available'");

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
            $this->error("❌ No Turn14 data found for SKU: {$testSku}");
            $this->info("\nPossible reasons:");
            $this->info("1. SKU not mapped in turn14_sku_mapping table");
            $this->info("2. Turn14 API credentials not configured");
            $this->info("3. Product not available in Turn14 catalog");

            $this->info("\nTo check mapped SKUs:");
            $this->info("  SELECT our_sku, turn14_item_id FROM turn14_sku_mapping LIMIT 10;");
        }

        $this->info("\n📌 Log Location:");
        $this->info("   storage/logs/laravel.log");

        return 0;
    }
}
