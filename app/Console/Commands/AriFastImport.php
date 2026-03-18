<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductAttributeValue;

class AriFastImport extends Command
{
    protected $signature = 'ari:fast-import {--batch=10000} {--skip=0} {--dry-run}';
    protected $description = 'Fast import from DataStream using indexed lookups - NO CATEGORY SYNC';

    private $basePath;
    private $kits = [];
    private $manufacturers = [];
    private $useIndexes = false;

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('ARI Fast Import Started (categories synced separately)');

        $this->basePath = $this->detectLatestExtractedPath();
        if (!$this->basePath) {
            $this->error('No extracted data folders found');
            return Command::FAILURE;
        }

        $this->info("Reading from: {$this->basePath}");
        $this->info("Batch size: {$batch}" . ($skip > 0 ? ", skipping first {$skip}" : ''));

        $this->useIndexes = $this->checkIndexTables();
        if ($this->useIndexes) {
            $this->info('Using indexed lookups (fast mode)');
        } else {
            $this->warn('Index tables not found. Run: php artisan datastream:build-indexes');
            $this->warn('Falling back to file streaming (slower)');
        }

        if (!File::exists($this->basePath)) {
            $this->error("Path not found: {$this->basePath}");
            return Command::FAILURE;
        }

        try {
            $this->loadKits();

            $this->info('Loading batch from Partmaster...');
            $batchProducts = $this->loadPartmasterBatch($skip, $batch);

            if (empty($batchProducts)) {
                $this->warn('No products found in batch. Either end of file or all filtered out.');
                return Command::SUCCESS;
            }

            $this->info("Processing batch: " . count($batchProducts) . " products");

            if ($dryRun) {
                $this->info('DRY-RUN MODE');
                $this->displayDryRunSample($batchProducts);
                return Command::SUCCESS;
            }

            $processed = 0;
            $created = 0;
            $skipped = 0;

            $bulkInserts = [
                'products' => [],
                'product_attribute_values' => [],
                'product_flat' => [],
                'product_inventories' => [],
            ];

            $existingSkus = $this->getExistingSkus($batchProducts);
            $processedSkus = [];

            $startLoopTime = microtime(true);
            foreach ($batchProducts as $product) {
                $processed++;

                $sku = $this->getProductSku($product);
                $name = $product['ItemName'] ?? ('Product ' . $sku);

                if (isset($existingSkus[$sku]) || isset($processedSkus[$sku])) {
                    $skipped++;
                    if ($processed % 100 === 0) {
                        $elapsed = round(microtime(true) - $startLoopTime, 2);
                        $avgTime = $processed > 0 ? round($elapsed / $processed, 3) : 0;
                        $this->line("Progress: {$processed}/" . count($batchProducts) . " (created: {$created}, skipped: {$skipped}) [{$elapsed}s, {$avgTime}s/product]");
                    }
                    continue;
                }

                try {
                    $this->prepareProductBulkData($product, $bulkInserts);

                    $processedSkus[$sku] = true;
                    $created++;

                    if ($created % 100 === 0) {
                        $elapsed = round(microtime(true) - $startLoopTime, 2);
                        $avgTime = $processed > 0 ? round($elapsed / $processed, 3) : 0;
                        $this->line("Progress: {$processed}/" . count($batchProducts) . " (created: {$created}, skipped: {$skipped}) [{$elapsed}s, {$avgTime}s/product]");
                    }

                    if ($created % 100 === 0) {
                        $this->line("  Flushing 100 products to database...");
                        $flushStart = microtime(true);
                        try {
                            $this->flushBulkInserts($bulkInserts);
                            $flushTime = round(microtime(true) - $flushStart, 2);
                            $this->info("  Flushed 100 products successfully (took {$flushTime}s)");
                        } catch (Exception $e) {
                            $this->error("  Flush failed: " . $e->getMessage());
                            throw $e;
                        }
                        gc_collect_cycles();
                    }

                } catch (Exception $e) {
                    $this->error("Failed: {$name} [SKU: {$sku}] - " . $e->getMessage());
                    Log::error('ARI fast import product failure', [
                        'sku' => $sku,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (!empty($bulkInserts['products'])) {
                $this->flushBulkInserts($bulkInserts);
            }

            $this->info('Fast import complete!');
            $this->table(['Metric', 'Count'], [
                ['Processed', $processed],
                ['Created', $created],
                ['Skipped (existing)', $skipped],
            ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Fatal: ' . $e->getMessage());
            Log::error('ARI fast import fatal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function checkIndexTables(): bool
    {
        try {
            return DB::table('ds_price_index')->count() > 0 &&
                   DB::table('ds_manufacturer_index')->count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getExistingSkus(array $products): array
    {
        $skus = array_map(fn($p) => $this->getProductSku($p), $products);

        $this->info('Checking for existing products (' . count($skus) . ' SKUs)...');

        // Use larger chunks to reduce number of queries
        // For 100k batch: 10k chunks = 10 queries instead of 100 queries
        $chunkSize = 10000;
        $existing = [];
        $chunkNum = 0;
        $totalChunks = ceil(count($skus) / $chunkSize);

        $startTime = microtime(true);
        foreach (array_chunk($skus, $chunkSize) as $skuChunk) {
            $chunkNum++;
            $chunkStart = microtime(true);
            $this->line("  Checking chunk {$chunkNum}/{$totalChunks} (" . count($skuChunk) . " SKUs)...");
            $found = DB::table('products')->whereIn('sku', $skuChunk)->pluck('sku')->toArray();
            $existing = array_merge($existing, $found);
            $elapsed = round(microtime(true) - $chunkStart, 2);
            $this->line("    Found " . count($found) . " existing (took {$elapsed}s)");
        }

        $totalTime = round(microtime(true) - $startTime, 2);
        $this->info("Found " . count($existing) . " existing products to skip (took {$totalTime}s total)");

        return array_flip($existing);
    }

    private function prepareProductBulkData(array $product, array &$bulkInserts): void
    {
        static $slowQueryCount = 0;
        static $totalPriceTime = 0;
        static $callCount = 0;

        $callCount++;

        $sku = $this->getProductSku($product);
        $name = $product['ItemName'] ?? ('Product ' . $sku);
        $description = $product['ItemDescription'] ?? '';
        $manufacturerId = $product['ManufacturerID'] ?? null;
        $partId = $product['ID'] ?? null;

        $productId = $this->generateTempProductId();

        $bulkInserts['products'][] = [
            'id' => $productId,
            'sku' => $sku,
            'type' => 'simple',
            'attribute_family_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // Track price lookup time
        $priceStart = microtime(true);
        $inventoryData = $this->getProductInventoryData($partId);
        $priceTime = microtime(true) - $priceStart;
        $totalPriceTime += $priceTime;

        if ($priceTime > 1.0) {
            $slowQueryCount++;
            $this->warn("    ⚠ Slow price lookup for {$sku}: " . round($priceTime, 2) . "s");
        }

        $price = $inventoryData['price'] ?? null;
        $quantity = $inventoryData['quantity'] ?? 0;

        $this->addAttributeValues($productId, $name, $description, $price, $product, $bulkInserts);

        $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
        $urlKey = $this->uniqueUrlKey($name . '-' . $sku);

        $bulkInserts['product_flat'][] = [
            'product_id' => $productId,
            'sku' => $sku,
            'type' => 'simple',
            'name' => $name,
            'short_description' => mb_substr($description, 0, 200),
            'description' => $this->buildSimpleDescription($name, $description, $manufacturerId, $sku),
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

        // Images skipped - will be added separately later

        // Show performance stats every 100 products
        if ($callCount % 100 === 0) {
            $avgPrice = round($totalPriceTime / $callCount, 3);
            $this->line("    Stats: Avg price lookup={$avgPrice}s, Slow queries={$slowQueryCount}");
        }
    }

    private function flushBulkInserts(array &$bulkInserts): void
    {
        if (empty($bulkInserts['products'])) {
            return;
        }

        $count = count($bulkInserts['products']);

        try {
            if (!empty($bulkInserts['products'])) {
                $start = microtime(true);
                DB::table('products')->insert($bulkInserts['products']);
                $this->line("    ├─ products: " . round(microtime(true) - $start, 2) . "s");
            }

            // Skip attributes for now - they cause unique constraint checking slowdown
            // Can be added later with a separate command

            if (!empty($bulkInserts['product_flat'])) {
                $start = microtime(true);
                DB::table('product_flat')->insert($bulkInserts['product_flat']);
                $this->line("    ├─ product_flat: " . round(microtime(true) - $start, 2) . "s");
            }

            if (!empty($bulkInserts['product_inventories'])) {
                $start = microtime(true);
                DB::table('product_inventories')->insert($bulkInserts['product_inventories']);
                $this->line("    └─ inventories: " . round(microtime(true) - $start, 2) . "s");
            }
        } catch (Exception $e) {
            Log::error("Flush failed for {$count} products", ['error' => $e->getMessage()]);
            $this->error("    Error: " . $e->getMessage());
            throw $e;
        }

        $bulkInserts['products'] = [];
        $bulkInserts['product_attribute_values'] = [];
        $bulkInserts['product_flat'] = [];
        $bulkInserts['product_inventories'] = [];
    }

    private function loadPartmasterBatch(int $skip, int $batchSize): array
    {
        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            return [];
        }

        $kitsFlipped = array_flip($this->kits);
        $products = [];
        $lines = File::lines($file);
        $header = null;
        $productCount = 0;
        $lineNumber = 0;

        foreach ($lines as $line) {
            $lineNumber++;

            // Show progress every 50k lines while reading
            if ($lineNumber % 50000 === 0) {
                $status = $productCount <= $skip ? "Skipping..." : "Loading batch...";
                $this->line("  Reading line {$lineNumber}, valid products: {$productCount} [{$status}]");
            }

            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if (!$row || !isset($row['ID'])) continue;

            foreach ($row as $key => $value) {
                $row[$key] = trim($value, '"');
            }

            $partId = $row['ID'];
            if (isset($kitsFlipped[$partId])) {
                continue;
            }

            $productCount++;

            if ($productCount <= $skip) {
                continue;
            }

            $products[] = $row;

            if (count($products) >= $batchSize) {
                $this->info("Loaded {$batchSize} products from Partmaster");
                break;
            }
        }

        return $products;
    }

    private function addAttributeValues(int $productId, string $name, string $description, ?float $price, array $product, array &$bulkInserts): void
    {
        $sku = $this->getProductSku($product);
        $urlKey = $this->uniqueUrlKey($name . '-' . $sku);
        $fullDescription = $this->buildSimpleDescription($name, $description, $product['ManufacturerID'] ?? null, $sku);

        $channel = 'maddparts';
        $locale = 'en';

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

            $bulkInserts['product_attribute_values'][] = $data;
        }
    }

    private function generateTempProductId(): int
    {
        static $maxId = null;
        if ($maxId === null) {
            $maxId = DB::table('products')->max('id') ?? 0;
        }
        return ++$maxId;
    }

    private function getProductInventoryData(?string $partId): array
    {
        if (!$partId) {
            return ['price' => null, 'quantity' => 0];
        }

        if ($this->useIndexes) {
            $result = DB::table('ds_price_index')
                ->where('partmaster_id', $partId)
                ->first();

            if ($result) {
                $price = $result->msrp ?? $result->standard_price ?? $result->best_price;
                return [
                    'price' => $price > 0 ? $price : null,
                    'quantity' => $result->quantity ?? 0,
                ];
            }
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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID']) && trim($row['PartmasterID'], '"') === $partId) {
                $price = (float) ($row['MSRP'] ?? $row['StandardPrice'] ?? $row['BestPrice'] ?? 0);
                if ($price > $bestPrice) {
                    $bestPrice = $price;
                }
                $qty = (int) ($row['DistributorQty'] ?? 0);
                $totalQuantity += $qty;
            }
        }

        return [
            'price' => $bestPrice > 0 ? $bestPrice : null,
            'quantity' => $totalQuantity
        ];
    }

    private function getProductImages(?string $partId): array
    {
        if (!$partId) {
            return [];
        }

        if ($this->useIndexes) {
            return DB::table('ds_image_index')
                ->where('partmaster_id', $partId)
                ->orderBy('position')
                ->limit(5)
                ->pluck('image_url')
                ->toArray();
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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID']) && trim($row['PartmasterID'], '"') === $partId) {
                $imagePath = trim($row['ImagePath'] ?? $row['Path'] ?? '', '"');
                $imageUrl = trim($row['ImageURL'] ?? $row['URL'] ?? '', '"');

                if ($imageUrl || $imagePath) {
                    $images[] = $imageUrl ?: $imagePath;
                }

                if (count($images) >= 5) {
                    break;
                }
            }
        }

        return $images;
    }

    private function loadKits(): void
    {
        if ($this->useIndexes) {
            $this->kits = DB::table('ds_kit_index')->pluck('primary_partmaster_id')->toArray();
            $this->info("Loaded " . count($this->kits) . " parent kit products from index");
            return;
        }

        $file = $this->basePath . '/Kits.txt';
        if (!File::exists($file)) {
            $this->warn('Kits.txt not found');
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
            $primaryKitId = null;
            if ($row && isset($row['Primary_PartmasterID'])) {
                $primaryKitId = trim($row['Primary_PartmasterID'], '"');
            }

            if ($primaryKitId) {
                $this->kits[] = $primaryKitId;
            }
        }

        $this->info("Loaded " . count($this->kits) . " parent kit products");
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
            if ($row && isset($row['id'], $row['ManufacturerName'])) {
                $this->manufacturers[trim($row['id'], '"')] = trim($row['ManufacturerName'], '"');
            }
        }
    }

    private function getProductSku(array $product): string
    {
        $long = $product['ManufacturerNumberLong'] ?? '';
        $short = $product['ManufacturerNumberShort'] ?? '';
        $id = $product['ID'] ?? '';

        return $long ?: ($short ?: "ARI-{$id}");
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

    private function displayDryRunSample(array $products): void
    {
        $sample = array_slice($products, 0, 5);
        $this->info('Sample products that would be imported:');

        foreach ($sample as $product) {
            $sku = $this->getProductSku($product);
            $name = $product['ItemName'] ?? 'N/A';
            $partId = $product['ID'] ?? null;
            $inventoryData = $this->getProductInventoryData($partId);

            $this->line("SKU: {$sku}");
            $this->line("  Name: {$name}");
            $this->line("  Price: " . ($inventoryData['price'] ? '$' . $inventoryData['price'] : 'N/A'));
            $this->line("  Quantity: {$inventoryData['quantity']}");
            $this->line("");
        }
    }

    private function detectLatestExtractedPath(): ?string
    {
        $baseExtractedPath = '/var/www/html/test14/storage/app/datastream/extracted';

        $fullPath = $baseExtractedPath . '/JonesboroCycleFull';
        if (File::exists($fullPath . '/Partmaster.txt')) {
            return $fullPath;
        }

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
}
