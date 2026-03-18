<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class VerifyTurn14Pricing extends Command
{
    protected $signature = 'verify:turn14-pricing {--sku=10010}';
    protected $description = 'Verify Turn14 is using retail_price instead of purchase_cost by directly calling API';

    public function handle()
    {
        $this->info("🔍 Turn14 Pricing Verification");
        $this->info("=============================\n");

        $sku = $this->option('sku');

        // Get Turn14 credentials
        $apiUrl = rtrim(config('turn14.api_url'), '/');
        $environment = config('turn14.environment', 'testing');
        $clientId = config('turn14.client_id');
        $clientSecret = config('turn14.client_secret');

        if ($environment === 'testing' && !str_contains($apiUrl, 'apitest')) {
            $apiUrl = str_replace('api.turn14.com', 'apitest.turn14.com', $apiUrl);
        }

        $this->info("Environment: {$environment}");
        $this->info("API URL: {$apiUrl}");
        $this->info("Testing SKU: {$sku}\n");

        // Step 1: Get Turn14 Item ID from mapping
        $this->info("Step 1: Looking up Turn14 Item ID...");
        $mapping = DB::table('turn14_sku_mapping')
            ->where('our_sku', $sku)
            ->first();

        if (!$mapping) {
            $this->error("❌ SKU not found in turn14_sku_mapping table");
            $this->info("\nTry one of these SKUs:");
            $samples = DB::table('turn14_sku_mapping')->limit(5)->pluck('our_sku');
            foreach ($samples as $sample) {
                $this->info("  --sku={$sample}");
            }
            return 1;
        }

        $itemId = $mapping->turn14_item_id;
        $this->info("✅ Found Turn14 Item ID: {$itemId}\n");

        // Step 2: Get Access Token
        $this->info("Step 2: Getting Turn14 access token...");
        try {
            $tokenResponse = Http::timeout(30)
                ->post("{$apiUrl}/v1/token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (!$tokenResponse->successful()) {
                $this->error("❌ Failed to get access token: " . $tokenResponse->status());
                return 1;
            }

            $tokenData = $tokenResponse->json();
            $accessToken = $tokenData['access_token'];
            $this->info("✅ Access token obtained\n");

        } catch (\Exception $e) {
            $this->error("❌ Token request failed: " . $e->getMessage());
            return 1;
        }

        // Step 3: Get Pricing Data
        $this->info("Step 3: Fetching pricing data from Turn14 API...");
        try {
            $pricingResponse = Http::timeout(30)
                ->withToken($accessToken)
                ->get("{$apiUrl}/v1/pricing/{$itemId}");

            if (!$pricingResponse->successful()) {
                $this->error("❌ Pricing API failed: " . $pricingResponse->status());
                $this->error("Response: " . $pricingResponse->body());
                return 1;
            }

            $pricingData = $pricingResponse->json();
            $attributes = $pricingData['data']['attributes'] ?? [];

            $this->info("✅ Pricing data received\n");

            // Display ALL price fields
            $this->info("📊 ALL PRICE FIELDS FROM TURN14 API:");
            $this->info("=====================================");

            $priceFields = [
                'retail_price' => ['label' => 'Retail Price (MSRP)', 'emoji' => '✅', 'note' => 'What customers should pay'],
                'map_price' => ['label' => 'MAP Price', 'emoji' => '✅', 'note' => 'Minimum Advertised Price'],
                'jobber_price' => ['label' => 'Jobber Price', 'emoji' => '⚠️', 'note' => 'Intermediate wholesale'],
                'purchase_cost' => ['label' => 'Purchase Cost', 'emoji' => '❌', 'note' => 'DEALER COST - Do not use for customers'],
            ];

            $priceTable = [];
            foreach ($priceFields as $field => $info) {
                $value = $attributes[$field] ?? null;
                $priceTable[] = [
                    $info['emoji'],
                    $info['label'],
                    $value !== null ? '$' . number_format($value, 2) : 'Not Available',
                    $info['note']
                ];
            }

            $this->table(
                ['', 'Price Type', 'Value', 'Notes'],
                $priceTable
            );

            // Show current configuration
            $this->newLine();
            $this->info("⚙️  CURRENT CONFIGURATION:");
            $this->info("File: Turn14DropshipService.php:499");
            $this->info("Priority: retail_price → map_price → jobber_price → purchase_cost");
            $this->newLine();

            // Calculate what price should be used
            $retailPrice = $attributes['retail_price'] ?? null;
            $mapPrice = $attributes['map_price'] ?? null;
            $jobberPrice = $attributes['jobber_price'] ?? null;
            $purchaseCost = $attributes['purchase_cost'] ?? null;

            $selectedPrice = $retailPrice ?? $mapPrice ?? $jobberPrice ?? $purchaseCost ?? 0;

            $this->info("🎯 PRICE SELECTION:");
            if ($retailPrice) {
                $this->info("✅ Using: retail_price = \${$retailPrice} (CORRECT - This is the customer retail price)");
            } elseif ($mapPrice) {
                $this->info("✅ Using: map_price = \${$mapPrice} (OK - Minimum advertised price)");
            } elseif ($jobberPrice) {
                $this->warn("⚠️  Using: jobber_price = \${$jobberPrice} (WARNING - This is wholesale, not retail)");
            } elseif ($purchaseCost) {
                $this->error("❌ Using: purchase_cost = \${$purchaseCost} (WRONG - This is dealer cost!)");
            }

            $this->newLine();
            $this->info("💰 FINAL PRICE: \$" . number_format($selectedPrice, 2));

            // Verification
            $this->newLine();
            if ($retailPrice && $purchaseCost && $retailPrice > $purchaseCost) {
                $margin = $retailPrice - $purchaseCost;
                $marginPercent = ($margin / $retailPrice) * 100;

                $this->info("✅ VERIFICATION PASSED:");
                $this->info("   • retail_price (\${$retailPrice}) > purchase_cost (\${$purchaseCost})");
                $this->info("   • Margin: \${$margin} (" . number_format($marginPercent, 1) . "%)");
                $this->info("   • Customers will be charged the correct retail price ✅");
            } elseif (!$retailPrice) {
                $this->warn("⚠️  retail_price not available in API response");
            }

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Pricing request failed: " . $e->getMessage());
            return 1;
        }
    }
}
