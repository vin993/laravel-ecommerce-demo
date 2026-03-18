<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class AriFastImportWithVariants extends Command
{
    protected $signature = 'ari:fast-import-variants {--batch=10000} {--skip=0} {--dry-run}';
    protected $description = 'Fast import with variant support using pre-built variant groups';

    private $basePath;
    private $kits = [];
    private $manufacturers = [];
    private $useIndexes = false;
    private $variantGroupsEnabled = false;

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('ARI Fast Import with Variants Started');

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
            $this->warn('Index tables not found');
            return Command::FAILURE;
        }

        $this->variantGroupsEnabled = $this->checkVariantGroups();
        if ($this->variantGroupsEnabled) {
            $this->info('Variant groups found - will create configurable products');
        } else {
            $this->warn('No variant groups found. Run: php artisan datastream:build-variant-groups');
            $this->warn('Proceeding without variants (simple products only)');
        }

        if (!File::exists($this->basePath)) {
            $this->error("Path not found: {$this->basePath}");
            return Command::FAILURE;
        }

        try {
            $this->loadKits();

            $this->info('Loading Partmaster products...');
            $products = $this->loadPartmaster();

            if (empty($products)) {
                $this->error('No products found in Partmaster.txt');
                return Command::FAILURE;
            }

            $totalBeforeFilter = count($products);
            $primaryProducts = $this->filterPrimaryProducts($products);
            $total = count($primaryProducts);
            $filteredCount = $totalBeforeFilter - $total;

            $this->info("Original products: {$totalBeforeFilter}");
            $this->info("Parent kit products filtered: {$filteredCount}");
            $this->info("Individual products remaining: {$total}");

            if ($skip >= $total) {
                $this->error("Skip offset {$skip} >= total {$total}. Nothing to process.");
                return Command::FAILURE;
            }

            $batchProducts = array_slice($primaryProducts, $skip, $batch);
            $this->info("Processing batch: " . count($batchProducts) . " products");

            if ($this->variantGroupsEnabled) {
                $processableItems = $this->groupProductsWithVariants($batchProducts);
                $this->info("After variant grouping: " . count($processableItems) . " items (products + groups)");
            } else {
                $processableItems = $batchProducts;
            }

            if ($dryRun) {
                $this->info('DRY-RUN MODE');
                $this->displayDryRunSample($processableItems);
                return Command::SUCCESS;
            }

            $processed = 0;
            $created = 0;
            $skipped = 0;

            foreach ($processableItems as $item) {
                $processed++;

                try {
                    if (isset($item['is_variant_group']) && $item['is_variant_group']) {
                        $result = $this->processVariantGroup($item);
                        if ($result === 'created') {
                            $created++;
                        } elseif ($result === 'skipped') {
                            $skipped++;
                        }
                    } else {
                        $sku = $this->getProductSku($item);
                        if (DB::table('products')->where('sku', $sku)->exists()) {
                            $skipped++;
                            continue;
                        }

                        $this->createSimpleProduct($item);
                        $created++;
                    }

                    if ($processed % 100 === 0) {
                        $this->line("Progress: {$processed}/" . count($processableItems) . " (created: {$created}, skipped: {$skipped})");
                        gc_collect_cycles();
                    }

                } catch (Exception $e) {
                    $this->error("Failed: " . $e->getMessage());
                    Log::error('ARI fast import failure', ['error' => $e->getMessage()]);
                }
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
            Log::error('ARI fast import fatal', ['error' => $e->getMessage()]);
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

    private function checkVariantGroups(): bool
    {
        try {
            return DB::table('ds_variant_groups')->count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function groupProductsWithVariants(array $products): array
    {
        $this->info('Grouping products by variant groups...');

        $partIds = array_column($products, 'ID');
        $variantData = DB::table('ds_variant_groups')
            ->whereIn('partmaster_id', $partIds)
            ->get()
            ->groupBy('variant_group_id');

        $grouped = [];
        $processedIds = [];

        foreach ($variantData as $groupId => $variants) {
            if (count($variants) > 1) {
                $variantProducts = [];
                foreach ($variants as $variant) {
                    $product = $this->findProductById($products, $variant->partmaster_id);
                    if ($product) {
                        $variantProducts[] = array_merge($product, [
                            'variant_type' => $variant->variant_type,
                            'variant_value' => $variant->variant_value,
                        ]);
                        $processedIds[] = $variant->partmaster_id;
                    }
                }

                if (count($variantProducts) > 1) {
                    $grouped[] = [
                        'is_variant_group' => true,
                        'group_id' => $groupId,
                        'base_name' => $variants->first()->base_name,
                        'base_sku' => $variants->first()->base_sku,
                        'variant_type' => $variants->first()->variant_type,
                        'variants' => $variantProducts,
                    ];
                }
            }
        }

        foreach ($products as $product) {
            if (!in_array($product['ID'], $processedIds)) {
                $grouped[] = $product;
            }
        }

        return $grouped;
    }

    private function findProductById(array $products, string $id): ?array
    {
        foreach ($products as $product) {
            if (($product['ID'] ?? '') === $id) {
                return $product;
            }
        }
        return null;
    }

    private function processVariantGroup(array $group): string
    {
        $parentSku = $group['base_sku'] . '-PARENT';

        if (DB::table('products')->where('sku', $parentSku)->exists()) {
            return 'skipped';
        }

        DB::transaction(function () use ($group, $parentSku) {
            $parentProductId = DB::table('products')->insertGetId([
                'sku' => $parentSku,
                'type' => 'configurable',
                'attribute_family_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->addParentAttributeValues($parentProductId, $group);

            $firstVariant = $group['variants'][0];
            $categoryId = $this->getCategoryId($firstVariant['ManufacturerID'] ?? null);
            if ($categoryId) {
                DB::table('product_categories')->insert([
                    'product_id' => $parentProductId,
                    'category_id' => $categoryId,
                ]);
            }

            $variantAttributeId = $this->getOrCreateVariantAttribute($group['variant_type']);

            foreach ($group['variants'] as $variant) {
                $variantSku = $this->getProductSku($variant);

                $variantProductId = DB::table('products')->insertGetId([
                    'sku' => $variantSku,
                    'type' => 'simple',
                    'attribute_family_id' => 1,
                    'parent_id' => $parentProductId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->addVariantAttributeValues($variantProductId, $variant, $variantAttributeId);

                $inventoryData = $this->getProductInventoryData($variant['ID'] ?? null);
                DB::table('product_inventories')->insert([
                    'product_id' => $variantProductId,
                    'inventory_source_id' => 1,
                    'vendor_id' => 0,
                    'qty' => $inventoryData['quantity'] ?? 0,
                ]);
            }
        });

        $this->line("Created variant group: {$group['base_name']} ({$parentSku}) with " . count($group['variants']) . " variants");
        return 'created';
    }

    private function addParentAttributeValues(int $productId, array $group): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $sku = $group['base_sku'] . '-PARENT';
        $name = $group['base_name'];
        $urlKey = $this->slugify($name . '-' . $sku);

        $attributes = [
            ['id' => 1, 'value' => $sku, 'type' => 'text'],
            ['id' => 2, 'value' => $name, 'type' => 'text'],
            ['id' => 3, 'value' => $urlKey, 'type' => 'text'],
            ['id' => 9, 'value' => $name, 'type' => 'text'],
            ['id' => 10, 'value' => '<p>' . e($name) . '</p>', 'type' => 'text'],
            ['id' => 8, 'value' => 1, 'type' => 'boolean'],
            ['id' => 7, 'value' => 1, 'type' => 'boolean'],
        ];

        foreach ($attributes as $attr) {
            $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . $attr['id'];
            $data = [
                'product_id' => $productId,
                'attribute_id' => $attr['id'],
                'locale' => $locale,
                'channel' => $channel,
                'unique_id' => $uniqueId,
            ];

            if ($attr['type'] === 'boolean') {
                $data['boolean_value'] = $attr['value'] ? 1 : 0;
                $data['text_value'] = null;
                $data['float_value'] = null;
            } else {
                $data['text_value'] = (string) $attr['value'];
                $data['float_value'] = null;
                $data['boolean_value'] = null;
            }

            DB::table('product_attribute_values')->insert($data);
        }
    }

    private function addVariantAttributeValues(int $productId, array $variant, int $variantAttributeId): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $sku = $this->getProductSku($variant);
        $name = $variant['ItemName'] ?? '';
        $description = $variant['ItemDescription'] ?? '';
        $inventoryData = $this->getProductInventoryData($variant['ID'] ?? null);
        $price = $inventoryData['price'] ?? null;

        $attributes = [
            ['id' => 1, 'value' => $sku, 'type' => 'text'],
            ['id' => 2, 'value' => $name, 'type' => 'text'],
            ['id' => 9, 'value' => mb_substr($description, 0, 200), 'type' => 'text'],
            ['id' => 10, 'value' => '<p>' . e($description) . '</p>', 'type' => 'text'],
            [$variantAttributeId, 'value' => $variant['variant_value'] ?? '', 'type' => 'text'],
        ];

        if ($price) {
            $attributes[] = ['id' => 11, 'value' => $price, 'type' => 'float'];
        }

        foreach ($attributes as $attr) {
            $attrId = is_array($attr) ? $attr['id'] : $attr;
            $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . $attrId;

            $data = [
                'product_id' => $productId,
                'attribute_id' => $attrId,
                'locale' => $locale,
                'channel' => $channel,
                'unique_id' => $uniqueId,
            ];

            if (($attr['type'] ?? '') === 'float') {
                $data['float_value'] = (float) $attr['value'];
                $data['text_value'] = null;
                $data['boolean_value'] = null;
            } else {
                $data['text_value'] = (string) ($attr['value'] ?? '');
                $data['float_value'] = null;
                $data['boolean_value'] = null;
            }

            DB::table('product_attribute_values')->insert($data);
        }
    }

    private function getOrCreateVariantAttribute(string $variantType): int
    {
        $attributeCode = 'variant_' . strtolower($variantType);

        $existing = DB::table('attributes')->where('code', $attributeCode)->first();
        if ($existing) {
            return $existing->id;
        }

        return DB::table('attributes')->insertGetId([
            'code' => $attributeCode,
            'admin_name' => ucfirst($variantType),
            'type' => 'text',
            'validation' => null,
            'position' => 100,
            'is_required' => 0,
            'is_unique' => 0,
            'is_filterable' => 1,
            'is_configurable' => 1,
            'is_visible_on_front' => 1,
            'is_user_defined' => 1,
            'swatch_type' => null,
            'use_in_flat' => 1,
            'is_comparable' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSimpleProduct(array $product): void
    {
        $sku = $this->getProductSku($product);
        $name = $product['ItemName'] ?? ('Product ' . $sku);
        $description = $product['ItemDescription'] ?? '';
        $manufacturerId = $product['ManufacturerID'] ?? null;
        $partId = $product['ID'] ?? null;

        $productId = DB::table('products')->insertGetId([
            'sku' => $sku,
            'type' => 'simple',
            'attribute_family_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inventoryData = $this->getProductInventoryData($partId);
        $price = $inventoryData['price'] ?? null;
        $quantity = $inventoryData['quantity'] ?? 0;

        $this->addSimpleAttributeValues($productId, $name, $description, $price, $sku);

        $categoryId = $this->getCategoryId($manufacturerId);
        if ($categoryId) {
            DB::table('product_categories')->insert([
                'product_id' => $productId,
                'category_id' => $categoryId,
            ]);
        }

        DB::table('product_inventories')->insert([
            'product_id' => $productId,
            'inventory_source_id' => 1,
            'vendor_id' => 0,
            'qty' => $quantity,
        ]);
    }

    private function addSimpleAttributeValues(int $productId, string $name, string $description, ?float $price, string $sku): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $urlKey = $this->slugify($name . '-' . $sku);

        $attributes = [
            ['id' => 1, 'value' => $sku, 'type' => 'text'],
            ['id' => 2, 'value' => $name, 'type' => 'text'],
            ['id' => 3, 'value' => $urlKey, 'type' => 'text'],
            ['id' => 9, 'value' => mb_substr($description, 0, 200), 'type' => 'text'],
            ['id' => 10, 'value' => '<p>' . e($description) . '</p>', 'type' => 'text'],
            ['id' => 8, 'value' => 1, 'type' => 'boolean'],
            ['id' => 7, 'value' => 1, 'type' => 'boolean'],
        ];

        if ($price) {
            $attributes[] = ['id' => 11, 'value' => $price, 'type' => 'float'];
        }

        foreach ($attributes as $attr) {
            $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . $attr['id'];
            $data = [
                'product_id' => $productId,
                'attribute_id' => $attr['id'],
                'locale' => $locale,
                'channel' => $channel,
                'unique_id' => $uniqueId,
            ];

            if ($attr['type'] === 'boolean') {
                $data['boolean_value'] = $attr['value'] ? 1 : 0;
                $data['text_value'] = null;
                $data['float_value'] = null;
            } elseif ($attr['type'] === 'float') {
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
        }

        return ['price' => null, 'quantity' => 0];
    }

    private function getCategoryId(?string $manufacturerId): ?int
    {
        if (!$manufacturerId) {
            return null;
        }

        if (!isset($this->manufacturers[$manufacturerId])) {
            $this->loadManufacturers();
        }

        $name = $this->manufacturers[$manufacturerId] ?? 'Manufacturer ' . $manufacturerId;

        $existing = DB::table('category_translations')
            ->where('name', $name)
            ->where('locale', 'en')
            ->first();

        if ($existing) {
            return (int) $existing->category_id;
        }

        return null;
    }

    private function loadKits(): void
    {
        if ($this->useIndexes) {
            $this->kits = DB::table('ds_kit_index')->pluck('primary_partmaster_id')->toArray();
            $this->info("Loaded " . count($this->kits) . " parent kit products from index");
            return;
        }
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
            if ($row && isset($row['id'], $row['ManufacturerName'])) {
                $this->manufacturers[trim($row['id'], '"')] = trim($row['ManufacturerName'], '"');
            }
        }
    }

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

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['ID'])) {
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
            return $products;
        }

        $kitsFlipped = array_flip($this->kits);
        $filtered = [];
        foreach ($products as $product) {
            $productId = $product['ID'] ?? null;
            if ($productId && !isset($kitsFlipped[$productId])) {
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

    private function slugify(string $text): string
    {
        static $usedKeys = [];

        $url = strtolower(trim($text));
        $url = preg_replace('/[^a-z0-9]+/i', '-', $url);
        $url = trim($url, '-');

        $baseKey = $url;
        $n = 1;

        while (isset($usedKeys[$url])) {
            $url = $baseKey . '-' . $n;
            $n++;
        }

        $usedKeys[$url] = true;
        return $url;
    }

    private function displayDryRunSample(array $items): void
    {
        $sample = array_slice($items, 0, 5);
        $this->info('Sample items that would be imported:');

        foreach ($sample as $item) {
            if (isset($item['is_variant_group']) && $item['is_variant_group']) {
                $this->line("VARIANT GROUP: {$item['base_name']}");
                $this->line("  Base SKU: {$item['base_sku']}");
                $this->line("  Variants: " . count($item['variants']));
                foreach ($item['variants'] as $v) {
                    $this->line("    - {$v['variant_value']} (SKU: " . $this->getProductSku($v) . ")");
                }
            } else {
                $sku = $this->getProductSku($item);
                $name = $item['ItemName'] ?? 'N/A';
                $this->line("SIMPLE: {$name} (SKU: {$sku})");
            }
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

        return null;
    }
}
