<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductAttributeValue;
use Webkul\Category\Models\Category;
use Webkul\Attribute\Models\Attribute;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Repositories\ProductRepository;
// use Webkul\Product\Models\ProductSuperAttribute; // Not available in this Bagisto version

class AriFreshImport extends Command
{
    protected $signature = 'ari:fresh-import {--batch=1000} {--skip=0} {--dry-run} {--verify=0} {--find-stocked}';
    protected $description = 'Fresh import from extracted TXT files, excluding exploded view kit components with variant support';

    private $basePath;
    private $kits = [];
    private $manufacturers = [];
    private $variantGroups = [];
    private $createdVariantAttributes = [];
    private $productRepository;
    // Remove large arrays to save memory - we'll stream these files instead

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('ARI Fresh Import Started with automatic variant detection');
        
        // Initialize Bagisto repositories
        $this->productRepository = app('Webkul\Product\Repositories\ProductRepository');
        
        // Detect latest extracted folder
        $this->basePath = $this->detectLatestExtractedPath();
        if (!$this->basePath) {
            $this->error('No extracted data folders found');
            return Command::FAILURE;
        }
        
        $this->info("Reading from: {$this->basePath}");
        $this->info("Batch size: {$batch}" . ($skip > 0 ? ", skipping first {$skip}" : ''));

        if (!File::exists($this->basePath)) {
            $this->error("Path not found: {$this->basePath}");
            return Command::FAILURE;
        }

        try {
            // Load minimal supporting data first
        $this->info('Loading supporting data...');
            $this->loadKits();
            $this->loadManufacturers();

            // Load and process Partmaster products
            $this->info('Loading Partmaster products...');
            $products = $this->loadPartmaster();
            
            if (empty($products)) {
                $this->error('No products found in Partmaster.txt');
                return Command::FAILURE;
            }

            // Filter out exploded view components
            $totalBeforeFilter = count($products);
            $primaryProducts = $this->filterPrimaryProducts($products);
            $total = count($primaryProducts);
            $filteredCount = $totalBeforeFilter - $total;

            $this->info("Original products: {$totalBeforeFilter}");
            $this->info("Parent kit products filtered out: {$filteredCount}");
            $this->info("Individual products remaining: {$total}");
            
            // Apply batch and skip BEFORE variant analysis to avoid processing everything
            if ($skip >= $total) {
                $this->error("Skip offset {$skip} >= total {$total}. Nothing to process.");
                return Command::FAILURE;
            }
            
            // Get only the products we need to process (skip + batch)
            $batchProducts = array_slice($primaryProducts, $skip, $batch + 1000); // Add buffer for variant analysis
            $this->info("Processing subset: " . count($batchProducts) . " products for variant analysis");
            
            // Always analyze for variants (but only on the subset)
            $this->info('Analyzing products for variants...');
            $processableItems = $this->groupProductsForVariants($batchProducts);
            $this->info("After variant grouping: " . count($processableItems) . " items to process (mix of single products and variant groups)");
            
            if ($dryRun) {
                $this->info('DRY-RUN MODE - No actual import will be performed');
                $verifyCount = (int) $this->option('verify');
                if ($verifyCount > 0) {
                    // For verification, we need the original products, not grouped items
                    $verifyProducts = array_slice($primaryProducts, $skip, $verifyCount);
                    $this->info("Verifying first {$verifyCount} products for complete data (price, stock, images, attributes, category)...");
                    $this->dryRunVerifyProducts($verifyProducts, $verifyCount);
                    return Command::SUCCESS;
                }
            }

            $processed = 0;
            $created = 0;
            
            // Apply final batch limit to the grouped items
            $finalBatch = array_slice($processableItems, 0, $batch);
            
            foreach ($finalBatch as $productData) {
                $processed++;

                if ($dryRun) {
                    // Show progress in dry-run
                    if ($processed % 10 === 0 || $processed <= 10) {
                        $this->info("Progress: {$processed}/" . count($finalBatch) . " analyzed");
                    }
                    continue;
                }

                // Check if this is a variant group or single product
                if (isset($productData['is_variant_group']) && $productData['is_variant_group']) {
                    // Check if variant group already exists
                    $parentSku = $this->getProductSku($productData['parent_product']) . '-parent';
                    if (DB::table('products')->where('sku', $parentSku)->exists()) {
                        $this->line("Variant group already exists: {$productData['base_name']} [SKU: {$parentSku}]");
                        continue;
                    }
                    // Process variant group
                    $this->processVariantGroup($productData, $created, $batch);
                    $created++;
                } else {
                    // Process single product
                    $sku = $this->getProductSku($productData);
                    $name = $productData['ItemName'] ?? ('Product ' . $sku);
                    
                    // Skip if already exists
                    if (DB::table('products')->where('sku', $sku)->exists()) {
                        $this->line("Already exists: {$name} [SKU: {$sku}]");
                        continue;
                    }

                    DB::beginTransaction();
                    try {
                        // Create complete product
                        $productId = $this->createCompleteProduct($productData);
                        
                        DB::commit();
                        $created++;
                        
                        $this->info("[{$created}/{$batch}] {$name} [SKU: {$sku}]");
                        
                        // Memory cleanup
                        gc_collect_cycles();
                        
                    } catch (Exception $e) {
                        DB::rollBack();
                        $this->error("Failed: {$name} [SKU: {$sku}] - " . $e->getMessage());
                        Log::error('ARI fresh import product failure', [
                            'sku' => $sku,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            if ($dryRun) {
                $this->info('DRY-RUN ANALYSIS COMPLETE');
                $this->table(['Metric', 'Count'], [
                    ['Total original products', $totalBeforeFilter],
                    ['Parent kit products filtered out', $filteredCount],
                    ['Individual products remaining', $total],
                    ['Batch for analysis', count($batchProducts)],
                    ['Items after variant grouping', count($processableItems)],
                    ['Final batch processed', $processed],
                ]);
            } else {
                $this->info('Fresh import complete!');
                $this->table(['Metric', 'Count'], [
                    ['Total original products', $totalBeforeFilter],
                    ['Parent kit products filtered', $filteredCount],
                    ['Individual products remaining', $total],
                    ['Batch for analysis', count($batchProducts)],
                    ['Items after variant grouping', count($processableItems)],
                    ['Processed', $processed],
                    ['Created', $created],
                ]);
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Fatal: ' . $e->getMessage());
            Log::error('ARI fresh import fatal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function loadKits(): void
    {
        $file = $this->basePath . '/Kits.txt';
        if (!File::exists($file)) {
            $this->warn('Kits.txt not found - will not filter exploded views');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $totalLines = 0;
        $skippedLines = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            $totalLines++;
            
            // Skip rows with mismatched column counts
            if (count($header) !== count($data)) {
                $skippedLines++;
                continue;
            }
            
            $row = array_combine($header, $data);
            
            // Filter out PRIMARY kit products (parents), not replacement components
            // Primary_PartmasterID = the main kit product to exclude
            // Replacement_PartmasterID = individual components to keep
            $primaryKitId = null;
            if ($row && isset($row['Primary_PartmasterID'])) {
                $primaryKitId = trim($row['Primary_PartmasterID'], '"');
            }
            
            if ($primaryKitId) {
                $this->kits[] = $primaryKitId;
            }
        }
        
        $this->info("Loaded " . count($this->kits) . " parent kit products to filter out");
    }

    private function loadManufacturers(): void
    {
        $file = $this->basePath . '/Manufacturer.txt';
        if (!File::exists($file)) {
            $this->warn('Manufacturer.txt not found');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            // Skip rows with mismatched column counts
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['id'], $row['ManufacturerName'])) {
                $this->manufacturers[trim($row['id'], '"')] = trim($row['ManufacturerName'], '"');
            }
        }
        
        $this->info("Loaded " . count($this->manufacturers) . " manufacturers");
    }

    // Attributes loading removed to save memory - will stream when needed

    // Images and inventory loading removed to save memory - will stream when needed

    private function loadPartmaster(): array
    {
        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            return [];
        }

        $products = [];
        $lines = File::lines($file);
        $header = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            // Skip rows with mismatched column counts
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['ID'])) {
                // Clean the data
                foreach ($row as $key => $value) {
                    $row[$key] = trim($value, '"');
                }
                $products[] = $row;
            }
        }
        
        return $products;
    }

    private function filterPrimaryProducts(array $products): array
    {
        if (empty($this->kits)) {
            return $products; // No kits data, return all
        }

        $filtered = [];
        foreach ($products as $product) {
            $productId = $product['ID'] ?? null;
            if ($productId && !in_array($productId, $this->kits)) {
                $filtered[] = $product;
            }
        }
        
        return $filtered;
    }

    private function getProductSku(array $product): string
    {
        $long = $product['ManufacturerNumberLong'] ?? '';
        $short = $product['ManufacturerNumberShort'] ?? '';
        $id = $product['ID'] ?? '';
        
        return $long ?: ($short ?: "ARI-{$id}");
    }

    private function createCompleteProduct(array $product): int
    {
        $sku = $this->getProductSku($product);
        $name = $product['ItemName'] ?? ('Product ' . $sku);
        $description = $product['ItemDescription'] ?? '';
        $manufacturerId = $product['ManufacturerID'] ?? null;
        $partId = $product['ID'] ?? null;

        // Debug: Check for variant data
        $variantInfo = $this->analyzeProductVariants($product, $partId);
        
        // For now, create all as simple products until we have proper variant data
        // TODO: Implement full configurable product creation with child variants
        $productType = 'simple';
        
        if ($variantInfo['has_variants']) {
            $this->line("    🔍 VARIANT POTENTIAL: {$name}");
            $this->line("        Variant attributes: " . implode(', ', $variantInfo['variant_attributes']));
            $this->line("        Potential variants: {$variantInfo['variant_count']}");
            $this->line("        Creating as: simple product (variant info saved as attributes)");
        }

        // Get manufacturer category - COMMENTED OUT - categories synced separately
        // $categoryId = $this->ensureManufacturerCategory($manufacturerId);

        // Get inventory data
        $inventoryData = $this->getProductInventoryData($partId);
        $price = $inventoryData['price'] ?? null;
        $quantity = $inventoryData['quantity'] ?? 0;

        // Create base product using Bagisto Product model (like WPS service)
        $bagistoProduct = Product::create([
            'sku' => $sku,
            'type' => $productType,
            'attribute_family_id' => 1,
        ]);

        // Add product attributes using proper Bagisto system (matching WPS service)
        $this->addBagistoAttributes($bagistoProduct->id, $name, $description, $price, $product);

        // Add variant information as attributes if detected
        if ($variantInfo['has_variants']) {
            $this->addVariantAttributes($bagistoProduct->id, $variantInfo);
        }

        // Add variant information as attributes if detected
        if ($variantInfo['has_variants']) {
            $this->addVariantAttributes($bagistoProduct->id, $variantInfo);
        }

        // Add ARI specifications as product attributes
        $this->addAriSpecifications($bagistoProduct->id, $partId);

        // Create product_flat entry for catalog visibility
        $this->createProductFlat($bagistoProduct->id, $name, $description, $price, $sku, $product);

        // Link category - COMMENTED OUT - categories synced separately
        // if ($categoryId) {
        //     DB::table('product_categories')->insert([
        //         'product_id' => $bagistoProduct->id,
        //         'category_id' => $categoryId,
        //     ]);
        // }

        // Create inventory record
        $this->createProductInventory($bagistoProduct->id, $quantity);

        // Attach product images
        $this->attachProductImages($bagistoProduct->id, $partId);

        return $bagistoProduct->id;
    }

    private function ensureManufacturerCategory(?string $manufacturerId): ?int
    {
        $name = 'Manufacturer ' . ($manufacturerId ?: 'Unknown');
        
        if ($manufacturerId && isset($this->manufacturers[$manufacturerId])) {
            $name = $this->manufacturers[$manufacturerId];
        }

        // Check if category already exists by name
        $existing = DB::table('category_translations')
            ->where('name', $name)
            ->where('locale', 'en')
            ->first();
            
        if ($existing) {
            return (int) $existing->category_id;
        }

        try {
            // Get root category info
            $rootCategory = DB::table('categories')->where('id', 1)->first();
            if (!$rootCategory) {
                throw new Exception('Root category (ID 1) not found');
            }
            
            // Create category with proper nested set values
            $maxRgt = DB::table('categories')->max('_rgt') ?? 1;
            $newLeft = $maxRgt;
            $newRight = $maxRgt + 1;
            
            // Update root category to accommodate new child
            DB::table('categories')->where('id', 1)->update([
                '_rgt' => $maxRgt + 2,
                'updated_at' => now()
            ]);
            
            $categoryId = DB::table('categories')->insertGetId([
                'position' => DB::table('categories')->where('parent_id', 1)->count() + 1,
                'status' => 1,
                'display_mode' => 'products_and_description',
                '_lft' => $newLeft,
                '_rgt' => $newRight,
                'parent_id' => 1, // Use correct root category ID
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create translation with proper locale_id
            $localeId = DB::table('locales')->where('code', 'en')->value('id') ?? 1;
            DB::table('category_translations')->insert([
                'category_id' => $categoryId,
                'name' => $name,
                'slug' => $this->slugify($name),
                'description' => 'ARI DataStream manufacturer category',
                'locale' => 'en',
                'locale_id' => $localeId,
            ]);

            return $categoryId;
            
        } catch (Exception $e) {
            // Log error but don't fail the import
            Log::error('Failed to create manufacturer category', [
                'manufacturer_id' => $manufacturerId,
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function buildSimpleDescription(string $name, string $description, ?string $manufacturerId, string $sku): string
    {
        $html = '<h3>' . e($name) . '</h3>';
        
        if ($manufacturerId && isset($this->manufacturers[$manufacturerId])) {
            $html .= '<p><strong>Manufacturer:</strong> ' . e($this->manufacturers[$manufacturerId]) . '</p>';
        }
        
        $html .= '<p><strong>SKU:</strong> ' . e($sku) . '</p>';
        
        if ($description) {
            $html .= '<p>' . e($description) . '</p>';
        }
        
        $html .= '<p><em>Imported from ARI DataStream</em></p>';
        
        return $html;
    }

    private function getProductPrice(?string $partId): ?float
    {
        if (!$partId) {
            return null;
        }

        $file = $this->basePath . '/PartPriceInv.txt';
        if (!File::exists($file)) {
            return null;
        }

        $lines = File::lines($file);
        $header = null;
        $bestPrice = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID']) && trim($row['PartmasterID'], '"') === $partId) {
                $price = (float) ($row['MSRP'] ?? $row['StandardPrice'] ?? $row['BestPrice'] ?? 0);
                if ($price > $bestPrice) {
                    $bestPrice = $price;
                }
            }
        }
        
        return $bestPrice > 0 ? $bestPrice : null;
    }

    private function getProductInventoryData(?string $partId): array
    {
        if (!$partId) {
            return ['price' => null, 'quantity' => 0];
        }

        $file = $this->basePath . '/PartPriceInv.txt';
        if (!File::exists($file)) {
            return ['price' => null, 'quantity' => 0];
        }

        $lines = File::lines($file);
        $header = null;
        $bestPrice = 0;
        $totalQuantity = 0;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID']) && trim($row['PartmasterID'], '"') === $partId) {
                $price = (float) ($row['MSRP'] ?? $row['StandardPrice'] ?? $row['BestPrice'] ?? 0);
                if ($price > $bestPrice) {
                    $bestPrice = $price;
                }
                
                // Add quantities from correct columns in PartPriceInv.txt
                $qty = (int) ($row['DistributorQty'] ?? 0);
                $totalQuantity += $qty;
            }
        }
        
        return [
            'price' => $bestPrice > 0 ? $bestPrice : null,
            'quantity' => $totalQuantity
        ];
    }

    private function buildCompleteDescription(array $product, ?string $manufacturerId, string $sku, ?string $partId): string
    {
        $name = $product['ItemName'] ?? ('Product ' . $sku);
        $description = $product['ItemDescription'] ?? '';
        
        $html = '<h3>' . e($name) . '</h3>';
        
        if ($manufacturerId && isset($this->manufacturers[$manufacturerId])) {
            $html .= '<p><strong>Manufacturer:</strong> ' . e($this->manufacturers[$manufacturerId]) . '</p>';
        }
        
        $html .= '<p><strong>SKU:</strong> ' . e($sku) . '</p>';
        
        if ($description) {
            $html .= '<p><strong>Description:</strong> ' . e($description) . '</p>';
        }
        
        // Add product specifications from Partmaster data
        if (!empty($product['Weight'])) {
            $html .= '<p><strong>Weight:</strong> ' . e($product['Weight']) . '</p>';
        }
        
        if (!empty($product['Dimensions'])) {
            $html .= '<p><strong>Dimensions:</strong> ' . e($product['Dimensions']) . '</p>';
        }
        
        // Add attributes dynamically
        $attributes = $this->getProductAttributes($partId);
        if (!empty($attributes)) {
            $html .= '<hr><h4>Specifications</h4><ul>';
            foreach ($attributes as $attr) {
                $html .= '<li><strong>' . e($attr['name']) . ':</strong> ' . e($attr['value']) . '</li>';
            }
            $html .= '</ul>';
        }
        
        $html .= '<p><em>Imported from ARI DataStream</em></p>';
        
        return $html;
    }

    private function getProductAttributes(?string $partId): array
    {
        if (!$partId) {
            return [];
        }

        $attributesFile = $this->basePath . '/Attributes.txt';
        $attributesToPartsFile = $this->basePath . '/AttributesToParts.txt';
        
        if (!File::exists($attributesFile) || !File::exists($attributesToPartsFile)) {
            return [];
        }

        // Load attributes lookup
        $attributesLookup = [];
        $lines = File::lines($attributesFile);
        $header = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['id'], $row['Description'])) {
                $attributesLookup[trim($row['id'], '"')] = trim($row['Description'], '"');
            }
        }

        // Find attributes for this part
        $partAttributes = [];
        $lines = File::lines($attributesToPartsFile);
        $header = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['Partmasterid'], $row['attributesmasterid']) && 
                trim($row['Partmasterid'], '"') === $partId) {
                
                $attrId = trim($row['attributesmasterid'], '"');
                $attrDescription = $attributesLookup[$attrId] ?? "Attribute {$attrId}";
                
                $partAttributes[] = [
                    'name' => 'Specification',
                    'value' => $attrDescription
                ];
            }
        }
        
        return $partAttributes;
    }

    private function createProductInventory(int $productId, int $quantity): void
    {
        // Insert or update inventory record in product_inventories table only
        // Do not touch product_flat table as it doesn't have quantity/in_stock columns
        
        $existingInventory = DB::table('product_inventories')
            ->where('product_id', $productId)
            ->where('inventory_source_id', 1)
            ->first();
            
        if ($existingInventory) {
            // Update existing inventory
            DB::table('product_inventories')
                ->where('product_id', $productId)
                ->where('inventory_source_id', 1)
                ->update(['qty' => $quantity]);
        } else {
            // Create new inventory record
            DB::table('product_inventories')->insert([
                'product_id' => $productId,
                'inventory_source_id' => 1, // Default inventory source
                'vendor_id' => 0,
                'qty' => $quantity,
            ]);
        }
    }

    private function attachProductImages(int $productId, ?string $partId): void
    {
        if (!$partId) {
            return;
        }

        $file = $this->basePath . '/Images.txt';
        if (!File::exists($file)) {
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $position = 1;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID']) && trim($row['PartmasterID'], '"') === $partId) {
                $imagePath = trim($row['ImagePath'] ?? $row['Path'] ?? '', '"');
                $imageUrl = trim($row['ImageURL'] ?? $row['URL'] ?? '', '"');
                
                if ($imageUrl || $imagePath) {
                    $imageSource = $imageUrl ?: $imagePath;
                    
                    DB::table('product_images')->insert([
                        'product_id' => $productId,
                        'path' => $imageSource,
                        'type' => 'image',
                        'position' => $position,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    $position++;
                    
                    // Limit to 5 images per product to avoid bloat
                    if ($position > 5) {
                        break;
                    }
                }
            }
        }
    }

    private function addBagistoAttributes(int $productId, string $name, string $description, ?float $price, array $product): void
    {
        $this->persistAttributes($productId, $name, $description, $price, $product, true);
    }

    private function addBagistoVariantAttributes(int $productId, string $name, string $description, ?float $price, array $product): void
    {
        // For variants, set visible_individually = 0
        $this->persistAttributes($productId, $name, $description, $price, $product, false);
    }

    private function persistAttributes(int $productId, string $name, string $description, ?float $price, array $product, bool $visibleIndividually): void
    {
        // Use same pattern as WPS service - proper channel and unique_id
        $sku = $this->getProductSku($product);
        $urlKey = $this->uniqueUrlKey($name . '-' . $sku);
        $fullDescription = $this->buildSimpleDescription($name, $description, $product['ManufacturerID'] ?? null, $sku);
        
        $channel = 'maddparts';
        $locale = 'en';
        
        // Core product attributes
        $attributes = [
            'sku' => ['id' => 1, 'value' => $sku],
            'name' => ['id' => 2, 'value' => $name],
            'url_key' => ['id' => 3, 'value' => $urlKey],
            'short_description' => ['id' => 9, 'value' => mb_substr($description, 0, 200)],
            'description' => ['id' => 10, 'value' => $fullDescription],
            'status' => ['id' => 8, 'value' => 1, 'type' => 'boolean'],
            'visible_individually' => ['id' => 7, 'value' => $visibleIndividually ? 1 : 0, 'type' => 'boolean'],
        ];
        
        // Add price if available
        if ($price) {
            $attributes['price'] = ['id' => 11, 'value' => $price, 'type' => 'float'];
        }
        
        // Add weight if available
        $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
        $attributes['weight'] = ['id' => 22, 'value' => $weight, 'type' => 'text'];
        
        foreach ($attributes as $code => $attr) {
            $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . $attr['id'];
            
            $data = [
                'product_id' => $productId,
                'attribute_id' => $attr['id'],
                'locale' => $locale,
                'channel' => $channel,
                'unique_id' => $uniqueId,
            ];
            $type = $attr['type'] ?? 'text';
            if ($type === 'boolean') {
                $data['boolean_value'] = $attr['value'] ? 1 : 0;
            } elseif ($type === 'float') {
                $data['float_value'] = (float) $attr['value'];
            } else {
                $data['text_value'] = (string) $attr['value'];
            }
            ProductAttributeValue::updateOrCreate(
                [
                    'product_id' => $productId,
                    'attribute_id' => $attr['id'],
                    'locale' => $locale,
                    'channel' => $channel
                ],
                $data
            );
        }
    }

    private function dryRunVerifyProducts(array $products, int $verifyCount): void
    {
        $verifyProducts = array_slice($products, 0, $verifyCount);
        $report = [
            'products_checked' => 0,
            'with_pricing' => 0,
            'with_stock' => 0,
            'with_images' => 0,
            'with_attributes' => 0,
            'with_manufacturer' => 0,
            'complete_products' => 0
        ];

        $this->info("Starting product verification...");
        
        foreach ($verifyProducts as $index => $product) {
            $report['products_checked']++;
            $sku = $this->getProductSku($product);
            $name = $product['ItemName'] ?? ('Product ' . $sku);
            $partId = $product['ID'] ?? null;
            $manufacturerId = $product['ManufacturerID'] ?? null;

            $productNum = $index + 1;
            $this->line("Product #{$productNum}: {$name} [SKU: {$sku}]");

            // Check pricing
            $inventoryData = $this->getProductInventoryData($partId);
            $hasPrice = !empty($inventoryData['price']);
            $hasStock = $inventoryData['quantity'] > 0;
            
            if ($hasPrice) {
                $report['with_pricing']++;
                $this->line("    Price: $" . number_format($inventoryData['price'], 2));
            } else {
                $this->line("    Price: Not found");
            }

            // Check stock
            if ($hasStock) {
                $report['with_stock']++;
                $this->line("    Stock: {$inventoryData['quantity']} units");
            } else {
                $this->line("    Stock: 0 units");
            }

            // Check images
            $images = $this->getProductImages($partId);
            $hasImages = count($images) > 0;
            if ($hasImages) {
                $report['with_images']++;
                $this->line("    Images: " . count($images) . " found");
            } else {
                $this->line("    Images: None found");
            }

            // Check attributes
            $attributes = $this->getProductAttributes($partId);
            $hasAttributes = count($attributes) > 0;
            if ($hasAttributes) {
                $report['with_attributes']++;
                $this->line("    Attributes: " . count($attributes) . " found");
            } else {
                $this->line("    Attributes: None found");
            }

            // Check manufacturer
            $hasManufacturer = $manufacturerId && isset($this->manufacturers[$manufacturerId]);
            if ($hasManufacturer) {
                $report['with_manufacturer']++;
                $this->line("    Manufacturer: " . $this->manufacturers[$manufacturerId]);
            } else {
                $this->line("    Manufacturer: Unknown");
            }

            // Check if product is complete
            $isComplete = $hasPrice && $hasImages && $hasAttributes && $hasManufacturer;
            if ($isComplete) {
                $report['complete_products']++;
                $this->line("    Status: COMPLETE");
            } else {
                $this->line("    Status: INCOMPLETE");
            }

            $this->line("");
        }

        // Summary report
        $this->info("VERIFICATION COMPLETE");
        $this->table(['Data Type', 'Products with Data', 'Percentage'], [
            ['Pricing', $report['with_pricing'], $this->percentage($report['with_pricing'], $report['products_checked'])],
            ['Stock (>0)', $report['with_stock'], $this->percentage($report['with_stock'], $report['products_checked'])],
            ['Images', $report['with_images'], $this->percentage($report['with_images'], $report['products_checked'])],
            ['Attributes', $report['with_attributes'], $this->percentage($report['with_attributes'], $report['products_checked'])],
            ['Manufacturer', $report['with_manufacturer'], $this->percentage($report['with_manufacturer'], $report['products_checked'])],
            ['Complete Products', $report['complete_products'], $this->percentage($report['complete_products'], $report['products_checked'])],
        ]);

        if ($report['complete_products'] > ($report['products_checked'] * 0.8)) {
            $this->info("EXCELLENT: Most products have complete data for e-commerce");
        } elseif ($report['complete_products'] > ($report['products_checked'] * 0.5)) {
            $this->warn("GOOD: Many products have complete data, some missing elements");
        } else {
            $this->error("NEEDS WORK: Many products missing essential e-commerce data");
        }
    }

    private function getProductImages(?string $partId): array
    {
        if (!$partId) {
            return [];
        }

        $file = $this->basePath . '/Images.txt';
        if (!File::exists($file)) {
            return [];
        }

        $images = [];
        $lines = File::lines($file);
        $header = null;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            if (count($header) !== count($data)) {
                continue;
            }
            
            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID']) && trim($row['PartmasterID'], '"') === $partId) {
                $imagePath = trim($row['ImagePath'] ?? $row['Path'] ?? '', '"');
                $imageUrl = trim($row['ImageURL'] ?? $row['URL'] ?? '', '"');
                
                if ($imageUrl || $imagePath) {
                    $images[] = $imageUrl ?: $imagePath;
                }
                
                // Limit check to first 5 images
                if (count($images) >= 5) {
                    break;
                }
            }
        }
        
        return $images;
    }

    private function percentage(int $part, int $total): string
    {
        if ($total === 0) return '0%';
        return number_format(($part / $total) * 100, 1) . '%';
    }



    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        return trim($text, '-');
    }

    private function uniqueUrlKey(string $base): string
    {
        $url = $this->slugify($base);
        $baseKey = $url;
        $n = 1;
        
        // Check both product_attribute_values and product_flat for URL key conflicts
        while (DB::table('product_attribute_values')
                ->where('attribute_id', 3)
                ->where('channel', 'maddparts')
                ->where('text_value', $url)
                ->exists() 
            ||
            DB::table('product_flat')
                ->where('channel', 'maddparts')
                ->where('url_key', $url)
                ->exists()) {
            $url = $baseKey . '-' . $n;
            $n++;
        }
        
        return $url;
    }
    
    private function addAriSpecifications(int $productId, ?string $partId): void
    {
        if (!$partId) {
            return;
        }
        
        // Get ARI attributes for this part
        $attributes = $this->getProductAttributes($partId);
        
        if (empty($attributes)) {
            return;
        }
        
        $channel = 'maddparts';
        $locale = 'en';
        
        // For now, save specifications as text attributes using a generic attribute ID
        // In a full implementation, you'd create custom attributes for each specification type
        foreach ($attributes as $index => $attr) {
            $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . (100 + $index); // Use high attribute IDs to avoid conflicts
            
            try {
                // Create a specification entry
                $specName = $attr['name'] ?? 'Specification';
                $specValue = $attr['value'] ?? '';
                
                // Save as text attribute - you may need to create custom attributes in Bagisto for proper display
                ProductAttributeValue::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'attribute_id' => 100 + $index, // Custom attribute IDs starting from 100
                        'locale' => $locale,
                        'channel' => $channel
                    ],
                    [
                        'unique_id' => $uniqueId,
                        'text_value' => $specName . ': ' . $specValue,
                    ]
                );
                
            } catch (Exception $e) {
                // Log but don't fail import
                Log::warning('Failed to add ARI specification', [
                    'product_id' => $productId,
                    'part_id' => $partId,
                    'spec' => $attr,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    private function createProductFlat(int $productId, string $name, string $description, ?float $price, string $sku, array $product): void
    {
        $this->createFlatEntry($productId, $name, $description, $price, $sku, $product, true);
    }
    
    private function createVariantProductFlat(int $productId, string $name, string $description, ?float $price, string $sku, array $product): void
    {
        $this->createFlatEntry($productId, $name, $description, $price, $sku, $product, false);
    }
    
    private function createFlatEntry(int $productId, string $name, string $description, ?float $price, string $sku, array $product, bool $visibleIndividually): void
    {
        // Get the actual product type from database
        $actualType = DB::table('products')->where('id', $productId)->value('type') ?? 'simple';
        
        // Create product_flat entry
        $urlKey = $this->uniqueUrlKey($name . '-' . $sku);
        $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
        
        DB::table('product_flat')->insert([
            'product_id' => $productId,
            'sku' => $sku,
            'type' => $actualType,
            'name' => $name,
            'short_description' => mb_substr($description, 0, 200),
            'description' => $this->buildSimpleDescription($name, $description, $product['ManufacturerID'] ?? null, $sku),
            'url_key' => $urlKey,
            'price' => $price,
            'weight' => $weight,
            'status' => 1,
            'visible_individually' => $visibleIndividually ? 1 : 0,
            'attribute_family_id' => 1,
            'channel' => 'maddparts',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    
    private function analyzeProductVariants(array $product, ?string $partId): array
    {
        $result = [
            'has_variants' => false,
            'variant_attributes' => [],
            'variant_count' => 0,
            'potential_variants' => []
        ];
        
        if (!$partId) {
            return $result;
        }
        
        // Look for common variant indicators in the product data itself
        $baseName = $product['ItemName'] ?? '';
        $sku = $this->getProductSku($product);
        
        // Check for size/color patterns in product name or SKU
        $variantPatterns = [
            'size' => '/\b(XS|S|M|L|XL|XXL|XXXL|\d+["\']?|\d+\s*[xX]\s*\d+|\d+mm|\d+cm|\d+in)\b/i',
            'color' => '/\b(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear|transparent)\b/i',
            'material' => '/\b(steel|aluminum|plastic|rubber|carbon|titanium|brass|copper)\b/i',
            'quantity' => '/\b(\d+[-\s]*pack|set\s*of\s*\d+|\d+[-\s]*piece)\b/i',
        ];
        
        $detectedAttributes = [];
        foreach ($variantPatterns as $type => $pattern) {
            if (preg_match($pattern, $baseName . ' ' . $sku, $matches)) {
                $detectedAttributes[] = $type;
            }
        }
        
        // Look for related products with similar names (potential variants)
        if (!empty($baseName) && strlen($baseName) > 10) {
            $basePart = $this->extractBaseName($baseName);
            if ($basePart && strlen($basePart) >= 5) {
                $similarProducts = $this->findSimilarProducts($basePart, $partId);
                
                if (count($similarProducts) > 1) {
                    $result['has_variants'] = true;
                    $result['variant_count'] = count($similarProducts);
                    $result['potential_variants'] = array_slice($similarProducts, 0, 5); // Limit for debugging
                }
            }
        }
        
        // Check product attributes for variant indicators
        $attributes = $this->getProductAttributes($partId);
        foreach ($attributes as $attr) {
            $attrValue = strtolower($attr['value'] ?? '');
            foreach ($variantPatterns as $type => $pattern) {
                if (preg_match($pattern, $attrValue)) {
                    $detectedAttributes[] = $type;
                }
            }
        }
        
        $result['variant_attributes'] = array_unique($detectedAttributes);
        
        // If we found variant attributes but no similar products, still might be configurable
        if (count($result['variant_attributes']) >= 2 && !$result['has_variants']) {
            $result['has_variants'] = true;
            $result['variant_count'] = 1; // At least this product itself
        }
        
        return $result;
    }
    
    private function extractBaseName(string $productName): string
    {
        // Remove common variant indicators to get base name
        $baseName = $productName;
        
        // Remove size indicators
        $baseName = preg_replace('/\s*[-\(]\s*(XS|S|M|L|XL|XXL|XXXL|\d+["\']?|\d+\s*[xX]\s*\d+|\d+mm|\d+cm|\d+in)\s*[-\)]?\s*/i', '', $baseName);
        
        // Remove color indicators
        $baseName = preg_replace('/\s*[-\(]\s*(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear|transparent)\s*[-\)]?\s*/i', '', $baseName);
        
        // Remove quantity indicators
        $baseName = preg_replace('/\s*[-\(]\s*(\d+[-\s]*pack|set\s*of\s*\d+|\d+[-\s]*piece)\s*[-\)]?\s*/i', '', $baseName);
        
        return trim($baseName);
    }
    
    private function findSimilarProducts(string $baseName, string $currentPartId): array
    {
        // This is a simplified version - in production you'd want to cache this or use a more efficient search
        $similar = [];
        $file = $this->basePath . '/Partmaster.txt';
        
        if (!File::exists($file)) {
            return $similar;
        }
        
        $lines = File::lines($file);
        $header = null;
        $checked = 0;
        $maxCheck = 1000; // Limit search to avoid performance issues
        
        foreach ($lines as $line) {
            if ($checked++ > $maxCheck) break;
            
            $line = trim($line);
            if (empty($line)) continue;
            
            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }
            
            if (count($header) !== count($data)) continue;
            
            $row = array_combine($header, $data);
            if (!$row || !isset($row['ID'], $row['ItemName'])) continue;
            
            $partId = trim($row['ID'], '"');
            $itemName = trim($row['ItemName'], '"');
            
            // Skip current product
            if ($partId === $currentPartId) continue;
            
            // Check if this product name contains the base name
            if (stripos($itemName, $baseName) !== false) {
                $similar[] = [
                    'id' => $partId,
                    'name' => $itemName,
                    'sku' => $this->getProductSku($row)
                ];
            }
        }
        
        return $similar;
    }
    
    private function detectLatestExtractedPath(): ?string
    {
        // Server path for production
        $baseExtractedPath = '/var/www/html/test14/storage/app/datastream/extracted';
        
        // First priority: JonesboroCycleFull (main dataset)
        $fullPath = $baseExtractedPath . '/JonesboroCycleFull';
        if (File::exists($fullPath . '/Partmaster.txt')) {
            return $fullPath;
        }
        
        // Fallback: Find latest update folder
        if (File::exists($baseExtractedPath)) {
            $directories = File::directories($baseExtractedPath);
            $updateFolders = [];
            
            foreach ($directories as $dir) {
                $folderName = basename($dir);
                if (strpos($folderName, 'JonesboroCycleUpdate') === 0) {
                    $updateFolders[] = $dir;
                }
            }
            
            if (!empty($updateFolders)) {
                // Sort by folder name (date) descending to get latest
                rsort($updateFolders);
                
                foreach ($updateFolders as $folder) {
                    if (File::exists($folder . '/Partmaster.txt')) {
                        return $folder;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Group products by variant potential for configurable product creation
     */
    private function groupProductsForVariants(array $products): array
    {
        $grouped = [];
        $variantGroups = [];
        $processedIds = [];
        $totalProducts = count($products);
        $analyzed = 0;
        
        foreach ($products as $product) {
            $analyzed++;
            
            // Show progress every 1000 products
            if ($analyzed % 1000 === 0 || $analyzed === $totalProducts) {
                $this->line("Analyzed {$analyzed}/{$totalProducts} products for variants...");
            }
            $partId = $product['ID'] ?? null;
            if (!$partId || in_array($partId, $processedIds)) {
                continue;
            }
            
            // Analyze for variants
            $variantInfo = $this->findProductVariants($product, $products);
            
            if ($variantInfo['has_variants'] && count($variantInfo['variants']) > 1) {
                // Create variant group
                $group = [
                    'is_variant_group' => true,
                    'base_name' => $variantInfo['base_name'],
                    'parent_product' => $variantInfo['parent_product'],
                    'variants' => $variantInfo['variants'],
                    'super_attributes' => $variantInfo['super_attributes']
                ];
                
                $grouped[] = $group;
                
                // Mark all variant products as processed
                foreach ($variantInfo['variants'] as $variant) {
                    $processedIds[] = $variant['ID'];
                }
                
                $this->info("Found variant group: {$variantInfo['base_name']} with " . count($variantInfo['variants']) . " variants");
            } else {
                // Single product
                $grouped[] = $product;
                $processedIds[] = $partId;
            }
        }
        
        return $grouped;
    }
    
    /**
     * Find variants for a given product
     */
    private function findProductVariants(array $baseProduct, array $allProducts): array
    {
        $baseName = $baseProduct['ItemName'] ?? '';
        $baseManufacturer = $baseProduct['ManufacturerID'] ?? null;
        $variants = [$baseProduct]; // Include the base product
        $superAttributes = [];
        
        if (empty($baseName) || strlen($baseName) < 5) {
            return [
                'has_variants' => false,
                'variants' => [$baseProduct],
                'super_attributes' => [],
                'base_name' => $baseName,
                'parent_product' => $baseProduct
            ];
        }
        
        // Extract base name for comparison
        $extractedBaseName = $this->extractVariantBaseName($baseName);
        
        // Find similar products from same manufacturer (limit search for performance)
        $checkedCount = 0;
        $maxCheck = 5000; // Limit to avoid infinite loops
        
        foreach ($allProducts as $product) {
            if ($checkedCount++ > $maxCheck) break;
            
            if ($product['ID'] === $baseProduct['ID']) continue;
            if (($product['ManufacturerID'] ?? null) !== $baseManufacturer) continue;
            
            $productName = $product['ItemName'] ?? '';
            $productBaseName = $this->extractVariantBaseName($productName);
            
            // Check if base names are similar
            if ($this->areBaseNamesSimilar($extractedBaseName, $productBaseName)) {
                $variants[] = $product;
                
                // Limit variant group size to prevent massive groups
                if (count($variants) >= 20) {
                    break; // Max 20 variants per group
                }
            }
        }
        
        // If we found multiple variants, detect super attributes
        if (count($variants) > 1) {
            $superAttributes = $this->detectSuperAttributes($variants);
            
            // Only treat as variants if we can detect actual variant attributes
            if (empty($superAttributes)) {
                // No detectable variant attributes, treat as individual products
                return [
                    'has_variants' => false,
                    'variants' => [$baseProduct],
                    'super_attributes' => [],
                    'base_name' => $baseName,
                    'parent_product' => $baseProduct
                ];
            }
        }
        
        return [
            'has_variants' => count($variants) > 1,
            'variants' => $variants,
            'super_attributes' => $superAttributes,
            'base_name' => $extractedBaseName,
            'parent_product' => $baseProduct
        ];
    }
    
    /**
     * Extract base name by removing variant-specific parts
     */
    private function extractVariantBaseName(string $name): string
    {
        $cleaned = trim($name);
        
        // Don't process very short or generic names
        if (strlen($cleaned) < 10) {
            return $cleaned;
        }
        
        // Remove specific variant patterns (be more targeted)
        $patterns = [
            '/\s*-\s*(\d+T)\b/i', // Teeth count like "13T", "14T"
            '/\s*-\s*(\d+\.\d+"|\d+"|\.\d+")\b/i', // Inch measurements
            '/\s*-\s*(\d+mm|\d+cm)\b/i', // Metric measurements
            '/\s*-\s*(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear|transparent)\b/i', // Colors
        ];
        
        $originalLength = strlen($cleaned);
        
        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }
        
        $cleaned = trim($cleaned);
        
        // If we removed too much, it might not be a real variant pattern
        if (strlen($cleaned) < ($originalLength * 0.6)) {
            return trim($name); // Return original if we removed more than 40%
        }
        
        return $cleaned;
    }
    
    /**
     * Check if two base names are similar enough to be variants
     */
    private function areBaseNamesSimilar(string $name1, string $name2): bool
    {
        if (empty($name1) || empty($name2)) return false;
        
        // Exact match after cleanup
        if (trim($name1) === trim($name2)) return true;
        
        // Must be at least 10 characters to avoid generic matches
        if (strlen($name1) < 10 || strlen($name2) < 10) return false;
        
        // Check similarity percentage with stricter threshold
        $similarity = 0;
        similar_text(strtolower($name1), strtolower($name2), $similarity);
        
        // Very strict similarity - must be 95% similar
        return $similarity >= 95;
    }
    
    /**
     * Detect super attributes from variant differences
     */
    private function detectSuperAttributes(array $variants): array
    {
        $attributes = [];
        $detectedValues = [];
        
        // Analyze each variant to extract different attribute values
        foreach ($variants as $variant) {
            $name = $variant['ItemName'] ?? '';
            $sku = $this->getProductSku($variant);
            
            // Extract teeth count (common for sprockets)
            if (preg_match('/\b(\d+)T\b/i', $name . ' ' . $sku, $matches)) {
                $detectedValues['teeth'][] = $matches[1] . 'T';
            }
            
            // Extract size measurements
            if (preg_match('/\b(\d+(?:\.\d+)?(?:mm|cm|in|"))\b/i', $name . ' ' . $sku, $matches)) {
                $detectedValues['size'][] = $matches[1];
            }
            
            // Extract colors
            if (preg_match('/\b(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear)\b/i', $name, $matches)) {
                $detectedValues['color'][] = ucfirst(strtolower($matches[1]));
            }
        }
        
        // Create attribute definitions for detected variations
        foreach ($detectedValues as $attributeName => $values) {
            $uniqueValues = array_unique($values);
            if (count($uniqueValues) > 1) {
                $attributes[$attributeName] = [
                    'name' => ucfirst($attributeName),
                    'code' => $attributeName,
                    'values' => $uniqueValues,
                    'type' => 'select'
                ];
            }
        }
        
        return $attributes;
    }
    
    /**
     * Process a variant group to create configurable product with variants
     */
    private function processVariantGroup(array $variantGroup, int &$created, int $batch): void
    {
        $parentProduct = $variantGroup['parent_product'];
        $variants = $variantGroup['variants'];
        $superAttributes = $variantGroup['super_attributes'];
        $baseName = $variantGroup['base_name'];
        
        $parentSku = $this->getProductSku($parentProduct) . '-parent';
        $parentName = $baseName;
        
        DB::beginTransaction();
        try {
            // Debug: Show super attributes structure
            $this->line("  → Super attributes detected: " . json_encode($superAttributes));
            
            // Create parent configurable product
            $parentProductId = $this->createConfigurableProduct($parentProduct, $parentSku, $parentName, $superAttributes);
            
            $childCount = 0;
            
            // Create child variants using ProductRepository (Bagisto's way)
            foreach ($variants as $variant) {
                $childSku = $this->getProductSku($variant);
                
                // Skip if child already exists
                if (DB::table('products')->where('sku', $childSku)->exists()) {
                    continue;
                }
                
                // Create child variant using Bagisto's proper method
                $this->createVariantChild($parentProductId, $variant, $superAttributes);
                
                $childCount++;
            }
            
            // Super attributes are automatically handled by ProductRepository
            
            DB::commit();
            
            $this->info("[{$created}/{$batch}] VARIANT GROUP: {$parentName} with {$childCount} variants [Parent SKU: {$parentSku}]");
            $this->line("  → Created {$childCount} child variants (should be hidden from product list)");
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->error("Failed to create variant group: {$parentName} [SKU: {$parentSku}] - " . $e->getMessage());
            Log::error('ARI fresh import variant group failure', [
                'parent_sku' => $parentSku,
                'variants_count' => count($variants),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Create configurable parent product
     */
    private function createConfigurableProduct(array $baseProduct, string $sku, string $name, array $superAttributes): int
    {
        $manufacturerId = $baseProduct['ManufacturerID'] ?? null;
        $description = $baseProduct['ItemDescription'] ?? '';
        
        // Ensure super attribute codes exist (e.g., 'teeth', 'size', 'color')
        $superAttributeCodes = [];
        
        // Safety check: ensure $superAttributes is an array
        if (!is_array($superAttributes)) {
            $this->line("  → Warning: Super attributes is not an array, converting: " . gettype($superAttributes));
            $superAttributes = [];
        }
        
        foreach ($superAttributes as $attrCode => $attrData) {
            if (is_array($attrData) && isset($attrData['code'])) {
                $superAttributeCodes[] = $attrData['code'];
            } else {
                $superAttributeCodes[] = $attrCode; // Use the key as fallback
            }
        }
        
        // Create configurable parent - first create base product, then add super attributes
        $parent = Product::create([
            'type' => 'configurable',
            'attribute_family_id' => 1,
            'sku' => $sku,
        ]);
        
        // Add basic attributes
        $this->addBagistoAttributes($parent->id, $name, $description, null, $baseProduct);
        
        // Create super attributes manually since ProductRepository might expect different format
        // Safety check: ensure $superAttributes is an array
        if (!is_array($superAttributes)) {
            $this->line("  → Warning: Super attributes is not an array in createConfigurableProduct, skipping attribute creation");
            $superAttributes = [];
        }
        
        foreach ($superAttributes as $attrCode => $attrData) {
            $attributeId = $this->ensureVariantAttribute($attrData);
            
            // Create super attribute relationship using direct DB insertion
            if (!DB::table('product_super_attributes')
                ->where('product_id', $parent->id)
                ->where('attribute_id', $attributeId)
                ->exists()) {
                DB::table('product_super_attributes')->insert([
                    'product_id' => $parent->id,
                    'attribute_id' => $attributeId,
                ]);
            }
        }
        
        $parentId = $parent->id;
        
        // Set additional attributes/flat data
        $this->addBagistoAttributes($parentId, $name, $description, null, $baseProduct);
        $this->createProductFlat($parentId, $name, $description, null, $sku, $baseProduct);
        
        // Link manufacturer category - COMMENTED OUT - categories synced separately
        // $categoryId = $this->ensureManufacturerCategory($manufacturerId);
        // if ($categoryId) {
        //     DB::table('product_categories')->insert([
        //         'product_id' => $parentId,
        //         'category_id' => $categoryId,
        //     ]);
        // }
        
        return $parentId;
    }
    
    /**
     * Create child variant using Bagisto's ProductRepository
     */
    private function createVariantChild(int $parentProductId, array $variant, array $superAttributes): void
    {
        $sku = $this->getProductSku($variant);
        $name = $variant['ItemName'] ?? ('Variant ' . $sku);
        $partId = $variant['ID'] ?? null;
        
        // Get inventory data
        $inventoryData = $this->getProductInventoryData($partId);
        $price = $inventoryData['price'] ?? null;
        $quantity = $inventoryData['quantity'] ?? 0;
        
        // Build attribute values for this variant
        $attributeValues = [];
        
        // Safety check: ensure $superAttributes is an array
        if (!is_array($superAttributes)) {
            $this->line("  → Warning: Super attributes is not an array in createVariantChild, skipping attribute extraction");
            $superAttributes = [];
        }
        
        foreach ($superAttributes as $attrCode => $attrData) {
            $value = $this->extractVariantAttributeValue($name . ' ' . $sku, $attrCode);
            if ($value) {
                $attributeValues[$attrCode] = $value;
            }
        }
        
        // Create child variant - use direct Product model instead of ProductRepository
        // ProductRepository might not handle variant attributes properly
        $child = Product::create([
            'type' => 'simple',
            'parent_id' => $parentProductId,
            'attribute_family_id' => 1,
            'sku' => $sku,
        ]);
        
        // Set basic product attributes using our method (includes name, price, etc.)
        // Use variant-specific method that sets visible_individually = 0
        $this->addBagistoVariantAttributes($child->id, $name, $variant['ItemDescription'] ?? '', $price, $variant);
        
        // Set variant-specific attribute values manually
        $this->setVariantAttributeValues($child->id, $variant, $superAttributes);
        
        // Don't create product_flat entry for variants - they should only be accessible through parent
        // $this->createVariantProductFlat($child->id, $name, $variant['ItemDescription'] ?? '', $price, $sku, $variant);
        
        // Create parent-child relationship
        if (!DB::table('product_relations')
            ->where('parent_id', $parentProductId)
            ->where('child_id', $child->id)
            ->exists()) {
            DB::table('product_relations')->insert([
                'parent_id' => $parentProductId,
                'child_id' => $child->id,
            ]);
        }
        
        // Create inventory for the variant
        if ($quantity > 0) {
            $this->createProductInventory($child->id, $quantity);
        }
        
        // Attach images
        $this->attachProductImages($child->id, $partId);
        
        // $this->line("    Created variant child: {$name} [SKU: {$sku}] with attributes: " . json_encode($attributeValues));
    }
    
    /**
     * Link variant to parent and set attribute values
     */
    private function linkVariantToParent(int $parentId, int $childId, array $variant, array $superAttributes): void
    {
        // Create parent-child relationship
        DB::table('product_relations')->insert([
            'parent_id' => $parentId,
            'child_id' => $childId,
        ]);
        
        // Set variant-specific attribute values  
        $name = $variant['ItemName'] ?? '';
        $sku = $this->getProductSku($variant);
        
        // Safety check: ensure $superAttributes is an array
        if (!is_array($superAttributes)) {
            $this->line("  → Warning: Super attributes is not an array in linkVariantToParent, skipping variant linking");
            return;
        }
        
        foreach ($superAttributes as $attrCode => $attrData) {
            $value = $this->extractVariantAttributeValue($name . ' ' . $sku, $attrCode);
            if ($value) {
                // Ensure attribute exists first
                $attributeId = $this->ensureVariantAttribute($attrData);
                
                // Find or create attribute option using the attribute ID
                $optionId = $this->ensureAttributeOptionById($attributeId, $value);
                
                // Store variant attribute value on the child product
                DB::table('product_attribute_values')->insert([
                    'product_id' => $childId,
                    'attribute_id' => $attributeId,
                    'locale' => 'en',
                    'channel' => 'maddparts',
                    'text_value' => $value,
                    'unique_id' => 'maddparts|en|' . $childId . '|' . $attributeId,
                ]);
            }
        }
    }
    
    /**
     * Create super attributes for configurable product
     */
    private function createSuperAttributes(int $parentProductId, array $superAttributes): void
    {
        // Safety check: ensure $superAttributes is an array
        if (!is_array($superAttributes)) {
            $this->line("  → Warning: Super attributes is not an array in createSuperAttributes, skipping");
            return;
        }
        
        foreach ($superAttributes as $attrCode => $attrData) {
            $attributeId = $this->ensureVariantAttribute($attrData);
            
            // Create super attribute relationship using direct DB insertion
            // Check if relationship already exists
            $exists = DB::table('product_super_attributes')
                ->where('product_id', $parentProductId)
                ->where('attribute_id', $attributeId)
                ->exists();
                
            if (!$exists) {
                DB::table('product_super_attributes')->insert([
                    'product_id' => $parentProductId,
                    'attribute_id' => $attributeId,
                ]);
            }
        }
    }
    
    /**
     * Ensure variant attribute exists in Bagisto
     */
    private function ensureVariantAttribute($attrData): int
    {
        // Safety check: ensure $attrData is an array
        if (!is_array($attrData)) {
            $this->line("  → Error: Attribute data is not an array: " . gettype($attrData) . " - " . var_export($attrData, true));
            throw new Exception("Invalid attribute data: expected array, got " . gettype($attrData));
        }
        
        if (!isset($attrData['code'])) {
            $this->line("  → Error: Attribute data missing 'code': " . json_encode($attrData));
            throw new Exception("Attribute data must have 'code' field");
        }
        
        $code = $attrData['code'];
        
        if (isset($this->createdVariantAttributes[$code])) {
            return $this->createdVariantAttributes[$code];
        }
        
        // Check if attribute already exists
        $existingAttr = Attribute::where('code', $code)->first();
        if ($existingAttr) {
            $this->createdVariantAttributes[$code] = $existingAttr->id;
            return $existingAttr->id;
        }
        
        // Create new attribute
        $attribute = Attribute::create([
            'code' => $code,
            'admin_name' => $attrData['name'],
            'type' => 'select',
            'is_required' => false,
            'is_unique' => false,
            'validation' => '',
            'is_configurable' => true,
            'position' => 100,
        ]);
        
        // Create attribute translations
        DB::table('attribute_translations')->insert([
            'attribute_id' => $attribute->id,
            'locale' => 'en',
            'name' => $attrData['name'],
        ]);
        
        $this->createdVariantAttributes[$code] = $attribute->id;
        return $attribute->id;
    }
    
    /**
     * Ensure attribute option exists
     */
    private function ensureAttributeOption(string $attributeName, string $optionValue): int
    {
        // First try to find by admin_name, then by code (lowercase version)
        $attribute = Attribute::where('admin_name', $attributeName)->first();
        if (!$attribute) {
            $attributeCode = strtolower($attributeName);
            $attribute = Attribute::where('code', $attributeCode)->first();
        }
        
        if (!$attribute) {
            throw new Exception("Attribute {$attributeName} (code: {$attributeCode}) not found. Available attributes: " . 
                Attribute::pluck('code')->take(10)->implode(', '));
        }
        
        // Check if option exists
        $existingOption = AttributeOption::where('attribute_id', $attribute->id)
            ->whereHas('translations', function($query) use ($optionValue) {
                $query->where('label', $optionValue);
            })->first();
            
        if ($existingOption) {
            return $existingOption->id;
        }
        
        // Create new option
        $option = AttributeOption::create([
            'attribute_id' => $attribute->id,
            'admin_name' => $optionValue,
            'sort_order' => 0,
        ]);
        
        // Create option translation
        DB::table('attribute_option_translations')->insert([
            'attribute_option_id' => $option->id,
            'locale' => 'en',
            'label' => $optionValue,
        ]);
        
        return $option->id;
    }
    
    /**
     * Ensure attribute option exists by attribute ID
     */
    private function ensureAttributeOptionById(int $attributeId, string $optionValue): int
    {
        // Check if option exists
        $existingOption = AttributeOption::where('attribute_id', $attributeId)
            ->whereHas('translations', function($query) use ($optionValue) {
                $query->where('label', $optionValue);
            })->first();
            
        if ($existingOption) {
            return $existingOption->id;
        }
        
        // Create new option
        $option = AttributeOption::create([
            'attribute_id' => $attributeId,
            'admin_name' => $optionValue,
            'sort_order' => 0,
        ]);
        
        // Create option translation
        DB::table('attribute_option_translations')->insert([
            'attribute_option_id' => $option->id,
            'locale' => 'en',
            'label' => $optionValue,
        ]);
        
        return $option->id;
    }
    
    /**
     * Extract specific attribute value from product name/SKU
     */
    private function extractVariantAttributeValue(string $text, string $attributeType): ?string
    {
        // Debug: Show what we're trying to extract
        //$this->line("        Extracting {$attributeType} from: '{$text}'");
        
        switch ($attributeType) {
            case 'teeth':
                // Match patterns like "13T", "14T", "15 tooth", "16 teeth"
                if (preg_match('/\b(\d+)\s*(?:T|tooth|teeth)\b/i', $text, $matches)) {
                    return $matches[1] . 'T';
                }
                // Also try to match tooth count in product names
                if (preg_match('/(\d+)[-\s]*T(?:ooth)?/i', $text, $matches)) {
                    return $matches[1] . 'T';
                }
                break;
                
            case 'size':
                // Match various size patterns
                if (preg_match('/\b(\d+(?:\.\d+)?\s*(?:mm|cm|in|inch|"))\b/i', $text, $matches)) {
                    return trim($matches[1]);
                }
                // Match fractional sizes
                if (preg_match('/\b(\d+\/\d+\s*(?:in|inch|"))\b/i', $text, $matches)) {
                    return $matches[1];
                }
                // Match decimal sizes without units
                if (preg_match('/\b(\d+\.\d{1,3})\b/', $text, $matches)) {
                    return $matches[1];
                }
                break;
                
            case 'color':
                if (preg_match('/\b(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear|transparent)\b/i', $text, $matches)) {
                    return ucfirst(strtolower($matches[1]));
                }
                break;
                
            case 'material':
                if (preg_match('/\b(steel|aluminum|plastic|rubber|carbon|titanium|brass|copper|alloy)\b/i', $text, $matches)) {
                    return ucfirst(strtolower($matches[1]));
                }
                break;
                
            case 'quantity':
                if (preg_match('/\b(\d+)[-\s]*(?:pack|piece|set|pcs?)\b/i', $text, $matches)) {
                    return $matches[1] . '-pack';
                }
                break;
        }
        
        return null;
    }
    
    /**
     * Set variant-specific attribute values on child product
     */
    private function setVariantAttributeValues(int $childProductId, array $variant, array $superAttributes): void
    {
        // Safety check: ensure $superAttributes is an array
        if (!is_array($superAttributes)) {
            $this->line("  → Warning: Super attributes is not an array in setVariantAttributeValues");
            return;
        }
        
        $name = $variant['ItemName'] ?? '';
        $sku = $this->getProductSku($variant);
        $channel = 'maddparts';
        $locale = 'en';
        
        foreach ($superAttributes as $attrCode => $attrData) {
            $searchText = $name . ' ' . $sku;
            //$this->line("      Looking for {$attrCode} in '{$searchText}'");
            
            $value = $this->extractVariantAttributeValue($searchText, $attrCode);
            if ($value) {
                //$this->line("      Found {$attrCode} = '{$value}'");
                
                // Get attribute ID
                $attributeId = $this->ensureVariantAttribute($attrData);
                
                // Create unique ID for this attribute value
                $uniqueId = $channel . '|' . $locale . '|' . $childProductId . '|' . $attributeId;
                
                try {
                    // Insert/update the variant attribute value
                    ProductAttributeValue::updateOrCreate(
                        [
                            'product_id' => $childProductId,
                            'attribute_id' => $attributeId,
                            'locale' => $locale,
                            'channel' => $channel
                        ],
                        [
                            'unique_id' => $uniqueId,
                            'text_value' => $value,
                        ]
                    );
                    
                    $this->line("      ✓ Set {$attrCode} = '{$value}' on variant {$sku}");
                    
                } catch (Exception $e) {
                    $this->line("      ✗ Failed to set {$attrCode} on variant {$sku}: " . $e->getMessage());
                }
            } else {
                //$this->line("      No {$attrCode} found in '{$searchText}'");
            }
        }
    }
    
    /**
     * Add variant attributes to product
     */
    private function addVariantAttributes(int $productId, array $variantInfo): void
    {
        if (!$variantInfo['has_variants']) {
            return;
        }
        
        $channel = 'maddparts';
        $locale = 'en';
        
        // Save variant information as product attributes
        foreach ($variantInfo['variant_attributes'] as $index => $attrType) {
            $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . (200 + $index);
            
            try {
                ProductAttributeValue::updateOrCreate(
                    [
                        'product_id' => $productId,
                        'attribute_id' => 200 + $index,
                        'locale' => $locale,
                        'channel' => $channel
                    ],
                    [
                        'unique_id' => $uniqueId,
                        'text_value' => 'Has ' . ucfirst($attrType) . ' variants (' . $variantInfo['variant_count'] . ' total)',
                    ]
                );
            } catch (Exception $e) {
                Log::warning('Failed to add variant attributes', [
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
