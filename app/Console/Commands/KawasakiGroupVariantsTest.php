<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductFlat;
use Webkul\Attribute\Models\Attribute;

class KawasakiGroupVariantsTest extends Command
{
    protected $signature = 'kawasaki:group-variants-test';
    protected $description = 'Test grouping Kawasaki product variants (10 groups only)';

    public function handle()
    {
        $this->info('Starting Kawasaki Variant Grouping Test (10 groups)...');
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

        // Step 2: Display groups and ask for confirmation
        $this->displayGroups($variantGroups);
        
        if (!$this->confirm('Do you want to proceed with grouping these variants?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        // Step 3: Process each group
        $this->newLine();
        $this->info('Step 2: Creating configurable products...');
        
        $bar = $this->output->createProgressBar($variantGroups->count());
        $bar->start();

        $successCount = 0;
        $errorCount = 0;

        foreach ($variantGroups as $group) {
            try {
                $this->processVariantGroup($group);
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
        $this->info("✓ Successfully processed: {$successCount} groups");
        if ($errorCount > 0) {
            $this->warn("✗ Errors: {$errorCount} groups");
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
            ->limit(10)
            ->get();
    }

    protected function displayGroups($groups)
    {
        $this->table(
            ['Product Name', 'Variant Count', 'Product IDs'],
            $groups->map(function ($group) {
                return [
                    substr($group->name, 0, 60) . (strlen($group->name) > 60 ? '...' : ''),
                    $group->variant_count,
                    substr($group->product_ids, 0, 40) . '...'
                ];
            })
        );
    }

    protected function processVariantGroup($group)
    {
        $productIds = explode(',', $group->product_ids);
        
        // Get full product details
        $products = Product::whereIn('id', $productIds)->with('attribute_values')->get();
        
        if ($products->count() < 2) {
            throw new \Exception('Not enough products in group');
        }

        // Check if parent already exists
        $parentSku = $this->generateParentSku($products->first()->sku);
        $existingParent = Product::where('sku', $parentSku)->first();
        
        if ($existingParent) {
            $this->warn("Parent already exists for: {$group->name}");
            return;
        }

        // Extract variant options from all products
        $variantOptions = $this->extractVariantOptions($products);
        
        if (empty($variantOptions)) {
            throw new \Exception('Could not extract variant options');
        }

        // Create parent configurable product
        $parent = $this->createParentProduct($products->first(), $parentSku, $variantOptions);
        
        // Link children to parent
        $this->linkChildrenToParent($parent, $products);
        
        // Hide children from individual display
        $this->hideChildProducts($products);
    }

    protected function generateParentSku($childSku)
    {
        // Remove variant suffix and add -PARENT
        // Example: K0322618BK2T -> K0322618-PARENT
        $baseSku = preg_replace('/[A-Z0-9]{2,4}$/', '', $childSku);
        return $baseSku . '-PARENT';
    }

    protected function extractVariantOptions($products)
    {
        $options = [
            'size' => [],
            'color' => []
        ];

        foreach ($products as $product) {
            // Try to find SizeAndStyle in product attributes or description
            $sizeAndStyle = $this->getSizeAndStyle($product);
            
            if ($sizeAndStyle) {
                $parsed = $this->parseSizeAndStyle($sizeAndStyle);
                
                if (isset($parsed['size'])) {
                    $options['size'][] = $parsed['size'];
                }
                if (isset($parsed['color'])) {
                    $options['color'][] = $parsed['color'];
                }
            }
        }

        // Remove duplicates and empty arrays
        $options['size'] = array_unique(array_filter($options['size']));
        $options['color'] = array_unique(array_filter($options['color']));

        // Remove empty option types
        return array_filter($options, function($opts) {
            return !empty($opts);
        });
    }

    protected function getSizeAndStyle($product)
    {
        foreach ($product->attribute_values as $attrValue) {
            if (!$attrValue->attribute) {
                continue;
            }
            
            if (in_array($attrValue->attribute->code, ['size_and_style', 'variant_options'])) {
                return $attrValue->text_value;
            }
        }

        return null;
    }

    protected function parseSizeAndStyle($sizeAndStyle)
    {
        $result = [];
        
        // Format: "2T, BLACK" or "LARGE" or "RED, XL"
        $parts = array_map('trim', explode(',', $sizeAndStyle));
        
        if (count($parts) == 2) {
            // Assume first is size, second is color
            $result['size'] = $parts[0];
            $result['color'] = $parts[1];
        } elseif (count($parts) == 1) {
            // Single value - determine if it's size or color
            $value = $parts[0];
            
            // Common color keywords
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
        $this->createSuperAttributes($parentId);

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

        $price = DB::table('product_attribute_values')
            ->where('product_id', $referenceProduct->id)
            ->where('attribute_id', 11)
            ->value('float_value') ?? 0;

        $urlKey = $this->slugify($name . '-' . $parentSku);

        $attributes = [
            ['id' => 1, 'value' => $parentSku, 'type' => 'text'],
            ['id' => 2, 'value' => $name, 'type' => 'text'],
            ['id' => 3, 'value' => $urlKey, 'type' => 'text'],
            ['id' => 9, 'value' => $name, 'type' => 'text'],
            ['id' => 10, 'value' => $description, 'type' => 'text'],
            ['id' => 7, 'value' => 1, 'type' => 'boolean'],
            ['id' => 8, 'value' => 1, 'type' => 'boolean'],
            ['id' => 11, 'value' => $price, 'type' => 'float'],
            ['id' => 25, 'value' => 'Kawasaki', 'type' => 'text'],
        ];

        foreach ($attributes as $attr) {
            $uniqueId = 'maddparts|en|' . $parentId . '|' . $attr['id'];

            $data = [
                'product_id' => $parentId,
                'attribute_id' => $attr['id'],
                'locale' => 'en',
                'channel' => 'maddparts',
                'unique_id' => $uniqueId,
                'text_value' => null,
                'float_value' => null,
                'integer_value' => null,
                'boolean_value' => null,
            ];

            if ($attr['type'] === 'boolean') {
                $data['boolean_value'] = $attr['value'] ? 1 : 0;
            } elseif ($attr['type'] === 'float') {
                $data['float_value'] = $attr['value'];
            } else {
                $data['text_value'] = (string) $attr['value'];
            }

            DB::table('product_attribute_values')->insert($data);
        }
    }

    protected function createParentFlat($parentId, $parentSku, $referenceProduct)
    {
        $name = DB::table('product_flat')
            ->where('product_id', $referenceProduct->id)
            ->value('name') ?? 'Product';

        $description = DB::table('product_flat')
            ->where('product_id', $referenceProduct->id)
            ->value('description') ?? '';

        $price = DB::table('product_flat')
            ->where('product_id', $referenceProduct->id)
            ->value('price') ?? 0;

        DB::table('product_flat')->insert([
            'product_id' => $parentId,
            'sku' => $parentSku,
            'name' => $name,
            'description' => $description,
            'short_description' => $name,
            'url_key' => $this->slugify($name . '-' . $parentSku),
            'price' => $price,
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
        $firstImage = DB::table('product_images')
            ->where('product_id', $childId)
            ->first();

        if ($firstImage) {
            DB::table('product_images')->insert([
                'type' => 'images',
                'path' => $firstImage->path,
                'product_id' => $parentId,
            ]);
        }
    }

    protected function slugify($text)
    {
        $url = strtolower(trim($text));
        $url = preg_replace('/[^a-z0-9]+/i', '-', $url);
        return trim($url, '-');
    }

    protected function linkChildrenToParent($parent, $products)
    {
        foreach ($products as $product) {
            DB::table('products')
                ->where('id', $product->id)
                ->update([
                    'parent_id' => $parent->id,
                    'updated_at' => now(),
                ]);
        }
    }

    protected function createSuperAttributes($parentId)
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
        $productIds = $products->pluck('id')->toArray();
        
        DB::table('product_flat')
            ->whereIn('product_id', $productIds)
            ->update(['visible_individually' => 0]);
    }
}
