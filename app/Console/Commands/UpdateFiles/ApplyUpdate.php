<?php

namespace App\Console\Commands\UpdateFiles;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class ApplyUpdate extends Command
{
    protected $signature = 'ari:apply-update 
                            {--path= : Path to update folder}
                            {--batch=5000 : Batch size for processing}
                            {--skip=0 : Skip first N products}
                            {--new-only : Only create new products}
                            {--update-only : Only update existing products}
                            {--dry-run : Show what would be done}';

    protected $description = 'Apply incremental update - create new products and update existing ones';

    private $basePath;
    private $kits = [];
    private $existingSkus = [];
    private $priceCache = [];
    private $manufacturerCache = [];

    public function handle()
    {
        $updateFolder = $this->option('path');
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = $this->option('dry-run');
        $newOnly = $this->option('new-only');
        $updateOnly = $this->option('update-only');

        if (!$updateFolder) {
            $this->error('Please provide --path option');
            return Command::FAILURE;
        }

        $this->basePath = '/var/www/html/test14/storage/app/datastream/extracted/' . $updateFolder;

        if (!File::exists($this->basePath)) {
            $this->error("Update folder not found: {$this->basePath}");
            return Command::FAILURE;
        }

        $this->info("Applying update from: {$updateFolder}");
        $this->info("Batch size: {$batch}, Skip: {$skip}");

        try {
            $this->loadKits();
            $this->loadManufacturers();
            $this->loadPriceCache();
            
            $existingSkusArray = DB::table('products')->pluck('id', 'sku')->toArray();
            $this->existingSkus = $existingSkusArray;
            $this->info("Loaded " . count($this->existingSkus) . " existing products");

            $result = $this->processPartmaster($batch, $skip, $dryRun, $newOnly, $updateOnly);

            $this->displayResults($result);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Update failed: ' . $e->getMessage());
            Log::error('Apply update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function processPartmaster(int $batch, int $skip, bool $dryRun, bool $newOnly, bool $updateOnly): array
    {
        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            throw new Exception('Partmaster.txt not found');
        }

        $this->info('Processing Partmaster.txt...');

        $newProducts = [];
        $updateProducts = [];
        $productsToCreate = [];
        $productsToUpdate = [];
        $skipped = 0;
        $processed = 0;
        $created = 0;
        $updated = 0;

        $kitsFlipped = array_flip($this->kits);
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
            
            $row = $this->normalizeRowKeys($row);
            if (!isset($row['ID'])) continue;

            foreach ($row as $key => $value) {
                $row[$key] = trim($value, '"');
            }

            $partId = $row['ID'];
            if (isset($kitsFlipped[$partId])) {
                continue;
            }

            $processed++;

            if ($processed <= $skip) {
                continue;
            }

            $sku = $this->getProductSku($row);

            if (isset($this->existingSkus[$sku])) {
                if ($newOnly) {
                    $skipped++;
                    continue;
                }
                
                $updateProducts[] = [
                    'product_id' => $this->existingSkus[$sku],
                    'sku' => $sku,
                    'data' => $row
                ];

                if (count($updateProducts) >= $batch) {
                    if (!$dryRun) {
                        $updated += $this->updateProductsBatch($updateProducts);
                    } else {
                        $updated += count($updateProducts);
                    }
                    $updateProducts = [];
                    gc_collect_cycles();
                }
            } else {
                if ($updateOnly) {
                    $skipped++;
                    continue;
                }

                $newProducts[] = $row;

                if (count($newProducts) >= $batch) {
                    if (!$dryRun) {
                        $created += $this->createProductsBatch($newProducts);
                        
                        $this->line("  Reloading existing SKUs to prevent duplicates...");
                        $existingSkusArray = DB::table('products')->pluck('id', 'sku')->toArray();
                        $this->existingSkus = $existingSkusArray;
                    } else {
                        $created += count($newProducts);
                    }
                    $newProducts = [];
                    gc_collect_cycles();
                }
            }

            if ($processed % 1000 === 0) {
                $this->line("  Processed: {$processed}, Created: {$created}, Updated: {$updated}, Skipped: {$skipped}");
            }

            if (($created + $updated) >= $batch && $skip === 0) {
                $this->warn("Batch limit reached. Run with --skip={$processed} to continue.");
                break;
            }
        }

        if (!$dryRun) {
            if (!empty($newProducts)) {
                $created += $this->createProductsBatch($newProducts);
            }
            if (!empty($updateProducts)) {
                $updated += $this->updateProductsBatch($updateProducts);
            }
        }

        return [
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped
        ];
    }

    private function createProductsBatch(array $products): int
    {
        $this->info("Creating batch of " . count($products) . " new products...");
        
        $uniqueProducts = [];
        $seenSkus = [];
        foreach ($products as $product) {
            $sku = $this->getProductSku($product);
            if (!isset($seenSkus[$sku])) {
                $uniqueProducts[] = $product;
                $seenSkus[$sku] = true;
            } else {
                $this->line("  Found duplicate within batch: {$sku}");
            }
        }
        
        if (count($uniqueProducts) < count($products)) {
            $this->warn("Removed " . (count($products) - count($uniqueProducts)) . " internal duplicates from batch");
            $products = $uniqueProducts;
        }
        
        $skusToCreate = array_map(fn($p) => $this->getProductSku($p), $products);
        $skusLower = array_map('strtolower', $skusToCreate);
        
        $existingInDb = DB::table('products')
            ->whereRaw('LOWER(sku) IN ("' . implode('","', array_map(fn($s) => addslashes($s), $skusLower)) . '")')
            ->pluck('sku')
            ->toArray();
        
        if (!empty($existingInDb)) {
            $this->warn("Found " . count($existingInDb) . " duplicate SKUs in batch, filtering...");
            $existingLower = array_map('strtolower', $existingInDb);
            $existingFlipped = array_flip($existingLower);
            $filtered = [];
            foreach ($products as $product) {
                $sku = $this->getProductSku($product);
                if (!isset($existingFlipped[strtolower($sku)])) {
                    $filtered[] = $product;
                } else {
                    $this->line("  Skipping duplicate: {$sku} (exists as case variant)");
                }
            }
            $products = $filtered;
            $this->info("Filtered to " . count($products) . " unique products");
        }
        
        if (empty($products)) {
            $this->warn("No products to create after filtering duplicates");
            return 0;
        }
        
        $bulkInserts = [
            'products' => [],
            'product_flat' => [],
            'product_inventories' => [],
            'product_attribute_values' => []
        ];

        foreach ($products as $product) {
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

            $price = $this->priceCache[$partId]['price'] ?? null;
            $quantity = $this->priceCache[$partId]['quantity'] ?? 0;

            $weight = !empty($product['weight']) ? (float) $product['weight'] : 1.0;
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
            $chunkSize = 1000;
            $totalInserted = 0;
            
            DB::transaction(function() use ($bulkInserts, $chunkSize, &$totalInserted) {
                if (!empty($bulkInserts['products'])) {
                    foreach (array_chunk($bulkInserts['products'], $chunkSize) as $chunk) {
                        DB::table('products')->insert($chunk);
                        $totalInserted += count($chunk);
                    }
                }
                if (!empty($bulkInserts['product_flat'])) {
                    foreach (array_chunk($bulkInserts['product_flat'], $chunkSize) as $chunk) {
                        DB::table('product_flat')->insert($chunk);
                    }
                }
                if (!empty($bulkInserts['product_inventories'])) {
                    foreach (array_chunk($bulkInserts['product_inventories'], $chunkSize) as $chunk) {
                        DB::table('product_inventories')->insert($chunk);
                    }
                }
            });

            $this->info("Created " . count($products) . " products successfully");
            return count($products);

        } catch (Exception $e) {
            $this->error("Failed to create batch: " . $e->getMessage());
            Log::error('Create batch failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function updateProductsBatch(array $products): int
    {
        $this->info("Updating batch of " . count($products) . " existing products...");
        
        $updated = 0;

        foreach ($products as $productData) {
            $productId = $productData['product_id'];
            $row = $productData['data'];
            $sku = $productData['sku'];

            $name = $row['ItemName'] ?? null;
            $description = $row['ItemDescription'] ?? null;
            $partId = $row['ID'] ?? null;
            $manufacturerId = $row['ManufacturerID'] ?? null;
            $weight = !empty($row['weight']) ? (float) $row['weight'] : null;

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

                if (isset($this->priceCache[$partId]['price'])) {
                    $flatUpdates['price'] = $this->priceCache[$partId]['price'];
                }

                DB::table('product_flat')
                    ->where('product_id', $productId)
                    ->update($flatUpdates);

                if (isset($this->priceCache[$partId]['quantity'])) {
                    DB::table('product_inventories')
                        ->where('product_id', $productId)
                        ->update(['qty' => $this->priceCache[$partId]['quantity']]);
                }

                $updated++;

            } catch (Exception $e) {
                $this->warn("Failed to update product {$sku}: " . $e->getMessage());
                Log::error('Update product failed', [
                    'sku' => $sku,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info("Updated {$updated} products successfully");
        return $updated;
    }

    private function loadPriceCache(): void
    {
        $file = $this->basePath . '/PartPriceInv.txt';
        if (!File::exists($file)) {
            $this->warn('PartPriceInv.txt not found, prices will not be updated');
            return;
        }

        $this->info('Loading price data...');

        $lines = File::lines($file);
        $header = null;
        $count = 0;

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

            $row = $this->normalizeRowKeys($row);
            
            $partId = $row['PartmasterID'] ?? null;
            if (!$partId) continue;

            $partId = trim($partId, '"');

            $price = (float) ($row['MSRP'] ?? $row['StandardPrice'] ?? $row['BestPrice'] ?? 0);
            $qty = (int) ($row['DistributorQty'] ?? 0);

            if (!isset($this->priceCache[$partId])) {
                $this->priceCache[$partId] = ['price' => 0, 'quantity' => 0];
            }

            if ($price > $this->priceCache[$partId]['price']) {
                $this->priceCache[$partId]['price'] = $price;
            }
            $this->priceCache[$partId]['quantity'] += $qty;

            $count++;
        }

        $this->info("Loaded " . count($this->priceCache) . " price records from {$count} lines");
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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if (!$row) continue;

            $row = $this->normalizeRowKeys($row);
            
            if (isset($row['ID'], $row['ManufacturerName'])) {
                $this->manufacturerCache[trim($row['ID'], '"')] = trim($row['ManufacturerName'], '"');
            }
        }

        $this->info("Loaded " . count($this->manufacturerCache) . " manufacturers");
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
            if (!$row) continue;
            
            $row = $this->normalizeRowKeys($row);
            if (isset($row['Primary_PartmasterID'])) {
                $this->kits[] = trim($row['Primary_PartmasterID'], '"');
            }
        }

        $this->info("Loaded " . count($this->kits) . " kit products");
    }

    private function displayResults(array $result): void
    {
        $this->line('');
        $this->info('=== UPDATE RESULTS ===');
        $this->table(['Metric', 'Count'], [
            ['Products Processed', number_format($result['processed'])],
            ['New Products Created', number_format($result['created'])],
            ['Existing Products Updated', number_format($result['updated'])],
            ['Products Skipped', number_format($result['skipped'])]
        ]);
    }

    private function generateTempProductId(): int
    {
        static $maxId = null;
        if ($maxId === null) {
            $maxId = DB::table('products')->max('id') ?? 0;
        }
        return ++$maxId;
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
            'primary_partmasterid' => 'Primary_PartmasterID',
            'partmasterid' => 'PartmasterID',
            'msrp' => 'MSRP',
            'standardprice' => 'StandardPrice',
            'bestprice' => 'BestPrice',
            'distributorqty' => 'DistributorQty',
            'manufacturername' => 'ManufacturerName'
        ];

        foreach ($row as $key => $value) {
            $lowerKey = strtolower($key);
            $normalizedKey = $keyMap[$lowerKey] ?? $key;
            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }
}
