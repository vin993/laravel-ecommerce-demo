<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearHomepageCache extends Command
{
    protected $signature = 'cache:clear-homepage';
    
    protected $description = 'Clear homepage cache (categories, bestsellers, toprated, featured)';

    public function handle()
    {
        $keys = [
            'homepage_categories',
            'homepage_bestsellers',
            'homepage_toprated'
        ];

        foreach ($keys as $key) {
            Cache::forget($key);
        }

        $featuredPattern = 'homepage_featured_*';
        $allKeys = Cache::getRedis()->keys($featuredPattern);
        foreach ($allKeys as $key) {
            Cache::forget(str_replace(config('cache.prefix') . ':', '', $key));
        }

        $this->info('Homepage cache cleared successfully.');
        $this->info('Cleared keys: ' . implode(', ', $keys));
        $this->info('Featured products cache cleared (all timestamps).');
        
        return 0;
    }
}
