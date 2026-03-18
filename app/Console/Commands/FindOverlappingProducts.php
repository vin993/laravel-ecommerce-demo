<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\WpsDropshipService;
use Webkul\Product\Models\Product;

class FindOverlappingProducts extends Command
{
    protected $signature = 'find:overlapping-products {--limit=50 : Number of products to check} {--batch=10 : Products to process per batch}';
    protected $description = 'Find products that exist in both Bagisto database and WPS by checking all SKUs';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch');
        
        $wpsService = app(WpsDropshipService::class);
        
        $this->info("🔍 Searching for products that exist in both Bagisto and WPS...");
        $this->info("📊 Will check up to {$limit} products in batches of {$batchSize}");
        
        // Get products from Bagisto (without price columns since they don't exist)
        $products = Product::where('type', 'simple')
            ->where('sku', 'not like', '%-parent')
            ->limit($limit)
            ->get(['id', 'sku']);
            
        if ($products->isEmpty()) {
            $this->error('❌ No products found in Bagisto database');
            return 1;
        }
        
        $this->info("✅ Found {$products->count()} products in Bagisto to check");
        $this->newLine();
        
        $foundMatches = [];
        $checkedCount = 0;
        $errorCount = 0;
        
        $bar = $this->output->createProgressBar($products->count());
        $bar->setFormat('Checking: %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
        $bar->start();
        
        foreach ($products->chunk($batchSize) as $chunk) {
            foreach ($chunk as $product) {
                $bar->advance();
                $checkedCount++;
                
                try {
                    $availability = $wpsService->checkAvailability($product->sku);
                    
                    if ($availability && isset($availability['name']) && $availability['name'] !== null) {
                        $foundMatches[] = [
                            'bagisto_id' => $product->id,
                            'sku' => $product->sku,
                            'wps_name' => $availability['name'],
                            'wps_price' => $availability['price'] ?? 0,
                            'wps_list_price' => $availability['list_price'] ?? 0,
                            'wps_available' => $availability['available'] ?? false,
                            'wps_inventory' => $availability['inventory'] ?? 0,
                            'wps_item_id' => $availability['wps_item_id'] ?? null,
                            'drop_ship_eligible' => $availability['drop_ship_eligible'] ?? false
                        ];
                        
                        // Show real-time match
                        $bar->clear();
                        $this->info("🎉 MATCH FOUND: {$product->sku} - {$availability['name']}");
                        $bar->display();
                    }
                    
                    // Small delay to be respectful to WPS API
                    usleep(100000); // 100ms delay
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    if ($errorCount <= 3) { // Only show first few errors
                        $bar->clear();
                        $this->warn("⚠️  Error checking SKU {$product->sku}: " . substr($e->getMessage(), 0, 50));
                        $bar->display();
                    }
                }
            }
            
            // Brief pause between batches
            sleep(1);
        }
        
        $bar->finish();
        $this->newLine(2);
        
        // Display results
        if (empty($foundMatches)) {
            $this->warn('❌ No overlapping products found between Bagisto and WPS');
            $this->info('📝 This means:');
            $this->info('   • No duplicate listings to manage');
            $this->info('   • Clean multi-channel strategy possible');
            $this->info('   • Each supplier has unique product catalog');
        } else {
            $this->info("🎉 Found " . count($foundMatches) . " overlapping product(s)!");
            $this->newLine();
            
            $tableData = [];
            foreach ($foundMatches as $match) {
                $tableData[] = [
                    $match['sku'],
                    substr($match['wps_name'], 0, 35) . (strlen($match['wps_name']) > 35 ? '...' : ''),
                    '$' . number_format($match['wps_list_price'], 2),
                    '$' . number_format($match['wps_price'], 2),
                    $match['wps_available'] ? 'Yes' : 'No',
                    $match['wps_inventory'],
                    $match['drop_ship_eligible'] ? 'Yes' : 'No'
                ];
            }
            
            $this->table([
                'SKU',
                'WPS Product Name',
                'WPS List Price',
                'WPS Dealer Price', 
                'WPS Available',
                'WPS Inventory',
                'Dropship Eligible'
            ], $tableData);
            
            // Show testing suggestions
            $this->newLine();
            $this->info('🧪 Test dual-supplier functionality with these SKUs:');
            foreach (array_slice($foundMatches, 0, 3) as $match) {
                $this->line("   php artisan test:wps-dropship --sku={$match['sku']}");
            }
            
            // Show WPS pricing info
            $this->newLine();
            $this->info('💰 WPS Pricing Summary:');
            $availableCount = 0;
            $dropshipCount = 0;
            
            foreach ($foundMatches as $match) {
                if ($match['wps_available']) $availableCount++;
                if ($match['drop_ship_eligible']) $dropshipCount++;
            }
            
            $this->line("   • Products with WPS inventory: {$availableCount}");
            $this->line("   • Products eligible for dropship: {$dropshipCount}");
            $this->line("   • Average dealer discount: " . $this->calculateAverageDiscount($foundMatches) . "%");
        }
        
        $this->newLine();
        $this->info('📊 Summary:');
        $this->line("   • Products checked: {$checkedCount}");
        $this->line("   • Matches found: " . count($foundMatches));
        $this->line("   • API errors: {$errorCount}");
        $this->line("   • Success rate: " . number_format((($checkedCount - $errorCount) / $checkedCount) * 100, 1) . "%");
        
        return 0;
    }
    
    private function calculateAverageDiscount($matches)
    {
        if (empty($matches)) return 0;
        
        $totalDiscount = 0;
        $validItems = 0;
        
        foreach ($matches as $match) {
            $listPrice = $match['wps_list_price'];
            $dealerPrice = $match['wps_price'];
            
            if ($listPrice > 0 && $dealerPrice > 0) {
                $discount = (($listPrice - $dealerPrice) / $listPrice) * 100;
                $totalDiscount += $discount;
                $validItems++;
            }
        }
        
        return $validItems > 0 ? number_format($totalDiscount / $validItems, 1) : 0;
    }
}
