<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class ScanTurn14PriceFields extends Command
{
    protected $signature = 'scan:turn14-price-fields {--limit=20}';
    protected $description = 'Scan multiple Turn14 products to find which price fields are actually available';

    public function handle()
    {
        $this->info("🔍 Scanning Turn14 Products for Available Price Fields");
        $this->info("====================================================\n");

        $limit = (int) $this->option('limit');

        // Get Turn14 credentials
        $apiUrl = rtrim(config('turn14.api_url'), '/');
        $environment = config('turn14.environment', 'testing');
        $clientId = config('turn14.client_id');
        $clientSecret = config('turn14.client_secret');

        if ($environment === 'testing' && !str_contains($apiUrl, 'apitest')) {
            $apiUrl = str_replace('api.turn14.com', 'apitest.turn14.com', $apiUrl);
        }

        $this->info("Environment: {$environment}");
        $this->info("API URL: {$apiUrl}\n");

        // Get access token
        try {
            $tokenResponse = Http::timeout(30)
                ->post("{$apiUrl}/v1/token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (!$tokenResponse->successful()) {
                $this->error("Failed to get access token");
                return 1;
            }

            $accessToken = $tokenResponse->json()['access_token'];
            $this->info("✅ Access token obtained\n");

        } catch (\Exception $e) {
            $this->error("Token error: " . $e->getMessage());
            return 1;
        }

        // Get sample SKU mappings
        $mappings = DB::table('turn14_sku_mapping')
            ->limit($limit * 2)  // Get more to account for failures
            ->get();

        if ($mappings->isEmpty()) {
            $this->error("No Turn14 SKU mappings found");
            return 1;
        }

        $this->info("Scanning {$mappings->count()} products for price field availability...\n");

        $bar = $this->output->createProgressBar($mappings->count());
        $bar->start();

        $priceFieldStats = [
            'retail_price' => 0,
            'map_price' => 0,
            'jobber_price' => 0,
            'purchase_cost' => 0,
        ];

        $scannedCount = 0;
        $examples = [];

        foreach ($mappings as $mapping) {
            try {
                $pricingResponse = Http::timeout(15)
                    ->withToken($accessToken)
                    ->get("{$apiUrl}/v1/pricing/{$mapping->turn14_item_id}");

                if ($pricingResponse->successful()) {
                    $attributes = $pricingResponse->json()['data']['attributes'] ?? [];

                    // Count which fields are present
                    foreach ($priceFieldStats as $field => $count) {
                        if (isset($attributes[$field]) && $attributes[$field] > 0) {
                            $priceFieldStats[$field]++;

                            // Save an example if we don't have one yet
                            if (!isset($examples[$field])) {
                                $examples[$field] = [
                                    'sku' => $mapping->our_sku,
                                    'item_id' => $mapping->turn14_item_id,
                                    'value' => $attributes[$field],
                                    'all_prices' => [
                                        'retail_price' => $attributes['retail_price'] ?? null,
                                        'map_price' => $attributes['map_price'] ?? null,
                                        'jobber_price' => $attributes['jobber_price'] ?? null,
                                        'purchase_cost' => $attributes['purchase_cost'] ?? null,
                                    ]
                                ];
                            }
                        }
                    }

                    $scannedCount++;
                }

                $bar->advance();
                usleep(50000); // Small delay to avoid overwhelming API

                if ($scannedCount >= $limit) {
                    break;
                }

            } catch (\Exception $e) {
                // Skip failed requests
                $bar->advance();
                continue;
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Display results
        $this->info("📊 SCAN RESULTS (from {$scannedCount} products):");
        $this->info("===============================================\n");

        $tableData = [];
        foreach ($priceFieldStats as $field => $count) {
            $percentage = $scannedCount > 0 ? round(($count / $scannedCount) * 100, 1) : 0;
            $emoji = $count > 0 ? '✅' : '❌';

            $tableData[] = [
                $emoji,
                $field,
                $count . ' / ' . $scannedCount,
                $percentage . '%',
            ];
        }

        $this->table(
            ['', 'Price Field', 'Found', 'Availability'],
            $tableData
        );

        // Show examples
        if (!empty($examples)) {
            $this->newLine();
            $this->info("📋 EXAMPLE PRODUCTS WITH EACH PRICE FIELD:");
            $this->info("=========================================\n");

            foreach ($examples as $field => $example) {
                $this->info("🔸 {$field}:");
                $this->info("   SKU: {$example['sku']}");
                $this->info("   Turn14 Item ID: {$example['item_id']}");
                $this->info("   Value: \$" . number_format($example['value'], 2));
                $this->info("   All Prices:");
                foreach ($example['all_prices'] as $priceField => $value) {
                    $valueStr = $value !== null ? '$' . number_format($value, 2) : 'Not Set';
                    $this->info("      • {$priceField}: {$valueStr}");
                }
                $this->newLine();
            }
        }

        // Conclusion
        $this->newLine();
        if ($priceFieldStats['retail_price'] > 0) {
            $this->info("✅ GOOD NEWS: Turn14 DOES provide retail_price for some products!");
            $this->info("   Our code will use retail_price when available.");
        } else {
            $this->warn("⚠️  WARNING: Turn14 does NOT provide retail_price for any tested products!");
            $this->warn("   This could mean:");
            $this->warn("   1. You're using Turn14 TEST API (only has purchase_cost)");
            $this->warn("   2. Your Turn14 account doesn't have access to retail pricing");
            $this->warn("   3. These specific products don't have retail pricing configured");
            $this->newLine();
            $this->error("❌ ACTION REQUIRED:");
            $this->error("   Contact Turn14 support to confirm retail pricing availability");
            $this->error("   Your account may need to be upgraded or configured differently");
        }

        // Show what price is being used
        $this->newLine();
        $this->info("💰 CURRENT PRICE PRIORITY (Turn14DropshipService.php:499):");
        $this->info("   1. retail_price (preferred)");
        $this->info("   2. map_price (backup)");
        $this->info("   3. jobber_price (backup)");
        $this->info("   4. purchase_cost (last resort - dealer cost)");

        return 0;
    }
}
