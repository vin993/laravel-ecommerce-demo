<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateExistingProducts extends Command
{
    protected $signature = 'ari:update-existing-products {--batch=1000} {--skip=0} {--dry-run} {--auto-continue}';
    protected $description = 'Update existing simple products to add variants and fix attributes';

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Updating Existing Products with Variants');

        $variantGroupsEnabled = $this->checkVariantGroups();
        if (!$variantGroupsEnabled) {
            $this->error('Variant groups not found. Run: php artisan datastream:build-variant-groups');
            return Command::FAILURE;
        }

        $existingProducts = DB::table('products')
            ->where('type', 'simple')
            ->whereNull('parent_id')
            ->orderBy('id')
            ->skip($skip)
            ->limit($batch)
            ->get();

        $total = DB::table('products')
            ->where('type', 'simple')
            ->whereNull('parent_id')
            ->count();

        $this->info("Total existing simple products: {$total}");
        $this->info("Processing batch: " . count($existingProducts) . " products");

        if ($dryRun) {
            $this->info('DRY-RUN MODE');
            $this->displayDryRunSample($existingProducts);
            return Command::SUCCESS;
        }

        $processed = 0;
        $converted = 0;
        $skipped = 0;

        $productSkus = $existingProducts->pluck('sku')->toArray();

        $variantData = DB::table('ds_variant_groups')
            ->join('products', 'ds_variant_groups.partmaster_id', '=', 'products.sku')
            ->orWhereIn('products.sku', $productSkus)
            ->select('ds_variant_groups.*')
            ->get()
            ->groupBy('variant_group_id');

        $this->info("Found " . count($variantData) . " variant groups in this batch");

        foreach ($variantData as $groupId => $variants) {
            if (count($variants) > 1) {
                $processed++;

                try {
                    $result = $this->convertToVariantGroup($variants);
                    if ($result === 'converted') {
                        $converted++;
                    } elseif ($result === 'skipped') {
                        $skipped++;
                    }

                    if ($processed % 50 === 0) {
                        $this->line("Progress: {$processed} groups (converted: {$converted}, skipped: {$skipped})");
                        gc_collect_cycles();
                    }

                } catch (Exception $e) {
                    $this->error("Failed: " . $e->getMessage());
                    Log::error('Update existing products failure', ['error' => $e->getMessage()]);
                }
            }
        }

        $this->info('Update complete!');
        $this->table(['Metric', 'Count'], [
            ['Groups processed', $processed],
            ['Converted to variants', $converted],
            ['Skipped', $skipped],
        ]);

        if ($this->option('auto-continue') && $skip + $batch < $total) {
            $nextSkip = $skip + $batch;
            $this->info("Auto-continuing with skip={$nextSkip}");
            $this->call('ari:update-existing-products', [
                '--batch' => $batch,
                '--skip' => $nextSkip,
                '--auto-continue' => true
            ]);
        } elseif ($skip + $batch < $total) {
            $nextSkip = $skip + $batch;
            $this->info("To continue, run: php artisan ari:update-existing-products --skip={$nextSkip} --batch={$batch}");
        } else {
            $this->info('All products processed!');
        }

        return Command::SUCCESS;
    }

    private function checkVariantGroups(): bool
    {
        try {
            return DB::table('ds_variant_groups')->count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function convertToVariantGroup($variants): string
    {
        $parentSku = $variants->first()->base_sku . '-PARENT';

        if (DB::table('products')->where('sku', $parentSku)->exists()) {
            return 'skipped';
        }

        $variantProductIds = [];
        $firstProduct = null;

        foreach ($variants as $variant) {
            $product = DB::table('products')
                ->where('sku', $variant->partmaster_id)
                ->orWhere('sku', 'LIKE', '%' . $variant->partmaster_id . '%')
                ->first();

            if ($product && $product->type === 'simple' && is_null($product->parent_id)) {
                $variantProductIds[] = $product->id;
                if (!$firstProduct) {
                    $firstProduct = $product;
                }
            }
        }

        if (count($variantProductIds) < 2) {
            return 'skipped';
        }

        DB::transaction(function () use ($variants, $parentSku, $variantProductIds, $firstProduct) {
            $parentProductId = DB::table('products')->insertGetId([
                'sku' => $parentSku,
                'type' => 'configurable',
                'attribute_family_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createParentAttributes($parentProductId, $variants->first(), $firstProduct);

            $categoryId = DB::table('product_categories')
                ->where('product_id', $firstProduct->id)
                ->value('category_id');

            if ($categoryId) {
                DB::table('product_categories')->insert([
                    'product_id' => $parentProductId,
                    'category_id' => $categoryId,
                ]);
            }

            $variantAttributeId = $this->getOrCreateVariantAttribute($variants->first()->variant_type);

            foreach ($variants as $variant) {
                $product = DB::table('products')
                    ->where('sku', $variant->partmaster_id)
                    ->orWhere('sku', 'LIKE', '%' . $variant->partmaster_id . '%')
                    ->first();

                if ($product && in_array($product->id, $variantProductIds)) {
                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'parent_id' => $parentProductId,
                            'updated_at' => now(),
                        ]);

                    $this->addVariantAttributeToProduct($product->id, $variantAttributeId, $variant->variant_value);
                }
            }
        });

        $this->line("Converted: {$variants->first()->base_name} with " . count($variantProductIds) . " variants");
        return 'converted';
    }

    private function createParentAttributes(int $parentProductId, $variantInfo, $firstProduct): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $sku = $variantInfo->base_sku . '-PARENT';
        $name = $variantInfo->base_name;
        $urlKey = $this->slugify($name . '-' . $sku);

        $description = DB::table('product_attribute_values')
            ->where('product_id', $firstProduct->id)
            ->where('attribute_id', 10)
            ->value('text_value');

        $attributes = [
            ['id' => 1, 'value' => $sku, 'type' => 'text'],
            ['id' => 2, 'value' => $name, 'type' => 'text'],
            ['id' => 3, 'value' => $urlKey, 'type' => 'text'],
            ['id' => 9, 'value' => $name, 'type' => 'text'],
            ['id' => 10, 'value' => $description ?: '<p>' . e($name) . '</p>', 'type' => 'text'],
            ['id' => 8, 'value' => 1, 'type' => 'boolean'],
            ['id' => 7, 'value' => 1, 'type' => 'boolean'],
        ];

        foreach ($attributes as $attr) {
            $uniqueId = $channel . '|' . $locale . '|' . $parentProductId . '|' . $attr['id'];
            $data = [
                'product_id' => $parentProductId,
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

    private function addVariantAttributeToProduct(int $productId, int $variantAttributeId, string $variantValue): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . $variantAttributeId;

        $existing = DB::table('product_attribute_values')
            ->where('product_id', $productId)
            ->where('attribute_id', $variantAttributeId)
            ->exists();

        if (!$existing) {
            DB::table('product_attribute_values')->insert([
                'product_id' => $productId,
                'attribute_id' => $variantAttributeId,
                'locale' => $locale,
                'channel' => $channel,
                'unique_id' => $uniqueId,
                'text_value' => $variantValue,
                'float_value' => null,
                'boolean_value' => null,
            ]);
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
            'is_comparable' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function slugify(string $text): string
    {
        $url = strtolower(trim($text));
        $url = preg_replace('/[^a-z0-9]+/i', '-', $url);
        return trim($url, '-');
    }

    private function displayDryRunSample($products): void
    {
        $sample = $products->take(5);
        $this->info('Sample products that would be analyzed:');

        foreach ($sample as $product) {
            $this->line("Product ID: {$product->id}");
            $this->line("  SKU: {$product->sku}");
            $this->line("  Type: {$product->type}");
            $this->line("");
        }
    }
}
