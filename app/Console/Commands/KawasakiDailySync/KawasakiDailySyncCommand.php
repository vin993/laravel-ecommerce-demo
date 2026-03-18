<?php

namespace App\Console\Commands\KawasakiDailySync;

use Illuminate\Console\Command;
use App\Services\KawasakiDailySync\KawasakiDailySyncService;
use Exception;
use Illuminate\Support\Facades\Log;

class KawasakiDailySyncCommand extends Command
{
    protected $signature = 'kawasaki:daily-sync
                            {path? : Path to local XML file (optional, downloads from FTP if not provided)}
                            {--dry-run : Preview changes without applying them}
                            {--limit= : Limit number of products to process (for testing)}
                            {--no-cleanup : Do not delete XML file after sync}';

    protected $description = 'Daily sync of Kawasaki products from FTP (incremental updates only)';

    public function handle()
    {
        $startedAt = now();

        $this->info('Kawasaki Daily Sync - Starting');
        $this->newLine();

        $syncService = new KawasakiDailySyncService();
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit');
        $noCleanup = $this->option('no-cleanup');
        $localPath = $this->argument('path');

        Log::channel('kawasaki_sync')->info('Kawasaki daily sync started', [
            'dry_run' => (bool) $dryRun,
            'limit' => $limit,
            'no_cleanup' => (bool) $noCleanup,
            'path' => $localPath,
            'started_at' => $startedAt->toDateTimeString(),
        ]);

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be saved');
            $this->newLine();
        }

        try {
            if ($localPath) {
                $this->info("Using local XML file: {$localPath}");
                if (!file_exists($localPath)) {
                    throw new Exception("File not found: {$localPath}");
                }
                $xmlPath = $localPath;
                $noCleanup = true;
            } else {
                $this->info('Downloading latest XML from FTP...');
                $xmlPath = $syncService->downloadLatestXml();
                $this->info("Downloaded: {$xmlPath}");
            }
            $this->newLine();

            $this->info('Processing changes...');
            if ($limit) {
                $this->info("   (Limited to {$limit} products for testing)");
            }

            $stats = $syncService->processChanges($xmlPath, [
                'dry_run' => $dryRun,
                'limit' => $limit,
            ]);

            $this->newLine();
            $this->info('Processing complete');
            $this->newLine();

            $this->displayResults($stats);

            if (!$dryRun) {
                $this->info('Grouping new variants...');
                $variantsGrouped = $syncService->groupNewVariants();
                $this->info("Grouped {$variantsGrouped} variant groups");
                $this->newLine();
            }

            if (!$noCleanup && !$dryRun) {
                $this->info('Cleaning up XML file...');
                $syncService->cleanupXmlFile($xmlPath);
                $this->info('Cleanup complete');
                $this->newLine();
            }

            if (!$dryRun) {
                \Cache::forget('kawasaki_filter_data');
                \Cache::forget('kawasaki_products_total_count');
                $this->info('Filter cache cleared');
                $this->newLine();
            }

            Log::channel('kawasaki_sync')->info('Kawasaki daily sync completed', [
                'dry_run' => (bool) $dryRun,
                'stats' => $stats,
                'duration_seconds' => now()->diffInSeconds($startedAt),
            ]);

            $this->info('Daily Sync Completed');
            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            Log::channel('kawasaki_sync')->error('Kawasaki daily sync failed', [
                'dry_run' => (bool) $dryRun,
                'limit' => $limit,
                'no_cleanup' => (bool) $noCleanup,
                'path' => $localPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'duration_seconds' => now()->diffInSeconds($startedAt),
            ]);

            return Command::FAILURE;
        }
    }

    protected function displayResults(array $stats): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products Processed', number_format($stats['processed'])],
                ['New Products Detected', number_format($stats['created'])],
                ['Products Updated', number_format($stats['updated'])],
                ['Products Skipped', number_format($stats['skipped'])],
                ['Prices Changed', number_format($stats['prices_changed'])],
                ['Inventory Updated', number_format($stats['inventory_updated'])],
                ['Images Added', number_format($stats['images_added'])],
            ]
        );

        if ($stats['updated'] > 0 || $stats['prices_changed'] > 0 || $stats['images_added'] > 0) {
            $this->info('Updated existing products with latest data from Kawasaki');
        }

        if ($stats['created'] > 0) {
            $this->warn('Detected ' . number_format($stats['created']) . ' new products in XML (not created - logged only)');
        }
    }
}
