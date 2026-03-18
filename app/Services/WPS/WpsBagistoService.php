<?php

namespace App\Services\WPS;

use App\Models\WPS\WpsProduct;
use App\Models\WPS\WpsProductItem;
use Webkul\Product\Models\Product;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductAttributeValue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WpsBagistoService
{
    protected $apiService;
    protected $defaultAttributeFamily;
    protected $defaultChannel = 'maddparts';
    protected $defaultLocale = 'en';
    protected $attributeMap;

    public function __construct(WpsApiService $apiService)
    {
        $this->apiService = $apiService;
        if (\Schema::hasTable('attribute_families')) {
            $this->defaultAttributeFamily = \Webkul\Attribute\Models\AttributeFamily::first();
        }
        $this->initAttributeMap();
    }

    /**
     * Initialize attribute mapping based on actual Bagisto attributes
     */
    protected function initAttributeMap()
    {
        $this->attributeMap = [
            'sku' => 1,
            'name' => 2,
            'url_key' => 3,
            'tax_category_id' => 4,
            'new' => 5,
            'featured' => 6,
            'visible_individually' => 7,
            'status' => 8,
            'short_description' => 9,
            'description' => 10,
            'price' => 11,
            'cost' => 12,
            'special_price' => 13,
            'special_price_from' => 14,
            'special_price_to' => 15,
            'meta_title' => 16,
            'meta_keywords' => 17,
            'meta_description' => 18,
            'length' => 19,
            'width' => 20,
            'height' => 21,
            'weight' => 22,
        ];
    }

    /**
     * Create Bagisto products from WPS data with dimensions
     */
    public function createBagistoProducts($limit = 100)
    {
        Log::channel('wps')->info('Starting Bagisto product creation with dimensions', ['limit' => $limit]);

        $wpsProducts = WpsProduct::with(['items' => function($query) {
            $query->dropShipEligible()->available();
        }])
            ->whereNull('bagisto_product_id')
            ->where('status', 'synced')
            ->limit($limit)
            ->get();

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($wpsProducts as $wpsProduct) {
            try {
                $result = $this->createSingleBagistoProduct($wpsProduct);

                if ($result['created']) {
                    $created++;
                    Log::channel('wps')->info('Created Bagisto product', [
                        'wps_product_id' => $wpsProduct->wps_product_id,
                        'bagisto_product_id' => $wpsProduct->bagisto_product_id,
                        'name' => $wpsProduct->name,
                        'has_dimensions' => $result['has_dimensions']
                    ]);
                } else {
                    $skipped++;
                    Log::channel('wps')->warning('Skipped product creation', [
                        'wps_product_id' => $wpsProduct->wps_product_id,
                        'reason' => $result['reason']
                    ]);
                }

            } catch (\Exception $e) {
                $errors++;
                Log::channel('wps')->error('Failed to create Bagisto product', [
                    'wps_product_id' => $wpsProduct->wps_product_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        Log::channel('wps')->info('Bagisto product creation completed', [
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors
        ]);

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Create a single Bagisto product with variants
     */
    protected function createSingleBagistoProduct($wpsProduct)
    {
        DB::beginTransaction();

        try {
            $eligibleItems = $wpsProduct->items;

            if ($eligibleItems->isEmpty()) {
                return ['created' => false, 'reason' => 'No eligible items found', 'has_dimensions' => false];
            }

            // Check if any item has minimum required data
            $validItems = $eligibleItems->filter(function($item) {
                return $this->hasMinimumRequiredData($item);
            });

            if ($validItems->isEmpty()) {
                return ['created' => false, 'reason' => 'No items with valid data found', 'has_dimensions' => false];
            }

            $variantsCreated = 0;
            $parentProduct = null;

            // If only one item, create simple product
            if ($validItems->count() == 1) {
                $singleItem = $validItems->first();
                $parentProduct = $this->createSimpleProduct($wpsProduct, $singleItem);
                $singleItem->update(['bagisto_product_id' => $parentProduct->id]);
                $variantsCreated = 1;
            } else {
                // Multiple items - create configurable product with variants
                $parentProduct = $this->createConfigurableProduct($wpsProduct, $validItems);
                $variantsCreated = $this->createVariants($parentProduct, $validItems);
            }

            $wpsProduct->update(['bagisto_product_id' => $parentProduct->id]);

            DB::commit();

            return [
                'created' => true, 
                'has_variants' => $validItems->count() > 1,
                'has_dimensions' => $validItems->first()->hasDimensions(),
                'variants_created' => $variantsCreated,
                'product' => $parentProduct
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Check if item has minimum required data for Bagisto
     */
    protected function hasMinimumRequiredData($wpsItem)
    {
        return !empty($wpsItem->sku) &&
               !empty($wpsItem->name) &&
               ($wpsItem->getEffectivePrice() > 0);
    }

    /**
     * Create simple product with enhanced attributes
     */
    protected function createSimpleProduct($wpsProduct, $wpsItem)
    {
        // Check if product with this SKU already exists
        $existingProduct = Product::where('sku', $wpsItem->sku)->first();

        if ($existingProduct) {
            // Link existing product to WPS data and update all attributes
            Log::channel('wps')->info('Found existing Bagisto product, updating with WPS data', [
                'sku' => $wpsItem->sku,
                'existing_product_id' => $existingProduct->id,
                'wps_product_id' => $wpsProduct->wps_product_id
            ]);

            // Update the existing product with new WPS data
            $this->addProductAttributes($existingProduct, $wpsProduct, $wpsItem);

            // Update inventory for existing product
            app(WpsInventoryService::class)->updateBagistoInventory(
                $existingProduct->id,
                $wpsItem->inventory_total
            );

            return $existingProduct;
        }

        $productData = [
            'type' => 'simple',
            'sku' => $wpsItem->sku,
            'parent_id' => null,
            'attribute_family_id' => $this->defaultAttributeFamily->id,
            'api_source' => 'wps',
            'external_id' => (string) $wpsProduct->wps_product_id,
        ];

        $product = Product::create($productData);
        $this->addProductAttributes($product, $wpsProduct, $wpsItem);
        $this->addToDefaultCategory($product);

        // Update inventory
        app(WpsInventoryService::class)->updateBagistoInventory(
            $product->id,
            $wpsItem->inventory_total
        );

        return $product;
    }

    /**
     * Add product attributes with dimensions and enhanced data
     */
    protected function addProductAttributes($product, $wpsProduct, $wpsItem)
    {
        $effectivePrice = $wpsItem->getEffectivePrice();

        // Handle missing descriptions - use product name as fallback
        $productDescription = !empty($wpsProduct->description) ? $wpsProduct->description : $wpsProduct->name;
        $shortDescription = !empty($wpsProduct->description)
            ? $this->truncateText($wpsProduct->description, 255)
            : $wpsProduct->name;

        $attributes = [
            'name' => $wpsProduct->name,
            'url_key' => $this->generateUrlKey($wpsProduct->name, $product->id),
            'short_description' => $shortDescription,
            'description' => $productDescription,
            'price' => $effectivePrice,
            'cost' => $wpsItem->cost ?? $wpsItem->dealer_price,
            'status' => 1, // Always enable products initially
            'visible_individually' => true,
            'new' => $wpsItem->is_new ?? false,
            'featured' => $wpsItem->is_featured ?? false,
            'meta_title' => $wpsProduct->name,
            'meta_description' => $this->truncateText($productDescription, 160),
        ];

        // Always add weight (mandatory field) - use default if missing
        $attributes['weight'] = $wpsItem->weight && $wpsItem->weight > 0 ? $wpsItem->weight : 1.0;

        // Add other dimensions if available
        if ($wpsItem->hasDimensions()) {
            $attributes['length'] = $wpsItem->length;
            $attributes['width'] = $wpsItem->width;
            $attributes['height'] = $wpsItem->height;

            Log::channel('wps')->info('Adding dimensions to product', [
                'product_id' => $product->id,
                'weight' => $attributes['weight'],
                'length' => $wpsItem->length,
                'width' => $wpsItem->width,
                'height' => $wpsItem->height
            ]);
        } else {
            Log::channel('wps')->info('Product created with default weight only', [
                'product_id' => $product->id,
                'sku' => $wpsItem->sku,
                'default_weight' => $attributes['weight'],
                'note' => 'Using default weight as original was missing or zero'
            ]);
        }

        // Add special pricing if available
        if ($wpsItem->special_price && $wpsItem->isSpecialPriceActive()) {
            $attributes['special_price'] = $wpsItem->special_price;

            if ($wpsItem->special_price_from) {
                $attributes['special_price_from'] = $wpsItem->special_price_from;
            }

            if ($wpsItem->special_price_to) {
                $attributes['special_price_to'] = $wpsItem->special_price_to;
            }
        }

        // Add each attribute
        foreach ($attributes as $code => $value) {
            $this->addSingleAttribute($product, $code, $value);
        }
    }

    /**
     * Add a single attribute to product with proper context and field type
     */
    protected function addSingleAttribute($product, $code, $value)
    {
        if (!isset($this->attributeMap[$code])) {
            Log::channel('wps')->debug('Attribute not mapped, skipping', ['code' => $code]);
            return;
        }

        $attributeId = $this->attributeMap[$code];

        try {
            // Use the correct channel (maddparts) and locale
            $channel = $this->defaultChannel;
            $locale = $this->defaultLocale;
            
            $uniqueId = $channel . '|' . $locale . '|' . $product->id . '|' . $attributeId;

            $data = [
                'product_id' => $product->id,
                'attribute_id' => $attributeId,
                'locale' => $locale,
                'channel' => $channel,
                'unique_id' => $uniqueId,
            ];

            // Special handling for boolean attributes (status, visible_individually, new, featured)
            if (in_array($code, ['status', 'visible_individually', 'new', 'featured'])) {
                $data['boolean_value'] = $value ? 1 : 0;
            }
            // Special handling for dimension attributes (weight, length, width, height)
            // These are type='text' with validation='decimal' in Bagisto
            elseif (in_array($code, ['weight', 'length', 'width', 'height']) && is_numeric($value)) {
                $data['text_value'] = (string) $value;
            }
            // Handle numeric values (price, cost, etc.)
            elseif (is_numeric($value) && !in_array($code, ['weight', 'length', 'width', 'height', 'status', 'visible_individually', 'new', 'featured'])) {
                $data['float_value'] = (float) $value;
            }
            // Handle date values
            elseif ($value instanceof \Carbon\Carbon || is_string($value) && strtotime($value)) {
                $data['date_value'] = is_string($value) ? $value : $value->toDateString();
            }
            // Handle text values
            else {
                $data['text_value'] = (string) $value;
            }

            // Use updateOrCreate to handle both new and existing attributes
            ProductAttributeValue::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'attribute_id' => $attributeId,
                    'locale' => $locale,
                    'channel' => $channel
                ],
                $data
            );

            Log::channel('wps')->debug('Attribute updated', [
                'product_id' => $product->id,
                'attribute_code' => $code,
                'attribute_id' => $attributeId,
                'value' => $value,
                'stored_in_field' => in_array($code, ['weight', 'length', 'width', 'height']) ? 'text_value' : (is_bool($value) ? 'boolean_value' : (is_numeric($value) ? 'float_value' : 'text_value')),
                'channel' => $channel,
                'locale' => $locale
            ]);

        } catch (\Exception $e) {
            Log::channel('wps')->error('Failed to add/update attribute', [
                'product_id' => $product->id,
                'attribute_code' => $code,
                'value' => $value,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create configurable product (parent)
     */
    protected function createConfigurableProduct($wpsProduct, $validItems)
    {
        // Use the first item's SKU with a suffix for the parent
        $parentSku = $wpsProduct->wps_product_id . '-config';
        
        $productData = [
            'type' => 'configurable',
            'sku' => $parentSku,
            'parent_id' => null,
            'attribute_family_id' => $this->defaultAttributeFamily->id,
            'api_source' => 'wps',
            'external_id' => (string) $wpsProduct->wps_product_id,
        ];

        $product = Product::create($productData);
        
        // Use the first valid item for basic attributes
        $mainItem = $validItems->first();
        $this->addProductAttributes($product, $wpsProduct, $mainItem);
        $this->addToDefaultCategory($product);

        Log::channel('wps')->info('Created configurable product', [
            'wps_product_id' => $wpsProduct->wps_product_id,
            'configurable_sku' => $parentSku,
            'variants_count' => $validItems->count()
        ]);

        return $product;
    }

    /**
     * Create variant products (children)
     */
    protected function createVariants($parentProduct, $validItems)
    {
        $variantsCreated = 0;
        
        foreach ($validItems as $item) {
            try {
                $variantProduct = Product::create([
                    'type' => 'simple',
                    'sku' => $item->sku,
                    'parent_id' => $parentProduct->id,
                    'attribute_family_id' => $this->defaultAttributeFamily->id,
                    'api_source' => 'wps',
                    'external_id' => (string) $item->wps_item_id,
                ]);
                
                // Find the WPS product for this item
                $wpsProduct = WpsProduct::where('wps_product_id', 
                    WpsProductItem::where('id', $item->id)->value('wps_product_id')
                )->first();
                
                $this->addProductAttributes($variantProduct, $wpsProduct, $item);
                
                // Add SKU attribute specifically for variants (required for admin display)
                $this->addSingleAttribute($variantProduct, 'sku', $item->sku);
                
                // Update inventory for variant
                app(WpsInventoryService::class)->updateBagistoInventory(
                    $variantProduct->id,
                    $item->inventory_total
                );
                
                // Link WPS item to variant product
                $item->update(['bagisto_product_id' => $variantProduct->id]);
                
                $variantsCreated++;
                
                Log::channel('wps')->info('Created variant product', [
                    'parent_id' => $parentProduct->id,
                    'variant_id' => $variantProduct->id,
                    'variant_sku' => $item->sku,
                    'wps_item_id' => $item->wps_item_id
                ]);
                
            } catch (\Exception $e) {
                Log::channel('wps')->error('Failed to create variant', [
                    'parent_id' => $parentProduct->id,
                    'wps_item_id' => $item->wps_item_id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        return $variantsCreated;
    }

    /**
     * Generate URL key
     */
    protected function generateUrlKey($name, $productId)
    {
        $urlKey = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        return $urlKey . '-' . $productId;
    }

    /**
     * Add product to default category
     */
    protected function addToDefaultCategory($product)
    {
        try {
            // Use category ID 1 for now (root category)
            DB::table('product_categories')->insert([
                'product_id' => $product->id,
                'category_id' => 1,
            ]);
        } catch (\Exception $e) {
            Log::channel('wps')->error('Failed to add product to category', [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Truncate text to specified length
     */
    protected function truncateText($text, $length)
    {
        if (!$text || strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length - 3) . '...';
    }

    /**
     * Get statistics about products with/without dimensions
     */
    public function getDimensionStats()
    {
        $totalItems = WpsProductItem::dropShipEligible()->count();
        $itemsWithDimensions = WpsProductItem::dropShipEligible()->withDimensions()->count();
        $bagistoProducts = WpsProduct::whereNotNull('bagisto_product_id')->count();

        return [
            'total_eligible_items' => $totalItems,
            'items_with_dimensions' => $itemsWithDimensions,
            'items_without_dimensions' => $totalItems - $itemsWithDimensions,
            'bagisto_products_created' => $bagistoProducts,
            'dimension_coverage_percent' => $totalItems > 0 ? round(($itemsWithDimensions / $totalItems) * 100, 2) : 0
        ];
    }
}
