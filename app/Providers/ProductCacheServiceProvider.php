<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Observers\ProductCacheObserver;
use App\Observers\CategoryCacheObserver;
use Webkul\Product\Models\Product;
use Webkul\Category\Models\Category;

/**
 * Service Provider for Product Count Cache
 *
 * Registers observers to handle cache invalidation
 */
class ProductCacheServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register observers
        Product::observe(ProductCacheObserver::class);
        Category::observe(CategoryCacheObserver::class);

        // Register pivot event listeners if needed
        // This depends on your Bagisto version and how pivot events are dispatched
        // Example:
        // Event::listen('product.categories.attached', [ProductCategoryPivotListener::class, 'handle']);
        // Event::listen('product.categories.detached', [ProductCategoryPivotListener::class, 'handle']);
    }
}
