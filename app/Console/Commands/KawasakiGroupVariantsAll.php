<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductFlat;
use Webkul\Attribute\Models\Attribute;

class KawasakiGroupVariantsAll extends Command
{
    protected $signature = 'kawasaki:group-variants-all {--dry-run : Preview what would be grouped without making changes}';
    protected $description = 'Group ALL Kawasaki product variants into configurable products';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        
        $this->info('Starting Kawasaki Variant Grouping (ALL products)...');
        $this->newLine();

        // Step 1: Find variant groups
        $this->info('Step 1: Finding products with variants...');
        
        $variantGroups = $this->findVariantGroups();
        
        if ($variantGroups->isEmpty()) {
            $this->error('No variant groups found!');
            return 1;
        }

        $this->info("Found {$variantGroups->count()} variant groups");
        $this->newLine();

        // Step 2: Display summary
        $totalProducts = $variantGroups->sum('variant_count');
        $this->info("Total products to be grouped: {$totalProducts}");
        $this->info("Parent products to be created: {$variantGroups->count()}");
        $this->newLine();

        if (!$dryRun && !$this->confirm('Do you want to proceed with grouping these variants?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Step 3: Process each group
        $this->newLine();
        $this->info($dryRun ? 'Step 2: Previewing groups...' : 'Step 2: Creating configurable products...');
        
        $bar = $this->output->createProgressBar($variantGroups->count());
        $bar->start();

        $successCount = 0;
        $errorCount = 0;

        foreach ($variantGroups as $group) {
            try {
                if (!$dryRun) {
                    $this->processVariantGroup($group);
                }
                $successCount++;
            } catch (\Exception $e) {
                $errorCount++;
                $this->newLine();
                $this->error("Error processing group '{$group->name}': " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        if ($dryRun) {
            $this->info("✓ Would process: {$successCount} groups ({$totalProducts} products)");
            $this->info("Run without --dry-run to apply changes");
        } else {
            $this->info("✓ Successfully processed: {$successCount} groups");
            if ($errorCount > 0) {
                $this->warn("✗ Errors: {$errorCount} groups");
            }
        }

        return 0;
    }

    protected function findVariantGroups()
    {
        $sizeAndStyleAttrId = DB::table('attributes')
            ->where('code', 'size_and_style')
            ->value('id');

        if (!$sizeAndStyleAttrId) {
            $this->error('size_and_style attribute not found in database!');
            $this->warn('You need to create this attribute first or update the import service to store SizeAndStyle data.');
            return collect([]);
        }

        return DB::table('product_flat as pf')
            ->select('pf.name', DB::raw('COUNT(*) as variant_count'), DB::raw('GROUP_CONCAT(pf.product_id) as product_ids'))
            ->join('product_attribute_values as pav_brand', 'pf.product_id', '=', 'pav_brand.product_id')
            ->join('product_attribute_values as pav_size', 'pf.product_id', '=', 'pav_size.product_id')
            ->where('pf.channel', 'maddparts')
            ->where('pf.locale', 'en')
            ->where('pf.status', 1)
            ->where('pav_brand.attribute_id', 25)
            ->where('pav_brand.text_value', 'Kawasaki')
            ->where('pav_size.attribute_id', $sizeAndStyleAttrId)
            ->whereNotNull('pav_size.text_value')
            ->where('pav_size.text_value', '!=', '')
            ->groupBy('pf.name')
            ->having('variant_count', '>', 1)
            ->orderBy('variant_count', 'desc')
            ->get(); // NO LIMIT - process all groups
    }

    protected function processVariantGroup($group)
    {
        $productIds = explode(',', $group->product_ids);
        $products = DB::table('product_flat')
            ->whereIn('product_id', $productIds)
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $referenceProduct = $products->first();
        $baseSku = $this->extractBaseSku($referenceProduct->sku);
        $parentSku = $baseSku . '-PARENT';

        $existingParent = DB::table('products')->where('sku', $parentSku)->first();
        if ($existingParent) {
            return;
        }

        $variantOptions = [];
        foreach ($products as $product) {
            $sizeAndStyle = $this->getSizeAndStyle($product->product_id);
            if ($sizeAndStyle) {
                $variantOptions[$product->product_id] = $sizeAndStyle;
            }
        }

        $parent = $this->createParentProduct($referenceProduct, $parentSku, $variantOptions);
        $this->linkChildrenToParent($parent, $products);
        $this->hideChildProducts($products);
    }

    protected function extractBaseSku($sku)
    {
        $parts = explode('-', $sku);
        if (count($parts) > 1) {
            array_pop($parts);
            return implode('-', $parts);
        }
        return preg_replace('/\d+$/', '', $sku);
    }

    protected function getSizeAndStyle($productId)
    {
        $sizeAndStyleAttrId = DB::table('attributes')
            ->where('code', 'size_and_style')
            ->value('id');

        if (!$sizeAndStyleAttrId) {
            return null;
        }

        return DB::table('product_attribute_values')
            ->where('product_id', $productId)
            ->where('attribute_id', $sizeAndStyleAttrId)
            ->value('text_value');
    }

    protected function parseSizeAndStyle($sizeAndStyle)
    {
        $result = ['size' => null, 'color' => null];
        
        if (empty($sizeAndStyle)) {
            return $result;
        }

        $parts = array_map('trim', explode(',', $sizeAndStyle));
        
        if (count($parts) >= 2) {
            $result['size'] = $parts[0];
            $result['color'] = $parts[1];
        } elseif (count($parts) == 1) {
            $value = $parts[0];
            
            $colors = ['BLACK', 'WHITE', 'RED', 'BLUE', 'GREEN', 'YELLOW', 'ORANGE', 'PURPLE', 'PINK', 'GRAY', 'GREY'];
            
            if (in_array(strtoupper($value), $colors)) {
                $result['color'] = $value;
            } else {
                $result['size'] = $value;
            }
        }

        return $result;
    }

    protected function createParentProduct($referenceProduct, $parentSku, $variantOptions)
    {
        $parentId = DB::table('products')->insertGetId([
            'sku' => $parentSku,
            'type' => 'configurable',
            'attribute_family_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createParentAttributes($parentId, $parentSku, $referenceProduct);
        $this->createParentFlat($parentId, $parentSku, $referenceProduct);
        $this->copyCategories($parentId, $referenceProduct->id);
        $this->copyFirstImage($parentId, $referenceProduct->id);
        $this->createSuperAttributes($parentId, $variantOptions);

        return DB::table('products')->where('id', $parentId)->first();
    }

    protected function createParentAttributes($parentId, $parentSku, $referenceProduct)
    {
        $name = DB::table('product_attribute_values')
            ->where('product_id', $referenceProduct->id)
            ->where('attribute_id', 2)
            ->value('text_value') ?? $referenceProduct->name ?? 'Product';

        $description = DB::table('product_attribute_values')
            ->where('product_id', $referenceProduct->id)
            ->where('attribute_id', 10)
            ->value('text_value') ?? '<p>' . htmlspecialchars($name) . '</p>';

        $price = DB::table('product_flat')
            ->where('product_id', $referenceProduct->id)
            ->value('price') ?? 0;

        $brand = DB::table('product_attribute_values')
            ->where('product_id', $referenceProduct->id)
            ->where('attribute_id', 25)
            ->value('text_value') ?? 'Kawasaki';

        $attributes = [
            2 => $name,
            3 => str_replace('_', '-', strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $name))) . '-' . strtolower($parentSku),
            9 => $name,
            10 => $description,
            25 => $brand,
        ];

        foreach ($attributes as $attributeId => $value) {
            DB::table('product_attribute_values')->updateOrInsert(
                [
                    'product_id' => $parentId,
                    'attribute_id' => $attributeId,
                    'channel' => 'maddparts',
                    'locale' => 'en',
                ],
                [
                    'product_id' => $parentId,
                    'attribute_id' => $attributeId,
                    'channel' => 'maddparts',
                    'locale' => 'en',
                    'text_value' => $value,
                ]
            );
        }
    }

    protected function createParentFlat($parentId, $parentSku, $referenceProduct)
    {
        $name = DB::table('product_attribute_values')
            ->where('product_id', $referenceProduct->id)
            ->where('attribute_id', 2)
            ->value('text_value') ?? $referenceProduct->name ?? 'Product';

        $urlKey = str_replace('_', '-', strtolower(preg_replace('/[^A-Za-z0-9-]+/', '-', $name))) . '-' . strtolower($parentSku);

        DB::table('product_flat')->insert([
            'product_id' => $parentId,
            'sku' => $parentSku,
            'name' => $name,
            'description' => $referenceProduct->description ?? $name,
            'short_description' => $referenceProduct->short_description ?? $name,
            'url_key' => $urlKey,
            'price' => $referenceProduct->price ?? 0,
            'status' => 1,
            'visible_individually' => 1,
            'channel' => 'maddparts',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function copyCategories($parentId, $childId)
    {
        $categories = DB::table('product_categories')
            ->where('product_id', $childId)
            ->get();

        foreach ($categories as $category) {
            DB::table('product_categories')->insert([
                'product_id' => $parentId,
                'category_id' => $category->category_id,
            ]);
        }
    }

    protected function copyFirstImage($parentId, $childId)
    {
        $image = DB::table('product_images')
            ->where('product_id', $childId)
            ->orderBy('position', 'asc')
            ->first();

        if ($image) {
            DB::table('product_images')->insert([
                'product_id' => $parentId,
                'path' => $image->path,
                'position' => 0,
            ]);
        }
    }

    protected function linkChildrenToParent($parent, $products)
    {
        foreach ($products as $product) {
            DB::table('products')
                ->where('id', $product->product_id)
                ->update([
                    'parent_id' => $parent->id,
                    'updated_at' => now(),
                ]);
        }
    }

    protected function createSuperAttributes($parentId, $variantOptions)
    {
        $sizeAndStyleAttrId = DB::table('attributes')
            ->where('code', 'size_and_style')
            ->value('id');

        if (!$sizeAndStyleAttrId) {
            return;
        }

        DB::table('product_super_attributes')->updateOrInsert(
            [
                'product_id' => $parentId,
                'attribute_id' => $sizeAndStyleAttrId,
            ],
            [
                'product_id' => $parentId,
                'attribute_id' => $sizeAndStyleAttrId,
            ]
        );
    }

    protected function hideChildProducts($products)
    {
        $productIds = $products->pluck('product_id')->toArray();
        
        DB::table('product_flat')
            ->whereIn('product_id', $productIds)
            ->update(['visible_individually' => 0]);
    }
}
