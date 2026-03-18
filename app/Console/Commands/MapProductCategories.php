<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MapProductCategories extends Command
{
    protected $signature = 'ari:map-categories {--batch=5000} {--skip=0} {--dry-run}';
    protected $description = 'Map proper categories to existing products';

    private $categoryCache = [];
    private $skuToPartmasterCache = [];

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Category Mapping Started');

        if (!$this->checkIndexes()) {
            $this->error('Category indexes not found. Run: php artisan datastream:build-category-indexes');
            return Command::FAILURE;
        }

        try {
            $totalProducts = DB::table('products')->count();
            $this->info("Total products: {$totalProducts}");
            $this->info("Batch size: {$batch}" . ($skip > 0 ? ", skipping first {$skip}" : ''));

            if ($skip >= $totalProducts) {
                $this->error("Skip offset {$skip} >= total {$totalProducts}");
                return Command::FAILURE;
            }

            // Process only products without categories if skip is 0
            if ($skip == 0) {
                $products = DB::table('products as p')
                    ->leftJoin('product_categories as pc', 'p.id', '=', 'pc.product_id')
                    ->whereNull('pc.product_id')
                    ->select('p.id', 'p.sku')
                    ->orderBy('p.id')
                    ->take($batch)
                    ->get();
            } else {
                // Original skip-based approach for compatibility
                $products = DB::table('products')
                    ->select('id', 'sku')
                    ->skip($skip)
                    ->take($batch)
                    ->get();
            }

            if ($products->isEmpty()) {
                $this->warn('No products to process');
                return Command::SUCCESS;
            }

            $this->info("Processing " . $products->count() . " products");

            if ($dryRun) {
                $this->dryRunSample($products);
                return Command::SUCCESS;
            }

            $processed = 0;
            $mapped = 0;
            $notFound = 0;

            $bulkMappings = [];

            foreach ($products as $product) {
                $processed++;

                try {
                    $categoryIds = $this->getCategoryIdsForProduct($product->sku);

                    if (!empty($categoryIds)) {
                        foreach ($categoryIds as $categoryId) {
                            $bulkMappings[] = [
                                'product_id' => $product->id,
                                'category_id' => $categoryId,
                            ];
                        }
                        $mapped++;
                    } else {
                        $notFound++;
                    }

                    if (count($bulkMappings) >= 1000) {
                        $this->flushMappings($bulkMappings);
                        $this->line("Progress: {$processed}/" . $products->count() . " (mapped: {$mapped}, not found: {$notFound})");
                    }

                } catch (Exception $e) {
                    $this->error("Failed for SKU {$product->sku}: " . $e->getMessage());
                    Log::error('Category mapping error', [
                        'sku' => $product->sku,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            if (!empty($bulkMappings)) {
                $this->flushMappings($bulkMappings);
            }

            $this->info('Category mapping complete!');
            $this->table(['Metric', 'Count'], [
                ['Processed', $processed],
                ['Mapped', $mapped],
                ['Not Found', $notFound],
            ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Fatal: ' . $e->getMessage());
            Log::error('Category mapping fatal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function checkIndexes(): bool
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
            $catId = $this->getOrCreateBagistoCategory($levelMasterId);
            if ($catId) {
                $categoryIds[] = $catId;
            }
        }

        return array_unique($categoryIds);
    }

    private function getPartmasterIdFromSku(string $sku): ?string
    {
        if (isset($this->skuToPartmasterCache[$sku])) {
            return $this->skuToPartmasterCache[$sku];
        }

        if (strpos($sku, 'ARI-') === 0) {
            $partmasterId = str_replace('ARI-', '', $sku);
            $this->skuToPartmasterCache[$sku] = $partmasterId;
            return $partmasterId;
        }

        $result = DB::table('ds_sku_partmaster_index')
            ->where('sku', $sku)
            ->value('partmaster_id');

        if ($result) {
            $this->skuToPartmasterCache[$sku] = $result;
            return $result;
        }

        return null;
    }

    private function getOrCreateBagistoCategory(string $levelMasterId): ?int
    {
        if (isset($this->categoryCache[$levelMasterId])) {
            return $this->categoryCache[$levelMasterId];
        }

        $levelMaster = DB::table('ds_level_master_index')
            ->where('id', $levelMasterId)
            ->first();

        if (!$levelMaster) {
            return null;
        }

        if ($levelMaster->bagisto_category_id) {
            $this->categoryCache[$levelMasterId] = $levelMaster->bagisto_category_id;
            return $levelMaster->bagisto_category_id;
        }

        $categoryPath = $this->buildCategoryPath($levelMaster);
        if (empty($categoryPath)) {
            return null;
        }

        $categoryId = $this->createCategoryHierarchy($categoryPath);

        if ($categoryId) {
            DB::table('ds_level_master_index')
                ->where('id', $levelMasterId)
                ->update(['bagisto_category_id' => $categoryId]);

            $this->categoryCache[$levelMasterId] = $categoryId;
        }

        return $categoryId;
    }

    private function buildCategoryPath(object $levelMaster): array
    {
        $path = [];

        if ($levelMaster->level_three_id && $levelMaster->level_three_id !== '2') {
            $level3 = DB::table('ds_level_three_index')
                ->where('id', $levelMaster->level_three_id)
                ->first();
            if ($level3 && !empty($level3->description)) {
                $path[] = $level3->description;
            }
        }

        if ($levelMaster->level_four_id && $levelMaster->level_four_id !== '2') {
            $level4 = DB::table('ds_level_four_index')
                ->where('id', $levelMaster->level_four_id)
                ->first();
            if ($level4 && !empty($level4->description)) {
                $path[] = $level4->description;
            }
        }

        if ($levelMaster->level_five_id && $levelMaster->level_five_id !== '2') {
            $level5 = DB::table('ds_level_five_index')
                ->where('id', $levelMaster->level_five_id)
                ->first();
            if ($level5 && !empty($level5->description)) {
                $path[] = $level5->description;
            }
        }

        return $path;
    }

    private function createCategoryHierarchy(array $path): ?int
    {
        $parentId = 1;

        foreach ($path as $index => $categoryName) {
            $categoryId = $this->findOrCreateCategory($categoryName, $parentId);
            if (!$categoryId) {
                return null;
            }
            $parentId = $categoryId;
        }

        return $parentId;
    }

    private function findOrCreateCategory(string $name, int $parentId): ?int
    {
        $existing = DB::table('category_translations')
            ->where('name', $name)
            ->where('locale', 'en')
            ->first();

        if ($existing) {
            $category = DB::table('categories')
                ->where('id', $existing->category_id)
                ->where('parent_id', $parentId)
                ->first();

            if ($category) {
                return $category->id;
            }
        }

        $maxPosition = DB::table('categories')
            ->where('parent_id', $parentId)
            ->max('position') ?? 0;

        $categoryId = DB::table('categories')->insertGetId([
            'parent_id' => $parentId,
            'position' => $maxPosition + 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('category_translations')->insert([
            'category_id' => $categoryId,
            'name' => $name,
            'slug' => $this->slugify($name) . '-' . $categoryId,
            'description' => '',
            'meta_title' => $name,
            'meta_description' => '',
            'meta_keywords' => '',
            'locale' => 'en',
        ]);

        return $categoryId;
    }

    private function flushMappings(array &$bulkMappings): void
    {
        if (empty($bulkMappings)) {
            return;
        }

        try {
            DB::beginTransaction();
            
            // Remove duplicates within the batch
            $uniqueMappings = [];
            foreach ($bulkMappings as $mapping) {
                $key = $mapping['product_id'] . '-' . $mapping['category_id'];
                $uniqueMappings[$key] = $mapping;
            }
            
            // Use single bulk insert for better performance
            if (!empty($uniqueMappings)) {
                DB::table('product_categories')->insertOrIgnore(array_values($uniqueMappings));
            }
            
            DB::commit();
        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }

        $bulkMappings = [];
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        return trim($text, '-');
    }

    private function dryRunSample($products): void
    {
        $this->info('DRY-RUN: Sample category mappings');

        $sample = $products->take(5);
        foreach ($sample as $product) {
            $categoryIds = $this->getCategoryIdsForProduct($product->sku);

            $this->line("SKU: {$product->sku}");
            $this->line("  Product ID: {$product->id}");
            $this->line("  Categories: " . (empty($categoryIds) ? 'None' : implode(', ', $categoryIds)));
            $this->line("");
        }
    }
}
