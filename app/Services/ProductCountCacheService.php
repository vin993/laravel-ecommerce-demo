<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service for caching product count queries with Redis
 *
 * Handles cache key generation and invalidation for expensive
 * COUNT(*) queries on product_flat table
 */
class ProductCountCacheService
{
    /**
     * Default TTL for count caches (5 minutes)
     */
    protected int $defaultTtl;

    public function __construct()
    {
        $this->defaultTtl = (int) env('CATEGORY_COUNT_CACHE_TTL', 300);
    }

    /**
     * Generate a stable cache key for vehicle fitment count queries
     *
     * @param array $params [vehicleType, vehicleMake, vehicleModel, vehicleYear, productIds, brands, categories, minPrice, maxPrice, inStock, sortBy]
     * @return string
     */
    public function generateVehicleCountKey(array $params): string
    {
        $productIdsHash = !empty($params['productIds'])
            ? md5(json_encode(array_values($params['productIds'])))
            : 'none';

        $filtersHash = md5(json_encode([
            'brands' => $params['brands'] ?? [],
            'categories' => $params['categories'] ?? [],
            'minPrice' => $params['minPrice'] ?? null,
            'maxPrice' => $params['maxPrice'] ?? null,
            'inStock' => $params['inStock'] ?? null,
        ]));

        return sprintf(
            'vehicle_count:%s:%s:%s:%s:%s:%s',
            $params['vehicleType'] ?? 'none',
            $params['vehicleMake'] ?? 'none',
            $params['vehicleModel'] ?? 'none',
            $params['vehicleYear'] ?? 'none',
            $productIdsHash,
            $filtersHash
        );
    }

    /**
     * Generate cache key for category product count
     *
     * @param int $categoryId
     * @param array $productIds
     * @param string $channel
     * @param string $locale
     * @return string
     */
    public function generateCategoryCountKey(int $categoryId, array $productIds, string $channel = 'maddparts', string $locale = 'en'): string
    {
        $productIdsHash = md5(json_encode(array_values($productIds)));

        return sprintf(
            'category_count:%d:%s:%s:%s',
            $categoryId,
            $channel,
            $locale,
            $productIdsHash
        );
    }

    /**
     * Cache a count query result
     *
     * @param string $cacheKey
     * @param callable $callback
     * @param int|null $ttl
     * @return mixed
     */
    public function remember(string $cacheKey, callable $callback, ?int $ttl = null)
    {
        $ttl = $ttl ?? $this->defaultTtl;

        // Explicitly use Redis driver to avoid config cache issues
        return Cache::driver('redis')->remember($cacheKey, $ttl, function() use ($callback) {
            return $callback();
        });
    }

    /**
     * Invalidate vehicle fitment count caches
     * Call this when products, categories, or vehicle fitments change
     *
     * @param int|null $productId Optional specific product ID
     * @return void
     */
    public function invalidateVehicleCounts(?int $productId = null): void
    {
        // Note: We use targeted invalidation by product/category changes
        // For bulk operations, see invalidateAllCounts() method

        if ($productId) {
            Log::info("ProductCountCache: Invalidation triggered for product", [
                'product_id' => $productId
            ]);
        }

        // Individual keys will be invalidated via model observers
        // This method is here for documentation and future expansion
    }

    /**
     * Invalidate category count caches for a specific category
     *
     * @param int $categoryId
     * @return void
     */
    public function invalidateCategoryCount(int $categoryId): void
    {
        // Use Redis SCAN to find and delete keys matching pattern
        // Only call during low-load periods or in background jobs

        $pattern = "category_count:{$categoryId}:*";

        Log::info("ProductCountCache: Invalidating category counts", [
            'category_id' => $categoryId,
            'pattern' => $pattern
        ]);

        // Note: Direct pattern deletion should be done via artisan command
        // to avoid blocking Redis during high traffic
    }

    /**
     * Flush all count caches (use only during maintenance or bulk imports)
     *
     * IMPORTANT: Run this via artisan command, not during web requests
     *
     * @param string $pattern
     * @return int Number of keys deleted
     */
    public function flushByPattern(string $pattern = 'category_count:*'): int
    {
        $deleted = 0;

        try {
            // This uses SCAN internally, safe for production
            $redis = Cache::driver('redis')->getRedis();
            $iterator = null;

            do {
                $keys = $redis->scan($iterator, [
                    'MATCH' => $pattern,
                    'COUNT' => 100
                ]);

                if ($keys !== false && count($keys) > 0) {
                    foreach ($keys as $key) {
                        Cache::driver('redis')->forget($key);
                        $deleted++;
                    }
                }
            } while ($iterator > 0);

            Log::info("ProductCountCache: Flushed caches", [
                'pattern' => $pattern,
                'deleted' => $deleted
            ]);

        } catch (\Exception $e) {
            Log::error("ProductCountCache: Flush failed", [
                'pattern' => $pattern,
                'error' => $e->getMessage()
            ]);
        }

        return $deleted;
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        try {
            $redis = Cache::driver('redis')->getRedis();
            $dbSize = $redis->dbSize();

            // Count category_count keys (safe with SCAN)
            $iterator = null;
            $countKeys = 0;

            do {
                $keys = $redis->scan($iterator, [
                    'MATCH' => 'category_count:*',
                    'COUNT' => 100
                ]);

                if ($keys !== false) {
                    $countKeys += count($keys);
                }
            } while ($iterator > 0);

            return [
                'total_keys' => $dbSize,
                'category_count_keys' => $countKeys,
                'ttl' => $this->defaultTtl
            ];

        } catch (\Exception $e) {
            return [
                'error' => $e->getMessage()
            ];
        }
    }
}
