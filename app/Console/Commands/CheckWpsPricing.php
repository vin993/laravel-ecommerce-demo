<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\WpsDropshipService;
use Illuminate\Support\Facades\Log;

class CheckWpsPricing extends Command
{
    protected $signature = 'check:wps-pricing {--sku=26-1772}';
    protected $description = 'Verify WPS is using correct retail pricing instead of dealer pricing';

    public function handle()
    {
        $testSku = $this->option('sku');

        $this->info("Checking WPS pricing for SKU: {$testSku}");
        $this->info("=====================================\n");

        $wpsService = app(WpsDropshipService::class);

        // Clear any previous logs to make it easier to find
        $this->info("📋 Fetching product details from WPS API...\n");

        $availability = $wpsService->checkAvailability($testSku);

        if ($availability && $availability['available']) {
            $this->info("✅ Product found in WPS:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', 'Yes'],
                    ['Current Price Used', '$' . number_format($availability['price'] ?? 0, 2)],
                    ['List Price (Retail)', '$' . number_format($availability['list_price'] ?? 0, 2)],
                    ['Inventory', $availability['inventory'] ?? 0],
                    ['WPS Item ID', $availability['wps_item_id'] ?? 'N/A'],
                    ['Name', $availability['name'] ?? 'N/A'],
                ]
            );

            $this->info("\n💡 Price Field Details:");
            $this->info("   • list_price = Retail/MSRP price (what customers should pay)");
            $this->info("   • standard_dealer_price = Wholesale price (dealer cost)");

            $this->info("\n📊 Current Configuration (WpsDropshipService.php:103):");
            $this->info("   Priority: list_price → msrp → standard_dealer_price");

            if (isset($availability['list_price']) && $availability['list_price'] > 0) {
                if ($availability['price'] == $availability['list_price']) {
                    $this->info("\n✅ CORRECT: Using list_price (retail) = $" . number_format($availability['list_price'], 2));
                } else {
                    $this->warn("\n⚠️  WARNING: Price mismatch!");
                    $this->warn("   Current price: $" . number_format($availability['price'], 2));
                    $this->warn("   Expected (list_price): $" . number_format($availability['list_price'], 2));
                }
            }

            $this->info("\n📝 To see ALL price fields from WPS API:");
            $this->info("   tail -f storage/logs/laravel.log | grep 'WPS Price Fields Available'");

        } elseif ($availability) {
            $this->warn("⚠️  Product found but not available:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', 'No'],
                    ['Price', '$' . number_format($availability['price'] ?? 0, 2)],
                    ['Inventory', $availability['inventory'] ?? 0],
                    ['Name', $availability['name'] ?? 'N/A'],
                ]
            );
        } else {
            $this->error("❌ No WPS data found for SKU: {$testSku}");
            $this->info("\nTry testing with a different SKU:");
            $this->info("   php artisan check:wps-pricing --sku=YOUR_SKU");
        }

        $this->info("\n📌 Log Location:");
        $this->info("   storage/logs/laravel.log");

        return 0;
    }
}
