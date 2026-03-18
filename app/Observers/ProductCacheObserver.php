<?php

namespace App\Observers;

use App\Services\ProductCountCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Models\Product;

/**
 * Observer to invalidate product count caches when products change
 */
class ProductCacheObserver
{
    protected ProductCountCacheService $cacheService;

    public function __construct(ProductCountCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle the Product "saved" event.
     */
    public function saved(Product $product): void
    {
        $this->invalidateProductCaches($product);
    }

    /**
     * Handle the Product "deleted" event.
     */
    public function deleted(Product $product): void
    {
        $this->invalidateProductCaches($product);
    }

    /**
     * Invalidate caches related to a product
     */
    protected function invalidateProductCaches(Product $product): void
    {
        try {
            // Invalidate vehicle count caches for this product
            $this->cacheService->invalidateVehicleCounts($product->id);

            // Invalidate category count caches for categories this product belongs to
            if ($product->categories) {
                foreach ($product->categories as $category) {
                    $this->cacheService->invalidateCategoryCount($category->id);
                }
            }

            // Use pattern-based invalidation for this product
            // This catches all vehicle/category combinations
            $this->invalidateByProductPattern($product->id);

            Log::info('ProductCacheObserver: Invalidated caches for product', [
                'product_id' => $product->id,
                'sku' => $product->sku ?? 'unknown'
            ]);

        } catch (\Exception $e) {
            // Don't fail the main operation if cache invalidation fails
            Log::error('ProductCacheObserver: Cache invalidation failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate caches by product pattern
     * This is a targeted invalidation that runs quickly
     */
    protected function invalidateByProductPattern(int $productId): void
    {
        // For now, we rely on TTL expiry
        // For immediate invalidation, you could scan for specific patterns
        // but this should be done asynchronously via a job

        // Example: Queue a job to invalidate specific patterns
        // dispatch(new InvalidateProductCacheJob($productId));
    }
}
