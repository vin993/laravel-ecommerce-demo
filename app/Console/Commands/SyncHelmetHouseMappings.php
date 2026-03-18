<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Dropship\HelmetHouseDropshipService;
use Exception;

class SyncHelmetHouseMappings extends Command
{
    protected $signature = 'helmet-house:sync-mappings {--test-sku=} {--test-only} {--limit=50}';
    protected $description = 'Test specific Helmet House SKUs or random product SKUs';

    public function handle()
    {
        $testOnly = $this->option('test-only');
        $testSku = $this->option('test-sku');
        $limit = (int) $this->option('limit');

        // First create the mapping table if it doesn't exist
        $this->createMappingTable();

        if ($testSku) {
            return $this->testSpecificSku($testSku, $testOnly);
        }

        $this->info('Testing random product SKUs with Helmet House...');
        $this->newLine();

        // Get random products to test
        $products = $this->getRandomProducts($limit);

        if ($products->isEmpty()) {
            $this->warn('No products found to test');
            return Command::SUCCESS;
        }

        $this->info("Testing {$products->count()} product SKUs with Helmet House API");
        $this->newLine();

        $helmetHouseService = new HelmetHouseDropshipService();

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        $foundCount = 0;
        $errorCount = 0;

        foreach ($products as $product) {
            try {
                // Test if this SKU works with Helmet House
                $availability = $helmetHouseService->checkAvailability($product->sku);

                if ($availability && $availability['available']) {
                    $foundCount++;

                    if (!$testOnly) {
                        // Create mapping
                        DB::table('helmet_house_sku_mapping')->updateOrInsert(
                            ['our_sku' => $product->sku],
                            [
                                'helmet_house_sku' => $product->sku, // Same SKU
                                'product_name' => substr($product->name ?? 'Unknown', 0, 255),
                                'brand' => '100%',
                                'last_known_price' => $availability['price'],
                                'last_known_inventory' => $availability['inventory'],
                                'is_active' => true,
                                'last_checked_at' => now(),
                                'updated_at' => now(),
                                'created_at' => now()
                            ]
                        );
                    }

                    $this->newLine();
                    $this->info("✓ Found: {$product->sku} - \${$availability['price']} ({$availability['inventory']} in stock)");
                    $bar->display();
                }

            } catch (Exception $e) {
                $errorCount++;
                if ($errorCount <= 3) {
                    $this->newLine();
                    $this->error("Error checking {$product->sku}: " . $e->getMessage());
                    $bar->display();
                }
            }

            $bar->advance();
            usleep(500000); // 0.5 second delay to be nice to the API
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Sync completed!");
        $this->table(['Metric', 'Count'], [
            ['Products Checked', $products->count()],
            ['Helmet House Matches', $foundCount],
            ['Errors', $errorCount],
        ]);

        if ($testOnly) {
            $this->warn('This was a test run - no mappings were saved');
            $this->info('Run without --test-only to create the mappings');
        } else {
            $this->info('Mappings created! Helmet House integration is now active for these products.');
        }

        return Command::SUCCESS;
    }

    private function createMappingTable()
    {
        if (!DB::getSchemaBuilder()->hasTable('helmet_house_sku_mapping')) {
            DB::statement('
                CREATE TABLE helmet_house_sku_mapping (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    our_sku VARCHAR(255) NOT NULL,
                    helmet_house_sku VARCHAR(255) NOT NULL,
                    product_name VARCHAR(255) NULL,
                    brand VARCHAR(255) NULL,
                    last_known_price DECIMAL(10,2) NULL,
                    last_known_inventory INT NULL,
                    is_active BOOLEAN DEFAULT TRUE,
                    last_checked_at TIMESTAMP NULL,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL,
                    UNIQUE KEY unique_mapping (our_sku, helmet_house_sku),
                    INDEX idx_our_sku (our_sku),
                    INDEX idx_helmet_house_sku (helmet_house_sku),
                    INDEX idx_is_active (is_active)
                )
            ');

            $this->info('Created helmet_house_sku_mapping table');
        }
    }

    private function getRandomProducts($limit)
    {
        return DB::table('products as p')
            ->leftJoin('product_flat as pf', 'p.id', '=', 'pf.product_id')
            ->select('p.id', 'p.sku', 'pf.name')
            ->orderBy('p.id','desc')->limit(200)
            ->limit($limit)
            ->get();
    }
    
    private function testSpecificSku($sku, $testOnly)
    {
        $this->info("Testing specific SKU: {$sku}");
        $this->newLine();
        
        $helmetHouseService = new HelmetHouseDropshipService();
        
        try {
            $availability = $helmetHouseService->checkAvailability($sku);
            
            if ($availability && $availability['available']) {
                $this->info("✅ SKU {$sku} is available at Helmet House!");
                $this->table(['Property', 'Value'], [
                    ['SKU', $sku],
                    ['Price', '$' . $availability['price']],
                    ['Inventory', $availability['inventory']],
                    ['MAP Price', '$' . ($availability['map_price'] ?? 'N/A')],
                    ['Retail Price', '$' . ($availability['retail_price'] ?? 'N/A')],
                ]);
                
                if (!$testOnly) {
                    DB::table('helmet_house_sku_mapping')->updateOrInsert(
                        ['our_sku' => $sku],
                        [
                            'helmet_house_sku' => $sku,
                            'product_name' => $availability['name'] ?? 'Helmet House Product',
                            'brand' => 'Helmet House',
                            'last_known_price' => $availability['price'],
                            'last_known_inventory' => $availability['inventory'],
                            'is_active' => true,
                            'last_checked_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now()
                        ]
                    );
                    $this->info('✅ Mapping saved!');
                } else {
                    $this->warn('Test mode - mapping not saved');
                }
                
                return Command::SUCCESS;
            } else {
                $this->warn("❌ SKU {$sku} is not available at Helmet House");
                return Command::SUCCESS;
            }
            
        } catch (Exception $e) {
            $this->error("❌ Error testing SKU {$sku}: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
