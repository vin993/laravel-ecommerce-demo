<?php

namespace App\Console\Commands\UpdateFiles;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ProcessUpdateSimple extends Command
{
    protected $signature = 'ari:process-update-simple 
                            {--path= : Update folder path}
                            {--dry-run : Preview only}';
    
    protected $description = 'Process small update folder in one pass (create, update, attributes, categories, brands)';

    private $basePath;
    private $updateFolder;
    private $kits = [];
    private $existingSkus = [];
    private $partmasterData = [];
    private $priceCache = [];
    private $manufacturerCache = [];
    private $categoryIndexes = true;

    public function handle()
    {
        $this->updateFolder = $this->option('path');
        $dryRun = $this->option('dry-run');

        if (!$this->updateFolder) {
            $this->error('Provide --path option');
            return Command::FAILURE;
        }

        $this->basePath = '/var/www/html/test14/storage/app/datastream/extracted/' . $this->updateFolder;

        if (!File::exists($this->basePath)) {
            $this->error("Folder not found: {$this->basePath}");
            return Command::FAILURE;
        }

        $this->info("Processing: {$this->updateFolder}");
        $startTime = now();

        try {
            $this->loadKits();
            $this->loadPartmasterData();
            $this->loadPriceCache();
            $this->loadManufacturers();
            
            $existingSkusArray = DB::table('products')->pluck('id', 'sku')->toArray();
            $this->existingSkus = $existingSkusArray;
            $this->info('Loaded ' . count($this->existingSkus) . ' existing products');

            $this->categoryIndexes = $this->checkCategoryIndexes();

            $stats = [
                'new_created' => 0,
                'existing_updated' => 0,
                'attributes_synced' => 0,
                'categories_mapped' => 0,
                'brands_assigned' => 0
            ];

            if ($dryRun) {
                $this->info('DRY RUN - No changes will be made');
                $this->analyzeOnly();
                return Command::SUCCESS;
            }

            $this->info('Step 1: Creating new products & updating existing...');
            $result = $this->processProducts();
            $stats['new_created'] = $result['new_created'];
            $stats['existing_updated'] = $result['existing_updated'];

            if ($stats['new_created'] + $stats['existing_updated'] === 0) {
                $this->info('No products to process');
                return Command::SUCCESS;
            }

            $this->info('Step 2: Syncing attributes...');
            $stats['attributes_synced'] = $this->syncAttributes();

            $this->info('Step 3: Mapping categories...');
            if ($this->categoryIndexes) {
                $stats['categories_mapped'] = $this->mapCategories();
            } else {
                $this->warn('Category indexes missing - skipping');
            }

            $this->info('Step 4: Assigning brands...');
            $stats['brands_assigned'] = $this->assignBrands();

            $duration = now()->diffInMinutes($startTime);
            
            $this->displaySummary($stats, $duration);
            $this->saveProgress($stats);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
            Log::error('Process update simple failed', [
                'folder' => $this->updateFolder,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function processProducts(): array
    {
        $newProducts = [];
        $updateProducts = [];
        $newCreated = 0;
        $existingUpdated = 0;

        $kitsFlipped = array_flip($this->kits);

        foreach ($this->partmasterData as $partId => $row) {
            if (isset($kitsFlipped[$partId])) {
                continue;
            }

            $sku = $this->getProductSku($row);

            if (isset($this->existingSkus[$sku])) {
                $updateProducts[] = [
                    'product_id' => $this->existingSkus[$sku],
                    'sku' => $sku,
                    'data' => $row
                ];
            } else {
                $newProducts[] = $row;
            }

            if (count($newProducts) >= 1000) {
                $newCreated += $this->createProductsBatch($newProducts);
                
                $existingSkusArray = DB::table('products')->pluck('id', 'sku')->toArray();
                $this->existingSkus = $existingSkusArray;
                $newProducts = [];
                gc_collect_cycles();
            }

            if (count($updateProducts) >= 1000) {
                $existingUpdated += $this->updateProductsBatch($updateProducts);
                $updateProducts = [];
                gc_collect_cycles();
            }
        }

        if (!empty($newProducts)) {
            $newCreated += $this->createProductsBatch($newProducts);
        }
        if (!empty($updateProducts)) {
            $existingUpdated += $this->updateProductsBatch($updateProducts);
        }

        return [
            'new_created' => $newCreated,
            'existing_updated' => $existingUpdated
        ];
    }

    private function createProductsBatch(array $products): int
    {
        $this->line('  Creating ' . count($products) . ' products...');
        
        $uniqueProducts = $this->filterDuplicates($products);
        
        if (empty($uniqueProducts)) {
            return 0;
        }

        $bulkInserts = [
            'products' => [],
            'product_flat' => [],
            'product_inventories' => []
        ];

        foreach ($uniqueProducts as $product) {
            $sku = $this->getProductSku($product);
            $name = $product['ItemName'] ?? ('Product ' . $sku);
            $description = $product['ItemDescription'] ?? '';
            $partId = $product['ID'] ?? null;
            $manufacturerId = $product['ManufacturerID'] ?? null;

            $productId = $this->generateTempProductId();

            $bulkInserts['products'][] = [
                'id' => $productId,
                'sku' => $sku,
                'type' => 'simple',
                'attribute_family_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $price = $this->priceCache[$partId] ?? null;
            $quantity = 0;
            $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
            $urlKey = $this->uniqueUrlKey($name . '-' . $sku);

            $bulkInserts['product_flat'][] = [
                'product_id' => $productId,
                'sku' => $sku,
                'type' => 'simple',
                'name' => $name,
                'short_description' => mb_substr($description, 0, 200),
                'description' => $this->buildDescription($name, $description, $manufacturerId, $sku),
                'url_key' => $urlKey,
                'price' => $price,
                'weight' => $weight,
                'status' => 1,
                'visible_individually' => 1,
                'attribute_family_id' => 1,
                'channel' => 'maddparts',
                'locale' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $bulkInserts['product_inventories'][] = [
                'product_id' => $productId,
                'inventory_source_id' => 1,
                'vendor_id' => 0,
                'qty' => $quantity,
            ];
        }

        try {
            DB::transaction(function() use ($bulkInserts) {
                foreach (array_chunk($bulkInserts['products'], 1000) as $chunk) {
                    DB::table('products')->insert($chunk);
                }
                foreach (array_chunk($bulkInserts['product_flat'], 1000) as $chunk) {
                    DB::table('product_flat')->insert($chunk);
                }
                foreach (array_chunk($bulkInserts['product_inventories'], 1000) as $chunk) {
                    DB::table('product_inventories')->insert($chunk);
                }
            });

            return count($uniqueProducts);

        } catch (Exception $e) {
            $this->error('Create batch failed: ' . $e->getMessage());
            return 0;
        }
    }

    private function updateProductsBatch(array $products): int
    {
        $this->line('  Updating ' . count($products) . ' products...');
        
        $updated = 0;

        foreach ($products as $productData) {
            $productId = $productData['product_id'];
            $row = $productData['data'];
            $sku = $productData['sku'];

            $name = $row['ItemName'] ?? null;
            $description = $row['ItemDescription'] ?? null;
            $partId = $row['ID'] ?? null;
            $manufacturerId = $row['ManufacturerID'] ?? null;
            $weight = !empty($row['Weight']) ? (float) $row['Weight'] : null;

            try {
                DB::table('products')
                    ->where('id', $productId)
                    ->update(['updated_at' => now()]);

                $flatUpdates = ['updated_at' => now()];
                if ($name) $flatUpdates['name'] = $name;
                if ($description) {
                    $flatUpdates['short_description'] = mb_substr($description, 0, 200);
                    $flatUpdates['description'] = $this->buildDescription($name, $description, $manufacturerId, $sku);
                }
                if ($weight) $flatUpdates['weight'] = $weight;
                if (isset($this->priceCache[$partId])) {
                    $flatUpdates['price'] = $this->priceCache[$partId];
                }

                DB::table('product_flat')
                    ->where('product_id', $productId)
                    ->update($flatUpdates);

                $updated++;

            } catch (Exception $e) {
                $this->warn("Update failed {$sku}: " . $e->getMessage());
            }
        }

        return $updated;
    }

    private function syncAttributes(): int
    {
        $skusInUpdate = array_map(
            fn($data) => $this->getProductSku($data),
            $this->partmasterData
        );

        $productsWithoutAttrs = DB::table('products as p')
            ->leftJoin('product_attribute_values as pav', 'p.id', '=', 'pav.product_id')
            ->whereIn('p.sku', $skusInUpdate)
            ->whereNull('pav.id')
            ->select('p.id', 'p.sku')
            ->get();

        $synced = 0;

        foreach ($productsWithoutAttrs as $product) {
            $partId = $this->findPartIdBySku($product->sku);
            if (!$partId || !isset($this->partmasterData[$partId])) {
                continue;
            }

            $partData = $this->partmasterData[$partId];
            $name = $partData['ItemName'] ?? ('Product ' . $product->sku);
            $description = $partData['ItemDescription'] ?? '';
            $price = $this->priceCache[$partId] ?? null;

            try {
                $this->addAttributeValues($product->id, $name, $description, $price, $product->sku, $partData);
                $synced++;
            } catch (Exception $e) {
                continue;
            }
        }

        return $synced;
    }

    private function mapCategories(): int
    {
        $skusInUpdate = array_map(
            fn($data) => $this->getProductSku($data),
            $this->partmasterData
        );

        $products = DB::table('products as p')
            ->leftJoin('product_categories as pc', 'p.id', '=', 'pc.product_id')
            ->whereIn('p.sku', $skusInUpdate)
            ->whereNull('pc.product_id')
            ->select('p.id', 'p.sku')
            ->get();

        $mapped = 0;
        $bulkMappings = [];

        foreach ($products as $product) {
            $categoryIds = $this->getCategoryIdsForProduct($product->sku);

            if (!empty($categoryIds)) {
                foreach ($categoryIds as $categoryId) {
                    $bulkMappings[] = [
                        'product_id' => $product->id,
                        'category_id' => $categoryId,
                    ];
                }
                $mapped++;
            }

            if (count($bulkMappings) >= 1000) {
                $this->flushMappings($bulkMappings);
                $bulkMappings = [];
            }
        }

        if (!empty($bulkMappings)) {
            $this->flushMappings($bulkMappings);
        }

        return $mapped;
    }

    private function assignBrands(): int
    {
        $brandAttribute = DB::table('attributes')->where('code', 'brand')->first();
        if (!$brandAttribute) {
            return 0;
        }

        $brandOptions = DB::table('attribute_options')
            ->where('attribute_id', $brandAttribute->id)
            ->get()
            ->keyBy(function($item) {
                return strtolower($item->admin_name);
            });

        $skusInUpdate = array_map(
            fn($data) => $this->getProductSku($data),
            $this->partmasterData
        );

        $products = DB::table('products as p')
            ->leftJoin('product_attribute_values as pav', function($join) use ($brandAttribute) {
                $join->on('p.id', '=', 'pav.product_id')
                     ->where('pav.attribute_id', '=', $brandAttribute->id);
            })
            ->whereIn('p.sku', $skusInUpdate)
            ->whereNull('pav.id')
            ->select('p.id', 'p.sku')
            ->get();

        $assigned = 0;
        $bulkInserts = [];

        foreach ($products as $product) {
            $partId = $this->findPartIdBySku($product->sku);
            if (!$partId || !isset($this->partmasterData[$partId])) {
                continue;
            }

            $manufacturerId = $this->partmasterData[$partId]['ManufacturerID'] ?? null;
            if (!$manufacturerId || !isset($this->manufacturerCache[$manufacturerId])) {
                continue;
            }

            $manufacturerName = $this->manufacturerCache[$manufacturerId];
            $brandOption = $brandOptions->get(strtolower($manufacturerName));

            if (!$brandOption) {
                continue;
            }

            $uniqueId = 'default|en|' . $product->id . '|' . $brandAttribute->id;

            $bulkInserts[] = [
                'product_id' => $product->id,
                'attribute_id' => $brandAttribute->id,
                'locale' => 'en',
                'channel' => 'default',
                'text_value' => (string) $brandOption->id,
                'unique_id' => $uniqueId
            ];

            $assigned++;

            if (count($bulkInserts) >= 1000) {
                DB::table('product_attribute_values')->insert($bulkInserts);
                $bulkInserts = [];
            }
        }

        if (!empty($bulkInserts)) {
            DB::table('product_attribute_values')->insert($bulkInserts);
        }

        return $assigned;
    }

    private function loadKits(): void
    {
        $file = $this->basePath . '/Kits.txt';
        if (!File::exists($file) || filesize($file) === 0) {
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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['Primary_PartmasterID'])) {
                $this->kits[] = trim($row['Primary_PartmasterID'], '"');
            }
        }

        if (count($this->kits) > 0) {
            $this->info('Loaded ' . count($this->kits) . ' kit products');
        }
    }

    private function loadPartmasterData(): void
    {
        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            throw new Exception('Partmaster.txt not found');
        }

        $this->info('Loading Partmaster data...');
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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if (!$row) continue;
            
            foreach ($row as $key => $value) {
                $row[$key] = trim($value, '"');
            }
            
            $row = $this->normalizeRowKeys($row);
            
            if (isset($row['ID'])) {
                $this->partmasterData[$row['ID']] = $row;
            }
        }

        $this->info('Loaded ' . count($this->partmasterData) . ' products');
    }

    private function loadPriceCache(): void
    {
        $file = $this->basePath . '/PartPriceInv.txt';
        if (!File::exists($file)) {
            return;
        }

        $this->info('Loading price data...');
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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if (!$row) continue;

            $partId = trim($row['PartmasterID'] ?? '', '"');
            if (!$partId) continue;

            $price = (float) ($row['MSRP'] ?? $row['StandardPrice'] ?? $row['BestPrice'] ?? 0);

            if (!isset($this->priceCache[$partId]) || $price > $this->priceCache[$partId]) {
                $this->priceCache[$partId] = $price;
            }
        }

        $this->info('Loaded ' . count($this->priceCache) . ' prices');
    }

    private function loadManufacturers(): void
    {
        $file = $this->basePath . '/Manufacturer.txt';
        if (!File::exists($file)) {
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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['ID'], $row['ManufacturerName'])) {
                $this->manufacturerCache[trim($row['ID'], '"')] = trim($row['ManufacturerName'], '"');
            }
        }

        $this->info('Loaded ' . count($this->manufacturerCache) . ' manufacturers');
    }

    private function checkCategoryIndexes(): bool
    {
        try {
            return DB::table('ds_category_product_index')->count() > 0 &&
                   DB::table('ds_level_master_index')->count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getCategoryIdsForProduct(string $sku): array
    {
        $partmasterId = $this->getPartmasterIdFromSku($sku);
        if (!$partmasterId) {
            return [];
        }

        $levelMasterIds = DB::table('ds_category_product_index')
            ->where('partmaster_id', $partmasterId)
            ->pluck('level_master_id')
            ->toArray();

        if (empty($levelMasterIds)) {
            return [];
        }

        $categoryIds = [];
        foreach ($levelMasterIds as $levelMasterId) {
            $levelMaster = DB::table('ds_level_master_index')
                ->where('id', $levelMasterId)
                ->first();

            if ($levelMaster && $levelMaster->bagisto_category_id) {
                $categoryIds[] = $levelMaster->bagisto_category_id;
            }
        }

        return array_unique($categoryIds);
    }

    private function getPartmasterIdFromSku(string $sku): ?string
    {
        if (strpos($sku, 'ARI-') === 0) {
            return str_replace('ARI-', '', $sku);
        }

        $result = DB::table('ds_sku_partmaster_index')
            ->where('sku', $sku)
            ->value('partmaster_id');

        return $result;
    }

    private function flushMappings(array &$bulkMappings): void
    {
        if (empty($bulkMappings)) {
            return;
        }

        $uniqueMappings = [];
        foreach ($bulkMappings as $mapping) {
            $key = $mapping['product_id'] . '-' . $mapping['category_id'];
            $uniqueMappings[$key] = $mapping;
        }

        if (!empty($uniqueMappings)) {
            DB::table('product_categories')->insertOrIgnore(array_values($uniqueMappings));
        }

        $bulkMappings = [];
    }

    private function addAttributeValues(int $productId, string $name, string $description, ?float $price, string $sku, array $product): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $urlKey = $this->slugify($name . '-' . $sku);
        $manufacturerId = $product['ManufacturerID'] ?? null;
        $fullDescription = $this->buildDescription($name, $description, $manufacturerId, $sku);

        $attributes = [
            ['id' => 1, 'value' => $sku, 'type' => 'text'],
            ['id' => 2, 'value' => $name, 'type' => 'text'],
            ['id' => 3, 'value' => $urlKey, 'type' => 'text'],
            ['id' => 9, 'value' => mb_substr($description, 0, 200), 'type' => 'text'],
            ['id' => 10, 'value' => $fullDescription, 'type' => 'text'],
            ['id' => 8, 'value' => 1, 'type' => 'boolean'],
            ['id' => 7, 'value' => 1, 'type' => 'boolean'],
        ];

        if ($price) {
            $attributes[] = ['id' => 11, 'value' => $price, 'type' => 'float'];
        }

        $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
        $attributes[] = ['id' => 22, 'value' => $weight, 'type' => 'text'];

        foreach ($attributes as $attr) {
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
                $data['text_value'] = null;
                $data['float_value'] = null;
            } elseif ($type === 'float') {
                $data['float_value'] = (float) $attr['value'];
                $data['text_value'] = null;
                $data['boolean_value'] = null;
            } else {
                $data['text_value'] = (string) $attr['value'];
                $data['float_value'] = null;
                $data['boolean_value'] = null;
            }

            DB::table('product_attribute_values')->insert($data);
        }
    }

    private function filterDuplicates(array $products): array
    {
        $uniqueProducts = [];
        $seenSkus = [];
        
        foreach ($products as $product) {
            $sku = $this->getProductSku($product);
            $skuLower = strtolower($sku);
            
            if (!isset($seenSkus[$skuLower])) {
                $uniqueProducts[] = $product;
                $seenSkus[$skuLower] = true;
            }
        }

        $skusToCreate = array_map(fn($p) => $this->getProductSku($p), $uniqueProducts);
        $skusLower = array_map('strtolower', $skusToCreate);

        $existingInDb = DB::table('products')
            ->whereRaw('LOWER(sku) IN ("' . implode('","', array_map(fn($s) => addslashes($s), $skusLower)) . '")')
            ->pluck('sku')
            ->toArray();

        if (!empty($existingInDb)) {
            $existingLower = array_map('strtolower', $existingInDb);
            $existingFlipped = array_flip($existingLower);
            
            $filtered = [];
            foreach ($uniqueProducts as $product) {
                $sku = $this->getProductSku($product);
                if (!isset($existingFlipped[strtolower($sku)])) {
                    $filtered[] = $product;
                }
            }
            return $filtered;
        }

        return $uniqueProducts;
    }

    private function findPartIdBySku(string $sku): ?string
    {
        foreach ($this->partmasterData as $partId => $data) {
            $genSku = $this->getProductSku($data);
            if ($genSku === $sku) {
                return $partId;
            }
        }
        return null;
    }

    private function getProductSku(array $product): string
    {
        $long = $product['ManufacturerNumberLong'] ?? '';
        $short = $product['ManufacturerNumberShort'] ?? '';
        $id = $product['ID'] ?? '';

        return $long ?: ($short ?: "ARI-{$id}");
    }

    private function buildDescription(string $name, string $description, ?string $manufacturerId, string $sku): string
    {
        $html = '<h3>' . e($name) . '</h3>';

        if ($manufacturerId && isset($this->manufacturerCache[$manufacturerId])) {
            $html .= '<p><strong>Manufacturer:</strong> ' . e($this->manufacturerCache[$manufacturerId]) . '</p>';
        }

        $html .= '<p><strong>SKU:</strong> ' . e($sku) . '</p>';

        if ($description) {
            $html .= '<p>' . e($description) . '</p>';
        }

        $html .= '<p><em>Imported from ARI DataStream</em></p>';

        return $html;
    }

    private function generateTempProductId(): int
    {
        static $maxId = null;
        if ($maxId === null) {
            $maxId = DB::table('products')->max('id') ?? 0;
        }
        return ++$maxId;
    }

    private function uniqueUrlKey(string $base): string
    {
        static $usedKeys = [];

        $url = $this->slugify($base);
        $baseKey = $url;
        $n = 1;

        while (isset($usedKeys[$url])) {
            $url = $baseKey . '-' . $n;
            $n++;
        }

        $usedKeys[$url] = true;
        return $url;
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        return trim($text, '-');
    }

    private function analyzeOnly(): void
    {
        $kitsFlipped = array_flip($this->kits);
        $newCount = 0;
        $updateCount = 0;

        foreach ($this->partmasterData as $partId => $row) {
            if (isset($kitsFlipped[$partId])) {
                continue;
            }

            $sku = $this->getProductSku($row);

            if (isset($this->existingSkus[$sku])) {
                $updateCount++;
            } else {
                $newCount++;
            }
        }

        $this->table(['Metric', 'Count'], [
            ['New products', $newCount],
            ['Existing products', $updateCount],
            ['Total', $newCount + $updateCount]
        ]);
    }

    private function displaySummary(array $stats, int $duration): void
    {
        $this->line('');
        $this->info('=== COMPLETED: ' . $this->updateFolder . ' ===');
        $this->table(['Metric', 'Count'], [
            ['New Products Created', number_format($stats['new_created'])],
            ['Existing Products Updated', number_format($stats['existing_updated'])],
            ['Attributes Synced', number_format($stats['attributes_synced'])],
            ['Categories Mapped', number_format($stats['categories_mapped'])],
            ['Brands Assigned', number_format($stats['brands_assigned'])],
        ]);
        $this->info("Duration: {$duration} minutes");
    }

    private function saveProgress(array $stats): void
    {
        $progressFile = '/var/www/html/test14/storage/logs/update_progress.json';
        
        $progress = File::exists($progressFile) 
            ? json_decode(File::get($progressFile), true) 
            : [];

        $progress[$this->updateFolder] = [
            'status' => 'completed',
            'new_created' => $stats['new_created'],
            'existing_updated' => $stats['existing_updated'],
            'attributes_synced' => $stats['attributes_synced'],
            'categories_mapped' => $stats['categories_mapped'],
            'brands_assigned' => $stats['brands_assigned'],
            'completed_at' => now()->toDateTimeString()
        ];

        File::put($progressFile, json_encode($progress, JSON_PRETTY_PRINT));
    }

    private function normalizeRowKeys(array $row): array
    {
        $normalized = [];
        $keyMap = [
            'id' => 'ID',
            'manufacturernumberlong' => 'ManufacturerNumberLong',
            'manufacturernumbershort' => 'ManufacturerNumberShort',
            'itemname' => 'ItemName',
            'itemdescription' => 'ItemDescription',
            'manufacturerid' => 'ManufacturerID',
            'weight' => 'Weight'
        ];

        foreach ($row as $key => $value) {
            $lowerKey = strtolower($key);
            $normalizedKey = $keyMap[$lowerKey] ?? $key;
            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }
}
