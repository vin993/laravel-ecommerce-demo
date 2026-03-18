<?php

namespace App\Observers;

use App\Services\ProductCountCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\Category\Models\Category;

/**
 * Observer to invalidate category count caches when categories change
 */
class CategoryCacheObserver
{
    protected ProductCountCacheService $cacheService;

    public function __construct(ProductCountCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle the Category "saved" event.
     */
    public function saved(Category $category): void
    {
        $this->invalidateCategoryCaches($category);
    }

    /**
     * Handle the Category "deleted" event.
     */
    public function deleted(Category $category): void
    {
        $this->invalidateCategoryCaches($category);
    }

    /**
     * Invalidate caches related to a category
     */
    protected function invalidateCategoryCaches(Category $category): void
    {
        try {
            // Invalidate count caches for this category
            $this->cacheService->invalidateCategoryCount($category->id);

            Log::info('CategoryCacheObserver: Invalidated caches for category', [
                'category_id' => $category->id
            ]);

        } catch (\Exception $e) {
            // Don't fail the main operation if cache invalidation fails
            Log::error('CategoryCacheObserver: Cache invalidation failed', [
                'category_id' => $category->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
