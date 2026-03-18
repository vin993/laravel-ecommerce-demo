<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Dropship\HelmetHouseDropshipService;
use Exception;

class AddHelmetHouseMapping extends Command
{
    protected $signature = 'helmet-house:add-mapping {--sku=} {--test-only}';
    protected $description = 'Add individual Helmet House SKU mappings';

    public function handle()
    {
        $sku = $this->option('sku');
        $testOnly = $this->option('test-only');
        
        if (!$sku) {
            $this->error('Please provide a SKU with --sku option');
            return Command::FAILURE;
        }
        
        $this->info("Testing Helmet House SKU: {$sku}");
        
        // First create the mapping table if it doesn't exist
        $this->createMappingTable();
        
        $helmetHouseService = new HelmetHouseDropshipService();
        
        try {
            // Test if this SKU works with Helmet House
            $availability = $helmetHouseService->checkAvailability($sku);
            
            if (!$availability || !$availability['available']) {
                $this->error("SKU {$sku} is not available in Helmet House");
                return Command::FAILURE;
            }
            
            $this->info("✓ SKU found in Helmet House!");
            $this->table(['Field', 'Value'], [
                ['SKU', $sku],
                ['Price', '$' . number_format($availability['price'], 2)],
                ['Inventory', $availability['inventory']],
                ['Weight', ($availability['weight'] ?? 0) . ' lbs']
            ]);
            
            if ($testOnly) {
                $this->warn('This was a test run - no mapping created');
                return Command::SUCCESS;
            }
            
            // Check if product exists in our database
            $product = DB::table('products')->where('sku', $sku)->first();
            
            if (!$product) {
                $this->warn("Product {$sku} not found in your products table");
                $this->info("You can still create the mapping for future use");
                
                if (!$this->confirm('Create mapping anyway?')) {
                    return Command::SUCCESS;
                }
            }
            
            // Create the mapping
            DB::table('helmet_house_sku_mapping')->updateOrInsert(
                ['our_sku' => $sku],
                [
                    'helmet_house_sku' => $sku, // Same SKU
                    'product_name' => 'Helmet House Product',
                    'brand' => 'Unknown',
                    'last_known_price' => $availability['price'],
                    'last_known_inventory' => $availability['inventory'],
                    'is_active' => true,
                    'last_checked_at' => now(),
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );
            
            $this->info("✓ Mapping created for SKU: {$sku}");
            
            if ($product) {
                $this->info("This product will now show Helmet House as a supplier option in checkout!");
            }
            
            return Command::SUCCESS;
            
        } catch (Exception $e) {
            $this->error("Error testing SKU {$sku}: " . $e->getMessage());
            return Command::FAILURE;
        }
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
}