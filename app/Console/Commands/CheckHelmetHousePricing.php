<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\HelmetHouseDropshipService;
use Illuminate\Support\Facades\DB;

class CheckHelmetHousePricing extends Command
{
    protected $signature = 'check:helmet-house-pricing {--sku=}';
    protected $description = 'Verify Helmet House is using correct retail pricing instead of dealer pricing';

    public function handle()
    {
        $testSku = $this->option('sku');

        if (!$testSku) {
            $this->info("Finding Helmet House products...\n");

            // Try to find a cached Helmet House product
            $cached = DB::table('supplier_cache')
                ->where('supplier', 'helmet_house')
                ->whereNotNull('price')
                ->first();

            if ($cached) {
                $testSku = $cached->sku;
                $this->info("Found cached SKU: {$testSku}");
                $this->info("Cached price: \${$cached->price}");
                $this->newLine();
            } else {
                $this->error("No SKU provided and no cached Helmet House products found.");
                $this->info("\nUsage:");
                $this->info("  php artisan check:helmet-house-pricing --sku=YOUR_SKU");
                return 1;
            }
        }

        $this->info("Checking Helmet House pricing for SKU: {$testSku}");
        $this->info("=====================================\n");

        $helmetHouseService = app(HelmetHouseDropshipService::class);

        // Clear cache for this SKU to get fresh data
        $this->info("Clearing cache for SKU {$testSku}...");
        DB::table('supplier_cache')
            ->where('sku', $testSku)
            ->where('supplier', 'helmet_house')
            ->delete();
        $this->info("Cache cleared.\n");

        $this->info("Fetching fresh data from Helmet House API...\n");

        $availability = $helmetHouseService->checkAvailability($testSku);

        if ($availability && $availability['available']) {
            $this->info("Product found in Helmet House:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Available', 'Yes'],
                    ['Current Price Used', '$' . number_format($availability['price'] ?? 0, 2)],
                    ['Retail Price', '$' . number_format($availability['retail_price'] ?? 0, 2)],
                    ['MAP Price', '$' . number_format($availability['map_price'] ?? 0, 2)],
                    ['Inventory', $availability['inventory'] ?? 0],
                    ['Helmet House SKU', $availability['helmet_house_sku'] ?? 'N/A'],
                    ['Name', $availability['name'] ?? 'N/A'],
                ]
            );

            $this->newLine();
            $this->info("Price Field Details:");
            $this->info("   retail_price = MSRP/Retail price (what customers should pay)");
            $this->info("   map_price = Minimum Advertised Price");
            $this->info("   price = Dealer cost (wholesale)");

            $this->newLine();
            $this->info("Current Configuration (HelmetHouseDropshipService.php:78):");
            $this->info("   Priority: retail_price -> map_price -> price");

            $retailPrice = $availability['retail_price'] ?? 0;
            $currentPrice = $availability['price'] ?? 0;

            $this->newLine();
            if ($retailPrice > 0 && abs($currentPrice - $retailPrice) < 0.01) {
                $this->info("CORRECT: Using retail_price = $" . number_format($retailPrice, 2));
            } elseif ($retailPrice > 0) {
                $this->warn("WARNING: Price mismatch!");
                $this->warn("   Current price: $" . number_format($currentPrice, 2));
                $this->warn("   Expected (retail_price): $" . number_format($retailPrice, 2));
            } else {
                $this->warn("WARNING: No retail_price available from API");
            }

        } elseif ($availability) {
            $this->warn("Product found but not available:");
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
            $this->error("No Helmet House data found for SKU: {$testSku}");
            $this->info("\nPossible reasons:");
            $this->info("1. SKU not available in Helmet House catalog");
            $this->info("2. Helmet House API credentials not configured");
        }

        $this->newLine();
        $this->info("Log Location:");
        $this->info("   storage/logs/laravel.log");

        return 0;
    }
}
