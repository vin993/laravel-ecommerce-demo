<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DataStreamToBagisto extends Command
{
    protected $signature = 'datastream:to-bagisto {--limit=100 : Number of records to process} {--dry-run : Show what would be created without actually creating} {--enhanced : Use enhanced transformation with automotive data} {--skip=0 : Number of records to skip (for resuming)}';
    protected $description = 'Transform DataStream staging data to Bagisto automotive products';
    
    private $brandCategories = [];
    private $processedBrands = [];

    public function handle()
    {
        $limit = $this->option('limit');
        $isDryRun = $this->option('dry-run');
        $enhanced = $this->option('enhanced');
        $skip = $this->option('skip');
        
        $this->info("🚗 Starting Enhanced DataStream → Bagisto Automotive Transformation");
        $this->info("📊 Processing {$limit} records" . ($skip > 0 ? " (skipping first {$skip})" : '') . ($isDryRun ? ' (DRY RUN)' : ''));
        
        if ($enhanced) {
            return $this->handleEnhancedTransformation($limit, $isDryRun, $skip);
        }
        
        return $this->handleBasicTransformation($limit, $isDryRun, $skip);
    }
    
    private function handleEnhancedTransformation($limit, $isDryRun, $skip = 0)
    {
        try {
            // Get rich Partmaster records with complete automotive data
            $records = DB::table('ari_staging_generic')
                ->whereRaw('JSON_EXTRACT(raw_data, "$.itemname") != ""')
                ->whereRaw('JSON_EXTRACT(raw_data, "$.itemname") IS NOT NULL')
                ->whereRaw('JSON_EXTRACT(raw_data, "$.manufacturerid") IS NOT NULL')
                ->skip($skip)
                ->limit($limit)
                ->get();
                
            if ($records->isEmpty()) {
                $this->error('❌ No enhanced records found to process');
                return 1;
            }
            
            $this->info("✅ Found {$records->count()} automotive products to transform");
            
            $created = 0;
            $skipped = 0;
            
            foreach ($records as $record) {
                $rawData = json_decode($record->raw_data, true);
                
                // Extract rich automotive data
                $itemName = $rawData['itemname'] ?? null;
                $description = $rawData['itemdescription'] ?? '';
                $manufacturerId = $rawData['manufacturerid'] ?? null;
                $partNumberLong = $rawData['manufacturernumberlong'] ?? null;
                $partNumberShort = $rawData['manufacturernumbershort'] ?? null;
                $partNumber = $partNumberLong ?: $partNumberShort;
                
                if (empty($itemName) || empty($manufacturerId)) {
                    $skipped++;
                    continue;
                }
                
                $sku = $partNumber ?: ('ARI-' . $record->ari_id);
                
                // Check if product already exists
                if (DB::table('products')->where('sku', $sku)->exists()) {
                    $skipped++;
                    continue;
                }
                
                if ($isDryRun) {
                    $this->line("Would create automotive product:");
                    $this->line("  📦 SKU: {$sku}");
                    $this->line("  🏷️  Name: {$itemName}");
                    $this->line("  🏭 Manufacturer ID: {$manufacturerId}");
                    if ($partNumber) $this->line("  🔧 Part Number: {$partNumber}");
                    if ($description) $this->line("  📝 Description: " . substr($description, 0, 100) . '...');
                    $this->line("");
                } else {
                    // Create/get manufacturer-based category
                    $categoryId = $this->getOrCreateManufacturerCategory($manufacturerId, $isDryRun);
                    
                    // Create Bagisto product
                    $productId = DB::table('products')->insertGetId([
                        'sku' => $sku,
                        'type' => 'simple',
                        'attribute_family_id' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    // Create comprehensive product_flat entry
                    $shortDescription = $this->buildShortDescription($rawData);
                    $fullDescription = $this->buildFullDescription($rawData);
                    
                    DB::table('product_flat')->insert([
                        'product_id' => $productId,
                        'sku' => $sku,
                        'type' => 'simple',
                        'name' => $itemName,
                        'short_description' => $shortDescription,
                        'description' => $fullDescription,
                        'url_key' => $this->generateUrlKey($itemName . '-' . $manufacturerId),
                        'status' => 1,
                        'attribute_family_id' => 1,
                        'channel' => 'default',
                        'locale' => 'en',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    // Link to brand category
                    if ($categoryId) {
                        DB::table('product_categories')->insert([
                            'product_id' => $productId,
                            'category_id' => $categoryId,
                        ]);
                    }
                    
                    $this->info("✅ Created: {$itemName} (Mfg: {$manufacturerId}) - SKU: {$sku}");
                }
                
                $created++;
                
                if ($created % 25 == 0) {
                    $this->info("📈 Processed {$created} automotive products...");
                }
            }
            
            $this->info("🎉 Enhanced transformation complete!");
            $this->displaySummary($created, $skipped, $isDryRun);
            
            return 0;
            
        } catch (Exception $e) {
            $this->error("❌ Enhanced transformation failed: " . $e->getMessage());
            Log::error('Enhanced DataStream transformation failed', ['error' => $e->getMessage()]);
            return 1;
        }
    }
    
    private function handleBasicTransformation($limit, $isDryRun, $skip = 0)
    {
        try {
            // Get sample of rich Partmaster records with product names
            $records = DB::table('ari_staging_generic')
                ->whereRaw('JSON_EXTRACT(raw_data, "$.itemname") != ""')
                ->whereRaw('JSON_EXTRACT(raw_data, "$.itemname") IS NOT NULL')
                ->skip($skip)
                ->limit($limit)
                ->get();
                
            if ($records->isEmpty()) {
                $this->error('❌ No records found to process');
                return 1;
            }
            
            $this->info("✅ Found {$records->count()} records to transform");
            
            $created = 0;
            $skipped = 0;
            
            // Create a basic category for DataStream products
            $categoryId = $this->ensureCategory('DataStream Vehicles', $isDryRun);
            
            foreach ($records as $record) {
                $rawData = json_decode($record->raw_data, true);
                
                if (empty($rawData['itemname'])) {
                    $skipped++;
                    continue;
                }
                
                $sku = 'DS-' . $record->ari_id;
                $name = $rawData['itemname'];
                $urlKey = $this->generateUrlKey($name);
                
                // Check if product already exists
                if (DB::table('products')->where('sku', $sku)->exists()) {
                    $skipped++;
                    continue;
                }
                
                if ($isDryRun) {
                    $this->line("Would create: SKU={$sku}, Name={$name}");
                } else {
                    // Insert into products table (minimal)
                    $productId = DB::table('products')->insertGetId([
                        'sku' => $sku,
                        'type' => 'simple',
                        'attribute_family_id' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    // Insert into product_flat table (main product data)
                    DB::table('product_flat')->insert([
                        'product_id' => $productId,
                        'sku' => $sku,
                        'type' => 'simple',
                        'name' => $name,
                        'short_description' => $rawData['description'] ?? 'Automotive part',
                        'description' => 'Imported from ARI DataStream. ID: ' . $record->ari_id,
                        'url_key' => $urlKey,
                        'status' => 1,
                        'attribute_family_id' => 1,
                        'channel' => 'default',
                        'locale' => 'en',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    // Link to category
                    DB::table('product_categories')->insert([
                        'product_id' => $productId,
                        'category_id' => $categoryId,
                    ]);
                }
                
                $created++;
                
                if ($created % 10 == 0) {
                    $this->info("Processed {$created} products...");
                }
            }
            
            $this->displaySummary($created, $skipped, $isDryRun);
            
            return 0;
            
        } catch (Exception $e) {
            $this->error("❌ Error during transformation: " . $e->getMessage());
            Log::error('DataStream to Bagisto transformation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }
    
    private function ensureCategory(string $name, bool $isDryRun): int
    {
        if ($isDryRun) {
            return 1; // Mock category ID for dry run
        }
        
        // Check if category already exists by checking translations
        $categoryTranslation = DB::table('category_translations')->where('name', $name)->first();
        
        if ($categoryTranslation) {
            return $categoryTranslation->category_id;
        }
        
        // Create new category
        $categoryId = DB::table('categories')->insertGetId([
            'position' => 999,
            'status' => 1,
            'display_mode' => 'products_and_description',
            '_lft' => 999,
            '_rgt' => 1000,
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Add translation
        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => str_replace(' ', '-', strtolower($name)),
            'description' => 'DataStream imported category',
            'locale_id' => 1,
            'locale' => 'en',
        ]);
        
        return $categoryId;
    }
    
    private function generateUrlKey(string $name): string
    {
        // Create URL-friendly key
        $urlKey = strtolower($name);
        $urlKey = preg_replace('/[^a-z0-9]+/', '-', $urlKey);
        $urlKey = trim($urlKey, '-');
        
        // Ensure uniqueness by adding random suffix if needed
        $baseKey = $urlKey;
        $counter = 1;
        
        while (DB::table('product_flat')->where('url_key', $urlKey)->exists()) {
            $urlKey = $baseKey . '-' . $counter;
            $counter++;
        }
        
        return $urlKey;
    }
    
    private function getOrCreateManufacturerCategory(string $manufacturerId, bool $isDryRun): ?int
    {
        if ($isDryRun) {
            return 1; // Mock category ID for dry run
        }
        
        $categoryName = "Manufacturer {$manufacturerId}";
        
        // Check cache first
        if (isset($this->brandCategories[$manufacturerId])) {
            return $this->brandCategories[$manufacturerId];
        }
        
        // Look for existing manufacturer category
        $categoryTranslation = DB::table('category_translations')->where('name', $categoryName)->first();
        
        if ($categoryTranslation) {
            $this->brandCategories[$manufacturerId] = $categoryTranslation->category_id;
            return $categoryTranslation->category_id;
        }
        
        // Create new manufacturer category
        $categoryId = DB::table('categories')->insertGetId([
            'position' => 100 + count($this->brandCategories),
            'status' => 1,
            'display_mode' => 'products_and_description',
            '_lft' => 100 + (count($this->brandCategories) * 2),
            '_rgt' => 101 + (count($this->brandCategories) * 2),
            'parent_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Add manufacturer category translation
        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'name' => $categoryName,
            'slug' => 'manufacturer-' . $manufacturerId,
            'description' => "Automotive parts from Manufacturer {$manufacturerId}",
            'locale_id' => 1,
            'locale' => 'en',
        ]);
        
        // Cache the result
        $this->brandCategories[$manufacturerId] = $categoryId;
        
        if (!in_array($manufacturerId, $this->processedBrands)) {
            $this->info("🏭 Created category for manufacturer: {$manufacturerId} (ID: {$categoryId})");
            $this->processedBrands[] = $manufacturerId;
        }
        
        return $categoryId;
    }
    
    private function buildShortDescription(array $rawData): string
    {
        $parts = [];
        
        if (!empty($rawData['manufacturerid'])) {
            $parts[] = "Manufacturer {$rawData['manufacturerid']}";
        }
        
        if (!empty($rawData['manufacturernumberlong'])) {
            $parts[] = "Part #" . $rawData['manufacturernumberlong'];
        } elseif (!empty($rawData['manufacturernumbershort'])) {
            $parts[] = "Part #" . $rawData['manufacturernumbershort'];
        }
        
        $description = implode(' | ', $parts);
        
        // Fallback to basic description if needed
        if (empty($description) && !empty($rawData['itemdescription'])) {
            $description = substr($rawData['itemdescription'], 0, 160);
        }
        
        return $description ?: 'Automotive part';
    }
    
    private function buildFullDescription(array $rawData): string
    {
        $description = "<h3>Automotive Part Details</h3>\n";
        
        if (!empty($rawData['itemname'])) {
            $description .= "<p><strong>Product:</strong> {$rawData['itemname']}</p>\n";
        }
        
        if (!empty($rawData['manufacturerid'])) {
            $description .= "<p><strong>Manufacturer ID:</strong> {$rawData['manufacturerid']}</p>\n";
        }
        
        if (!empty($rawData['manufacturernumberlong'])) {
            $description .= "<p><strong>Part Number:</strong> {$rawData['manufacturernumberlong']}</p>\n";
        } elseif (!empty($rawData['manufacturernumbershort'])) {
            $description .= "<p><strong>Part Number:</strong> {$rawData['manufacturernumbershort']}</p>\n";
        }
        
        if (!empty($rawData['itemdescription'])) {
            $description .= "<p><strong>Description:</strong> {$rawData['itemdescription']}</p>\n";
        }
        
        $description .= "<p><em>Imported from ARI DataStream automotive parts database</em></p>";
        
        return $description;
    }
    
    private function displaySummary($created, $skipped, $isDryRun)
    {
        $this->info("Transformation complete!");
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products ' . ($isDryRun ? 'would be created' : 'created'), $created],
                ['Records skipped', $skipped],
                ['Brand categories ' . ($isDryRun ? 'would be created' : 'created'), count($this->processedBrands)],
            ]
        );
        
        if (count($this->processedBrands) > 0) {
            $this->info("🏭 Brands processed: " . implode(', ', $this->processedBrands));
        }
        
        if (!$isDryRun) {
            $this->info("Check your Bagisto admin panel to see the imported automotive products!");
        }
    }
    
}
