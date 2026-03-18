<?php

namespace App\Listeners;

use App\Services\ProductCountCacheService;
use Illuminate\Support\Facades\Log;

/**
 * Listener for product-category pivot changes
 *
 * This handles cache invalidation when product-category relationships change
 */
class ProductCategoryPivotListener
{
    protected ProductCountCacheService $cacheService;

    public function __construct(ProductCountCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle product-category pivot attached event
     */
    public function handle($event): void
    {
        try {
            // Extract product and category IDs from the event
            // The exact structure depends on your Bagisto version
            // Typically: $event->product, $event->categories

            if (method_exists($event, 'getProduct')) {
                $product = $event->getProduct();
                $this->invalidateForProduct($product);
            }

            Log::info('ProductCategoryPivotListener: Invalidated caches for pivot change');

        } catch (\Exception $e) {
            Log::error('ProductCategoryPivotListener: Cache invalidation failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Invalidate caches for a product
     */
    protected function invalidateForProduct($product): void
    {
        $this->cacheService->invalidateVehicleCounts($product->id);

        if ($product->categories) {
            foreach ($product->categories as $category) {
                $this->cacheService->invalidateCategoryCount($category->id);
            }
        }
    }
}
