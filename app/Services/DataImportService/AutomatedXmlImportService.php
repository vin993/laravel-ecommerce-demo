<?php

namespace App\Services\DataImportService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Exception;
use XMLReader;
use SimpleXMLElement;
use App\Services\DataStream\FtpService;
use Webkul\Category\Models\Category;

class AutomatedXmlImportService
{
    protected $command = null;
    protected $ftpService;

    protected $skuCache = [];
    protected $categoryCache = [];

    public function __construct(FtpService $ftpService)
    {
        $this->ftpService = $ftpService;
    }

    public function setCommand($command)
    {
        $this->command = $command;
    }

    public function importFromFtp(bool $syncImages = false, bool $dryRun = false, bool $onlySyncImages = false): array
    {
        $this->ftpService->connect();

        try {
            $files = $this->ftpService->listFiles('.');
            $targetFile = null;

            foreach ($files as $file) {
                $name = $file['name'] ?? $file['filename'] ?? '';
                if ($name === 'ItemsComplete.xml' || $name === 'ItemsIndex.xml') {
                    $targetFile = $file;
                    break;
                }
            }

            if (!$targetFile) {
                throw new Exception("ItemsComplete.xml or ItemsIndex.xml not found on FTP server.");
            }

            Log::info("[XmlImport] Found remote file: " . ($targetFile['name'] ?? 'Unknown'));

            $localPath = $this->ftpService->downloadFile($targetFile);

            return $this->importFromXml($localPath, $syncImages, $dryRun, $onlySyncImages);

        } finally {
            $this->ftpService->disconnect();
        }
    }

    public function importFromXml(string $path, bool $syncImages = false, bool $dryRun = false, bool $onlySyncImages = false): array
    {
        if (File::isDirectory($path)) {
            $files = File::files($path);
            foreach ($files as $file) {
                if ($file->getFilename() === 'ItemsComplete.xml') {
                    return $this->processCompleteFile($file->getPathname(), $syncImages, $dryRun, $onlySyncImages);
                }
                if ($file->getFilename() === 'ItemsIndex.xml') {
                    return $this->processCompleteFile($file->getPathname(), $syncImages, $dryRun, $onlySyncImages);
                }
            }
            throw new Exception("No valid XML import files (ItemsComplete.xml or ItemsIndex.xml) found in directory: $path");
        } elseif (File::isFile($path)) {
            return $this->processCompleteFile($path, $syncImages, $dryRun, $onlySyncImages);
        } else {
            throw new Exception("Path not found: $path");
        }
    }

    protected function processCompleteFile(string $filePath, bool $syncImages, bool $dryRun, bool $onlySyncImages = false): array
    {
        Log::info("[XmlImport] Starting import from: $filePath" . ($dryRun ? " [DRY RUN]" : ""));

        if ($this->command) {
            $this->command->info("Counting items in XML file...");
        }

        $totalItems = $this->countItemsInXml($filePath);
        
        if ($this->command) {
            $this->command->info("Found {$totalItems} items to process");
            $this->command->newLine();
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'processed_dry_run' => 0, 'total' => $totalItems];

        if (!$dryRun || $onlySyncImages) {
            $this->skuCache = DB::table('products')->pluck('id', 'sku')->filter()->toArray();

            if (empty($this->skuCache)) {
                Log::info("[XmlImport] SKU cache from 'products' is empty. Trying 'product_flat'...");
                $this->skuCache = DB::table('product_flat')->pluck('product_id', 'sku')->filter()->toArray();
            }

            $this->categoryCache = DB::table('category_translations')
                ->where('locale', 'en')
                ->pluck('category_id', 'slug')
                ->toArray();

            Log::info("[XmlImport] Caches loaded: " . count($this->skuCache) . " SKUs, " . count($this->categoryCache) . " Categories.");
        }

        $checkpointSku = $this->getCheckpoint();
        $skipping = !empty($checkpointSku);
        if ($skipping) {
            Log::info("[XmlImport] Resuming from checkpoint SKU: $checkpointSku");
        }

        $reader = new XMLReader();
        if (!$reader->open($filePath)) {
            throw new Exception("Could not open XML file: $filePath");
        }

        $progressBar = null;
        if ($this->command && $totalItems > 0) {
            $progressBar = $this->command->getOutput()->createProgressBar($totalItems);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - Created: %created% | Updated: %updated% | Skipped: %skipped%');
            $progressBar->setMessage('0', 'created');
            $progressBar->setMessage('0', 'updated');
            $progressBar->setMessage('0', 'skipped');
            $progressBar->start();
        }

        $processed = 0;

        while ($reader->read() && $reader->name !== 'Item')
            ;

        while ($reader->name === 'Item') {
            try {
                $node = new SimpleXMLElement($reader->readOuterXML());

                if ($skipping) {
                    $sku = $this->getSkuFromNode($node);
                    if ($sku === $checkpointSku) {
                        Log::info("[XmlImport] Found checkpoint SKU $sku. Resuming processing...");
                        $skipping = false;
                    }
                    $reader->next('Item');
                    continue;
                }

                $this->processItemNode($node, $syncImages, $stats, $dryRun, $onlySyncImages);
                $processed++;

                if ($progressBar) {
                    $progressBar->setMessage((string)$stats['created'], 'created');
                    $progressBar->setMessage((string)$stats['updated'], 'updated');
                    $progressBar->setMessage((string)$stats['skipped'], 'skipped');
                    $progressBar->advance();
                }

                $reader->next('Item');

            } catch (Exception $e) {
                Log::error("[XmlImport] Error reading XML node: " . $e->getMessage());
                $reader->next('Item');
            }
        }

        if ($progressBar) {
            $progressBar->finish();
            $this->command->newLine(2);
        }

        $reader->close();

        Log::info("[XmlImport] Completed. Stats: " . json_encode($stats));
        return $stats;
    }

    protected function countItemsInXml(string $filePath): int
    {
        $count = 0;
        $reader = new XMLReader();
        
        if (!$reader->open($filePath)) {
            return 0;
        }

        while ($reader->read()) {
            if ($reader->nodeType == XMLReader::ELEMENT && $reader->name === 'Item') {
                $count++;
            }
        }

        $reader->close();
        return $count;
    }

    protected function processItemNode(SimpleXMLElement $node, bool $syncImages, &$stats, bool $dryRun, bool $onlySyncImages = false): void
    {
        $sku = $this->getSkuFromNode($node);

        if (empty($sku)) {
            $stats['skipped']++;
            return;
        }

        if ($onlySyncImages) {
            $productId = $this->findProductId($sku);
            if ($productId && isset($node->Images)) {
                $this->syncImages($node->Images, $productId);
                $stats['updated']++;
            } else {
                $stats['skipped']++;
                if (!$productId) {
                    Log::debug("[XmlImport] Skipped SKU $sku: Not found in cache.");
                } elseif (!isset($node->Images)) {
                    Log::debug("[XmlImport] Skipped SKU $sku: Found in cache (ID: $productId), but no <Images> node.");
                }
            }
            return;
        }

        // Sync images for existing products BEFORE checking flag
        if ($syncImages && isset($node->Images)) {
            $productId = $this->findProductId($sku);
            if ($productId) {
                $this->syncImages($node->Images, $productId);
            }
        }

        $flag = (string) $node->RtlCusEcommItemFlag;
        if (in_array($flag, ['N', '1', '2', '4', '8'])) {
            $stats['skipped']++;
            return;
        }

        if ($dryRun) {
            $stats['processed_dry_run']++;
            return;
        }

        try {
            DB::beginTransaction();

            $productId = $this->findProductId($sku);
            $isNew = !$productId;

            $attributes = $this->mapAttributes($node);

            if ($isNew) {
                $productId = $this->createProduct($sku, $attributes);
                $this->skuCache[$sku] = $productId;
                $stats['created']++;
            } else {
                $this->updateProduct($productId, $sku, $attributes);
                $stats['updated']++;
            }

            if ($syncImages && isset($node->Images)) {
                $this->syncImages($node->Images, $productId);
            }

            if (isset($node->Categories)) {
                $this->syncCategories($node->Categories, $productId);
            }

            DB::commit();

            if (!$dryRun) {
                $this->updateCheckpoint($sku);
            }

        } catch (Exception $e) {
            DB::rollBack();
            $stats['failed']++;
            Log::error("[XmlImport] Failed processing Item $sku: " . $e->getMessage());
        }
    }

    protected function mapAttributes(SimpleXMLElement $node): array
    {
        return [
            'name' => html_entity_decode((string) $node->ItemDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'price' => (float) $node->MsrpPriceAmt,
            'weight' => $this->parseWeight((string) $node->ItemWeight),
            'description' => html_entity_decode((string) $node->ItemDescription, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'status' => ((string) $node->ItemRootStatus === 'C') ? 1 : 0,
            'visible_individually' => 1,
        ];
    }

    /**
     * Finds a product ID by SKU, with a fuzzy matching fallback for different prefixes.
     */
    protected function findProductId(string $sku): ?int
    {
        $id = $this->skuCache[$sku] ?? $this->skuCache[trim($sku)] ?? null;

        if (!$id) {
            // Try case-insensitive just in case
            foreach ($this->skuCache as $cacheSku => $cacheId) {
                if (strcasecmp((string) $cacheSku, $sku) === 0) {
                    $id = $cacheId;
                    break;
                }
            }
        }

        // Fallback to database if not in cache
        if (!$id) {
            $id = DB::table('products')->where('sku', $sku)->value('id');
            if ($id) {
                $this->skuCache[$sku] = $id; // Cache it for future lookups
            }
        }

        return $id;
    }

    /**
     * Extracts the SKU (ItemNumber) from the XML node.
     * Can be overridden for different XML structures (e.g., attributes).
     */
    protected function getSkuFromNode(SimpleXMLElement $node): string
    {
        return (string) $node->ItemNumber;
    }

    protected function parseWeight(string $weightStr): float
    {
        $weightStr = strtolower(trim($weightStr));
        $value = (float) filter_var($weightStr, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        if (str_contains($weightStr, 'kg')) {
            return $value * 1000;
        } elseif (str_contains($weightStr, 'lb')) {
            return $value * 453.592;
        } elseif (str_contains($weightStr, 'oz')) {
            return $value * 28.3495;
        }

        return $value;
    }

    protected function parseDimension(string $dimensionStr): float
    {
        $dimensionStr = strtolower(trim($dimensionStr));
        $value = (float) filter_var($dimensionStr, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

        if (str_contains($dimensionStr, 'cm')) {
            return $value;
        } elseif (str_contains($dimensionStr, 'in')) {
            return $value * 2.54;
        }

        return $value;
    }

    protected function createProduct(string $sku, array $attributes): int
    {
        $productId = DB::table('products')->insertGetId([
            'sku' => $sku,
            'type' => 'simple',
            'attribute_family_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->updateProductFlat($productId, $sku, $attributes);
        $this->updateProductAttributeValues($productId, $attributes);
        $this->updateInventory($productId, 100);

        return $productId;
    }

    protected function updateProduct(int $productId, string $sku, array $attributes): void
    {
        $this->updateProductFlat($productId, $sku, $attributes);
        $this->updateProductAttributeValues($productId, $attributes);
    }

    protected function updateProductFlat(int $productId, string $sku, array $attributes): void
    {
        DB::table('product_flat')->updateOrInsert(
            ['product_id' => $productId],
            [
                'sku' => $sku,
                'name' => $attributes['name'],
                'price' => $attributes['price'],
                'weight' => $attributes['weight'],
                'status' => $attributes['status'],
                'description' => $attributes['description'],
                'short_description' => Str::limit($attributes['description'], 150),
                'url_key' => Str::slug($attributes['name'] . '-' . $sku),
                'channel' => 'maddparts',
                'locale' => 'en',
                'visible_individually' => $attributes['visible_individually'],
                'attribute_family_id' => 1,
                'updated_at' => now(),
            ]
        );
    }

    protected function updateProductAttributeValues(int $productId, array $attributes): void
    {
        $attributeMapping = [
            'name' => ['id' => 2, 'type' => 'text'],
            'url_key' => ['id' => 3, 'type' => 'text'],
            'status' => ['id' => 8, 'type' => 'boolean'],
            'short_description' => ['id' => 9, 'type' => 'text'],
            'description' => ['id' => 10, 'type' => 'text'],
            'price' => ['id' => 11, 'type' => 'float'],
            'length' => ['id' => 19, 'type' => 'float'],
            'width' => ['id' => 20, 'type' => 'float'],
            'height' => ['id' => 21, 'type' => 'float'],
            'weight' => ['id' => 22, 'type' => 'float'],
            'brand' => ['id' => 25, 'type' => 'text'],
        ];

        foreach ($attributeMapping as $key => $config) {
            $value = $attributes[$key] ?? null;
            
            if ($value === null) {
                continue;
            }

            $data = [
                'product_id' => $productId,
                'attribute_id' => $config['id'],
                'channel' => 'maddparts',
                'locale' => 'en',
            ];

            switch ($config['type']) {
                case 'text':
                    $data['text_value'] = $value;
                    break;
                case 'float':
                    $data['float_value'] = (float) $value;
                    break;
                case 'boolean':
                    $data['boolean_value'] = (bool) $value;
                    break;
            }

            DB::table('product_attribute_values')->updateOrInsert(
                [
                    'product_id' => $productId,
                    'attribute_id' => $config['id'],
                    'channel' => 'maddparts',
                    'locale' => 'en',
                ],
                $data
            );
        }
    }


    protected function updateInventory(int $productId, int $qty): void
    {
        $exists = DB::table('product_inventories')
            ->where('product_id', $productId)
            ->where('inventory_source_id', 1)
            ->exists();

        if ($exists) {
            DB::table('product_inventories')
                ->where('product_id', $productId)
                ->where('inventory_source_id', 1)
                ->update(['qty' => $qty]);
        } else {
            DB::table('product_inventories')->insert([
                'product_id' => $productId,
                'inventory_source_id' => 1,
                'qty' => $qty,
                'vendor_id' => 0,
            ]);
        }
    }

    protected function syncImages(SimpleXMLElement $imagesNode, int $productId): void
    {
        $position = 0;
        foreach ($imagesNode->Image as $image) {
            $path = (string) ($image['src'] ?? $image['Path'] ?? $image['Url'] ?? $image['URL'] ?? '');

            if (empty($path))
                continue;

            $exists = DB::table('product_images')
                ->where('product_id', $productId)
                ->where('path', $path)
                ->exists();

            if (!$exists) {
                DB::table('product_images')->insert([
                    'product_id' => $productId,
                    'path' => $path,
                    'type' => 'images',
                    'position' => $position++,
                ]);
            }
        }
    }

    protected function syncCategories(SimpleXMLElement $categoriesNode, int $productId): void
    {
        foreach ($categoriesNode->Category as $categoryXml) {
            $categoryName = trim((string) $categoryXml);
            if (empty($categoryName))
                continue;

            // Apply category consolidation to prevent creating redundant categories
            if (class_exists('\App\Console\Commands\ConsolidateKawasakiCategories')) {
                $categoryName = \App\Console\Commands\ConsolidateKawasakiCategories::getConsolidatedName($categoryName);
            }

            $slug = Str::slug($categoryName);

            $categoryId = $this->categoryCache[$slug] ?? null;

            if (!$categoryId) {
                $category = Category::whereHas('translations', function ($q) use ($slug) {
                    $q->where('slug', $slug)->where('locale', 'en');
                })->first();

                if ($category) {
                    $categoryId = $category->id;
                } else {
                    try {
                        $category = Category::create([
                            'position' => 0,
                            'status' => 1,
                            'parent_id' => 1,
                            'display_mode' => 'products_and_description',
                            'en' => [
                                'name' => $categoryName,
                                'slug' => $slug,
                                'description' => $categoryName,
                                'meta_title' => $categoryName,
                                'meta_description' => $categoryName,
                                'meta_keywords' => $categoryName,
                            ]
                        ]);
                        $categoryId = $category->id;
                    } catch (Exception $e) {
                        Log::error("[XmlImport] Failed to create category $categoryName: " . $e->getMessage());
                        continue;
                    }
                }

                $this->categoryCache[$slug] = $categoryId;
            }

            DB::table('product_categories')->updateOrInsert(
                ['product_id' => $productId, 'category_id' => $categoryId]
            );
        }
    }

    protected function getCheckpoint(): ?string
    {
        if (Storage::exists('xml_import_checkpoint.txt')) {
            return trim(Storage::get('xml_import_checkpoint.txt'));
        }
        return null;
    }

    protected function updateCheckpoint(string $sku): void
    {
        Storage::put('xml_import_checkpoint.txt', $sku);
    }

    public function clearCheckpoint(): void
    {
        if (Storage::exists('xml_import_checkpoint.txt')) {
            Storage::delete('xml_import_checkpoint.txt');
            Log::info("[XmlImport] Checkpoint cleared.");
        }
    }
}
