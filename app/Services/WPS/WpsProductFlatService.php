<?php

namespace App\Services\WPS;

use App\Models\WPS\WpsProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WpsProductFlatService
{
    protected $defaultChannel = 'default';
    protected $defaultLocale = 'en';

    /**
     * Populate product_flat table for WPS products with enhanced data
     */
    public function populateProductFlat($limit = 100)
    {
        Log::channel('wps')->info('Starting enhanced product flat population', ['limit' => $limit]);
        
        $wpsProducts = WpsProduct::whereNotNull('bagisto_product_id')
            ->limit($limit)
            ->get();

        $populated = 0;
        $withDimensions = 0;
        $errors = 0;

        foreach ($wpsProducts as $wpsProduct) {
            try {
                $result = $this->populateSingleProduct($wpsProduct);
                $populated++;
                
                if ($result['has_dimensions']) {
                    $withDimensions++;
                }
                
                Log::channel('wps')->info('Populated product flat', [
                    'wps_product_id' => $wpsProduct->wps_product_id,
                    'bagisto_product_id' => $wpsProduct->bagisto_product_id,
                    'has_dimensions' => $result['has_dimensions']
                ]);
                
            } catch (\Exception $e) {
                $errors++;
                Log::channel('wps')->error('Failed to populate product flat', [
                    'wps_product_id' => $wpsProduct->wps_product_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::channel('wps')->info('Enhanced product flat population completed', [
            'populated' => $populated,
            'with_dimensions' => $withDimensions,
            'errors' => $errors
        ]);

        return [
            'populated' => $populated, 
            'with_dimensions' => $withDimensions, 
            'errors' => $errors
        ];
    }

    /**
     * Populate product_flat for a single product with enhanced data
     */
    protected function populateSingleProduct($wpsProduct)
    {
        $productId = $wpsProduct->bagisto_product_id;
        
        // Check if already exists
        $existing = DB::table('product_flat')
            ->where('product_id', $productId)
            ->where('channel', $this->defaultChannel)
            ->where('locale', $this->defaultLocale)
            ->first();
            
        if ($existing) {
            // Update existing record instead of skipping
            $this->updateExistingProductFlat($productId, $wpsProduct);
        } else {
            // Create new record
            $this->createNewProductFlat($productId, $wpsProduct);
        }

        // Check if product has dimensions
        $attributes = $this->getProductAttributes($productId);
        $hasDimensions = !empty($attributes['weight']) && 
                        !empty($attributes['length']) && 
                        !empty($attributes['width']) && 
                        !empty($attributes['height']);

        return ['has_dimensions' => $hasDimensions];
    }

    /**
     * Create new product flat record
     */
    protected function createNewProductFlat($productId, $wpsProduct)
    {
        // Get product data
        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product) {
            throw new \Exception("Product not found: {$productId}");
        }

        // Get all attribute values
        $attributes = $this->getProductAttributes($productId);

        // Get WPS item for additional data
        $wpsItem = $wpsProduct->items()->dropShipEligible()->first();

        // Insert into product_flat
        DB::table('product_flat')->insert($this->buildProductFlatData($product, $attributes, $wpsItem));
    }

    /**
     * Update existing product flat record
     */
    protected function updateExistingProductFlat($productId, $wpsProduct)
    {
        // Get product data
        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product) {
            throw new \Exception("Product not found: {$productId}");
        }

        // Get all attribute values
        $attributes = $this->getProductAttributes($productId);

        // Get WPS item for additional data
        $wpsItem = $wpsProduct->items()->dropShipEligible()->first();

        // Update product_flat
        DB::table('product_flat')
            ->where('product_id', $productId)
            ->where('channel', $this->defaultChannel)
            ->where('locale', $this->defaultLocale)
            ->update(array_merge(
                $this->buildProductFlatData($product, $attributes, $wpsItem),
                ['updated_at' => now()]
            ));
    }

    /**
     * Build product flat data array
     */
    protected function buildProductFlatData($product, $attributes, $wpsItem)
    {
        $effectivePrice = $wpsItem ? $wpsItem->getEffectivePrice() : ($attributes['price'] ?? 0);
        
        return [
            'sku' => $product->sku,
            'type' => $product->type,
            'product_number' => $product->sku,
            'name' => $attributes['name'] ?? 'Unnamed Product',
            'short_description' => $attributes['short_description'] ?? '',
            'description' => $attributes['description'] ?? '',
            'url_key' => $attributes['url_key'] ?? $this->generateUrlKey($product->sku),
            
            // Product status flags
            'new' => (bool)($attributes['new'] ?? ($wpsItem->is_new ?? false)),
            'featured' => (bool)($attributes['featured'] ?? ($wpsItem->is_featured ?? false)),
            'status' => (bool)($attributes['status'] ?? ($wpsItem->is_available ?? true)),
            'visible_individually' => (bool)($attributes['visible_individually'] ?? true),
            
            // SEO fields
            'meta_title' => $attributes['meta_title'] ?? $attributes['name'] ?? 'Product',
            'meta_keywords' => $attributes['meta_keywords'] ?? '',
            'meta_description' => $attributes['meta_description'] ?? '',
            
            // Pricing
            'price' => (float) $effectivePrice,
            'special_price' => $this->getSpecialPrice($attributes, $wpsItem),
            'special_price_from' => $attributes['special_price_from'] ?? ($wpsItem->special_price_from ?? null),
            'special_price_to' => $attributes['special_price_to'] ?? ($wpsItem->special_price_to ?? null),
            
            // Physical dimensions - Critical for frontend display
            'weight' => (float)($attributes['weight'] ?? ($wpsItem->weight ?? 1)),
            'length' => (float)($attributes['length'] ?? ($wpsItem->length ?? null)),
            'width' => (float)($attributes['width'] ?? ($wpsItem->width ?? null)),
            'height' => (float)($attributes['height'] ?? ($wpsItem->height ?? null)),
            
            // System fields
            'created_at' => $product->created_at ?? now(),
            'updated_at' => now(),
            'locale' => $this->defaultLocale,
            'channel' => $this->defaultChannel,
            'attribute_family_id' => $product->attribute_family_id,
            'product_id' => $product->id,
            'parent_id' => $product->parent_id,
        ];
    }

    /**
     * Get special price with proper validation
     */
    protected function getSpecialPrice($attributes, $wpsItem)
    {
        // Check if special price is active
        if ($wpsItem && $wpsItem->special_price && $wpsItem->isSpecialPriceActive()) {
            return (float) $wpsItem->special_price;
        }
        
        // Fallback to attribute value
        $specialPrice = (float)($attributes['special_price'] ?? 0);
        return $specialPrice > 0 ? $specialPrice : null;
    }

    /**
     * Get all attribute values for a product
     */
    protected function getProductAttributes($productId)
    {
        $attributeValues = DB::table('product_attribute_values')
            ->join('attributes', 'product_attribute_values.attribute_id', '=', 'attributes.id')
            ->where('product_attribute_values.product_id', $productId)
            ->where('product_attribute_values.channel', $this->defaultChannel)
            ->where('product_attribute_values.locale', $this->defaultLocale)
            ->select(
                'attributes.code',
                'product_attribute_values.text_value',
                'product_attribute_values.boolean_value',
                'product_attribute_values.float_value',
                'product_attribute_values.integer_value',
                'product_attribute_values.date_value',
                'product_attribute_values.datetime_value'
            )
            ->get();

        $attributes = [];
        
        foreach ($attributeValues as $attr) {
            $value = $attr->text_value 
                ?? $attr->boolean_value 
                ?? $attr->float_value 
                ?? $attr->integer_value 
                ?? $attr->date_value 
                ?? $attr->datetime_value;
                
            $attributes[$attr->code] = $value;
        }

        return $attributes;
    }

    /**
     * Generate URL key from SKU
     */
    protected function generateUrlKey($sku)
    {
        return strtolower(str_replace(['/', '-', ' '], '-', $sku));
    }

    /**
     * Clear product flat data for WPS products
     */
    public function clearProductFlat()
    {
        $productIds = WpsProduct::whereNotNull('bagisto_product_id')
            ->pluck('bagisto_product_id');

        $deleted = DB::table('product_flat')
            ->whereIn('product_id', $productIds)
            ->delete();

        Log::channel('wps')->info('Cleared product flat data', ['deleted' => $deleted]);
        
        return $deleted;
    }

    /**
     * Get statistics about product flat data
     */
    public function getProductFlatStats()
    {
        $wpsProductIds = WpsProduct::whereNotNull('bagisto_product_id')
            ->pluck('bagisto_product_id');

        $totalWpsProducts = $wpsProductIds->count();
        
        $productFlatRecords = DB::table('product_flat')
            ->whereIn('product_id', $wpsProductIds)
            ->count();

        $productsWithDimensions = DB::table('product_flat')
            ->whereIn('product_id', $wpsProductIds)
            ->whereNotNull('length')
            ->whereNotNull('width')
            ->whereNotNull('height')
            ->where('weight', '>', 0)
            ->count();

        return [
            'total_wps_products' => $totalWpsProducts,
            'product_flat_records' => $productFlatRecords,
            'products_with_dimensions' => $productsWithDimensions,
            'missing_flat_records' => $totalWpsProducts - $productFlatRecords,
            'missing_dimensions' => $productFlatRecords - $productsWithDimensions
        ];
    }

    /**
     * Fix products missing dimensions in flat table
     */
    public function fixMissingDimensions($limit = 100)
    {
        Log::channel('wps')->info('Fixing missing dimensions in product flat', ['limit' => $limit]);

        $wpsProductIds = WpsProduct::whereNotNull('bagisto_product_id')
            ->pluck('bagisto_product_id');

        // Find products in flat table missing dimensions
        $productsNeedingFix = DB::table('product_flat')
            ->whereIn('product_id', $wpsProductIds)
            ->where(function($query) {
                $query->whereNull('length')
                      ->orWhereNull('width')
                      ->orWhereNull('height')
                      ->orWhere('weight', '<=', 0);
            })
            ->limit($limit)
            ->pluck('product_id');

        $fixed = 0;

        foreach ($productsNeedingFix as $productId) {
            try {
                $wpsProduct = WpsProduct::where('bagisto_product_id', $productId)->first();
                if ($wpsProduct) {
                    $this->updateExistingProductFlat($productId, $wpsProduct);
                    $fixed++;
                }
            } catch (\Exception $e) {
                Log::channel('wps')->error('Failed to fix product dimensions', [
                    'product_id' => $productId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::channel('wps')->info('Finished fixing missing dimensions', ['fixed' => $fixed]);
        
        return $fixed;
    }
}