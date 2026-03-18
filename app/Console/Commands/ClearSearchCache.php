<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearSearchCache extends Command
{
    protected $signature = 'cache:clear-search';

    protected $description = 'Clear all search-related caches including filters and results';

    public function handle()
    {
        $this->info('Clearing all search-related caches...');

        $patterns = [
            'search_results_*',
            'search_filters_*',
            'brand_products_*',
            'products_page_categories',
            'trending_products',
            'search_categories',
        ];

        $cleared = 0;

        foreach ($patterns as $pattern) {
            if (strpos($pattern, '*') !== false) {
                $prefix = str_replace('*', '', $pattern);
                $keys = $this->getKeysWithPrefix($prefix);

                foreach ($keys as $key) {
                    Cache::forget($key);
                    $cleared++;
                }

                $this->line("Cleared {$prefix} pattern caches");
            } else {
                Cache::forget($pattern);
                $cleared++;
                $this->line("Cleared {$pattern} cache");
            }
        }

        Cache::flush();

        $this->info("Cache clearing completed. Total keys cleared: {$cleared}");
        $this->info('All application cache has been flushed.');

        return 0;
    }

    private function getKeysWithPrefix($prefix)
    {
        $keys = [];

        try {
            $store = Cache::getStore();

            if (method_exists($store, 'getRedis')) {
                $redis = $store->getRedis();
                $keys = $redis->keys($prefix . '*');
            } elseif (method_exists($store, 'getMemcached')) {
                $this->warn('Memcached does not support key pattern matching. Using flush instead.');
            }
        } catch (\Exception $e) {
            $this->warn('Could not retrieve cache keys: ' . $e->getMessage());
        }

        return $keys;
    }
}
