<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductFlat;
use Illuminate\Support\Facades\DB;

class WarmHomepageCache extends Command
{
    protected $signature = 'cache:warm-homepage';
    
    protected $description = 'Pre-warm homepage cache for instant loading';

    public function handle()
    {
        $this->info('Warming homepage cache...');

        $this->info('Loading categories...');
        Cache::remember('homepage_categories', 1800, function () {
            return $this->getCachedCategories();
        });
        
        $this->info('Loading best sellers...');
        Cache::remember('homepage_bestsellers', 1800, function () {
            return $this->getCachedBestSellers();
        });
        
        $this->info('Loading top rated...');
        Cache::remember('homepage_toprated', 1800, function () {
            return $this->getCachedTopRated();
        });
        
        $this->info('Loading featured products...');
        $cacheKey = 'homepage_featured_' . date('YmdH') . floor(date('i') / 15);
        Cache::remember($cacheKey, 900, function () {
            return $this->getFeaturedProducts();
        });

        $this->info('Homepage cache warmed successfully!');
        
        return 0;
    }

    protected function getCachedCategories()
    {
        try {
            $excludedCategories = ['Spreaders', 'Plows', 'DVDs', 'Mower', 'Displays'];
            $locale = 'en';

            $rootCategory = Category::where('status', 1)
                ->whereNull('parent_id')
                ->first();

            if (!$rootCategory) {
                return collect();
            }

            return Category::where('status', 1)
                ->where('parent_id', $rootCategory->id)
                ->select(['id', 'position', 'status', 'logo_path', 'banner_path', 'parent_id', '_lft', '_rgt'])
                ->with(['translations'])
                ->withCount(['products' => function($query) {
                    $query->where('channel', 'maddparts')->where('locale', 'en');
                }])
                ->orderBy('position')
                ->limit(12)
                ->get()
                ->filter(function ($category) use ($locale, $excludedCategories) {
                    $translation = $category->translate($locale);
                    $categoryName = $translation->name ?? '';

                    foreach ($excludedCategories as $excluded) {
                        if (strcasecmp($categoryName, $excluded) === 0) {
                            return false;
                        }
                    }

                    return $category->products_count > 0;
                });
        } catch (\Exception $e) {
            $this->error('Categories cache error: ' . $e->getMessage());
            return collect();
        }
    }

    protected function getCachedBestSellers()
    {
        try {
            $bestSellerIds = DB::table('order_items')
                ->select('order_items.product_id', DB::raw('SUM(order_items.qty_ordered) as total_sold'))
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', '!=', 'canceled')
                ->where('orders.status', '!=', 'closed')
                ->groupBy('order_items.product_id')
                ->orderBy('total_sold', 'desc')
                ->limit(4)
                ->pluck('product_id');

            if ($bestSellerIds->isEmpty()) {
                return collect();
            }

            return ProductFlat::where('channel', 'maddparts')
                ->where('locale', 'en')
                ->where('status', 1)
                ->whereIn('product_id', $bestSellerIds)
                ->with(['product.images' => function($query) {
                    $query->whereNotNull('path')
                          ->where('path', '!=', '')
                          ->where('path', '!=', 'product-image-placeholder.png')
                          ->limit(1);
                }])
                ->get()
                ->map(function ($product) {
                    $firstImage = $product->product->images->first();
                    $product->image_url = $firstImage ? asset('storage/' . $firstImage->path) : null;
                    return $product;
                });
        } catch (\Exception $e) {
            $this->error('Best sellers cache error: ' . $e->getMessage());
            return collect();
        }
    }

    protected function getCachedTopRated()
    {
        try {
            $topRatedIds = DB::table('product_reviews')
                ->select('product_reviews.product_id', DB::raw('AVG(product_reviews.rating) as avg_rating'), DB::raw('COUNT(product_reviews.id) as review_count'))
                ->where('product_reviews.status', 'approved')
                ->groupBy('product_reviews.product_id')
                ->having('review_count', '>=', 1)
                ->orderBy('avg_rating', 'desc')
                ->orderBy('review_count', 'desc')
                ->limit(4)
                ->pluck('product_id');

            if ($topRatedIds->isEmpty()) {
                return collect();
            }

            return ProductFlat::where('channel', 'maddparts')
                ->where('locale', 'en')
                ->where('status', 1)
                ->whereIn('product_id', $topRatedIds)
                ->with(['product.images' => function($query) {
                    $query->whereNotNull('path')
                          ->where('path', '!=', '')
                          ->where('path', '!=', 'product-image-placeholder.png')
                          ->limit(1);
                }])
                ->get()
                ->map(function ($product) {
                    $firstImage = $product->product->images->first();
                    $product->image_url = $firstImage ? asset('storage/' . $firstImage->path) : null;
                    return $product;
                });
        } catch (\Exception $e) {
            $this->error('Top rated cache error: ' . $e->getMessage());
            return collect();
        }
    }

    protected function getFeaturedProducts()
    {
        try {
            $productIds = DB::table('product_flat as pf')
                ->join('product_images as pi', 'pf.product_id', '=', 'pi.product_id')
                ->where('pf.channel', 'maddparts')
                ->where('pf.locale', 'en')
                ->where('pf.status', 1)
                ->whereNotNull('pi.path')
                ->where('pi.path', '!=', '')
                ->where('pi.path', '!=', 'product-image-placeholder.png')
                ->select('pf.product_id')
                ->distinct()
                ->orderBy('pf.product_id','desc')->limit(200)
                ->limit(12)
                ->pluck('product_id');

            return ProductFlat::where('channel', 'maddparts')
                ->where('locale', 'en')
                ->whereIn('product_id', $productIds)
                ->with(['product.images' => function($query) {
                    $query->whereNotNull('path')
                          ->where('path', '!=', '')
                          ->where('path', '!=', 'product-image-placeholder.png')
                          ->limit(1);
                }])
                ->get()
                ->map(function ($product) {
                    $firstImage = $product->product->images->first();
                    $product->image_url = $firstImage ? asset('storage/' . $firstImage->path) : null;
                    return $product;
                });
        } catch (\Exception $e) {
            $this->error('Featured products cache error: ' . $e->getMessage());
            return collect();
        }
    }
}
