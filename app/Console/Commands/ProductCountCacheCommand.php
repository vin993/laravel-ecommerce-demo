<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ProductCountCacheService;

/**
 * Artisan command to manage product count caches
 */
class ProductCountCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:product-counts {action : Action to perform (flush|stats|warm)}
                            {--pattern=category_count:* : Pattern to flush (default: category_count:*)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage product count caches (flush, stats, warm)';

    /**
     * Execute the console command.
     */
    public function handle(ProductCountCacheService $cacheService): int
    {
        $action = $this->argument('action');

        switch ($action) {
            case 'flush':
                return $this->flushCaches($cacheService);

            case 'stats':
                return $this->showStats($cacheService);

            case 'warm':
                $this->warn('Cache warming should be done via the warm-cache.sh script');
                return 0;

            default:
                $this->error("Invalid action: {$action}. Use flush, stats, or warm.");
                return 1;
        }
    }

    /**
     * Flush caches by pattern
     */
    protected function flushCaches(ProductCountCacheService $cacheService): int
    {
        $pattern = $this->option('pattern');

        $this->info("Flushing caches matching pattern: {$pattern}");

        if (!$this->confirm('This will delete all matching cache keys. Continue?', true)) {
            $this->info('Aborted.');
            return 0;
        }

        $deleted = $cacheService->flushByPattern($pattern);

        $this->info("Flushed {$deleted} cache keys.");

        return 0;
    }

    /**
     * Show cache statistics
     */
    protected function showStats(ProductCountCacheService $cacheService): int
    {
        $this->info('Product Count Cache Statistics');
        $this->line('');

        $stats = $cacheService->getStats();

        if (isset($stats['error'])) {
            $this->error('Failed to get stats: ' . $stats['error']);
            return 1;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Redis Keys', $stats['total_keys']],
                ['Category Count Keys', $stats['category_count_keys']],
                ['Cache TTL (seconds)', $stats['ttl']],
            ]
        );

        return 0;
    }
}
