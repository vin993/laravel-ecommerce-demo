<?php

namespace App\Console\Commands\AutomatedSync;

use App\Services\AutomatedSync\AutomatedFtpSyncService;
use App\Services\AutomatedSync\UpdateDetectionService;
use App\Services\AutomatedSync\CleanupService;
use Illuminate\Console\Command;

class RunAutomatedFtpSync extends Command
{
    protected $signature = 'sync:automated-ftp-sync
                            {--dry-run : Show what would be synced without actually syncing}
                            {--force : Force sync even if no new files detected}
                            {--skip-images : Skip image synchronization}
                            {--skip-notification : Skip email notifications}';

    protected $description = 'Run automated FTP sync to download and process update files';

    private AutomatedFtpSyncService $syncService;
    private UpdateDetectionService $detectionService;
    private CleanupService $cleanupService;

    public function __construct(
        AutomatedFtpSyncService $syncService,
        UpdateDetectionService $detectionService,
        CleanupService $cleanupService
    ) {
        parent::__construct();
        $this->syncService = $syncService;
        $this->detectionService = $detectionService;
        $this->cleanupService = $cleanupService;
    }

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $skipImages = $this->option('skip-images');

        $this->info('===========================================');
        $this->info('  Automated FTP Sync Starting');
        $this->info('===========================================');

        $this->info('Options:');
        $this->line('  Dry Run: ' . ($dryRun ? 'Yes' : 'No'));
        $this->line('  Force: ' . ($force ? 'Yes' : 'No'));
        $this->line('  Skip Images: ' . ($skipImages ? 'Yes' : 'No'));
        $this->line('');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - Detecting files without processing');
            $this->line('');
            return $this->runDryRun();
        }

        $this->displayStorageStats();

        try {
            $result = $this->syncService->runSync($skipImages, $force);

            $this->line('');
            $this->info('===========================================');
            $this->info('  Sync Completed');
            $this->info('===========================================');

            $this->displayResults($result);

            $this->cleanupOldFiles();

            return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Sync failed: ' . $e->getMessage());
            $this->error('Location: ' . $e->getFile() . ':' . $e->getLine());
            return Command::FAILURE;
        }
    }

    private function displayStorageStats(): void
    {
        $stats = $this->cleanupService->getStorageStats();

        $this->info('Storage Status:');
        $this->line('  Downloads: ' . $stats['downloads_size_mb'] . ' MB');
        $this->line('  Extracted: ' . $stats['extracted_size_mb'] . ' MB');
        $this->line('  Disk Free: ' . $stats['disk_free_gb'] . ' GB / ' . $stats['disk_total_gb'] . ' GB');
        $this->line('  Disk Usage: ' . $stats['disk_usage_percent'] . '%');
        $this->line('');

        if ($stats['disk_free_gb'] < 10) {
            $this->warn('WARNING: Low disk space (less than 10GB free)');
        }
    }

    private function displayResults(array $result): void
    {
        $this->info('Status: ' . strtoupper($result['status']));
        $this->line('Sync Log ID: ' . $result['sync_log_id']);

        if ($result['status'] === 'no_updates') {
            $this->line($result['message']);
            return;
        }

        if ($result['status'] === 'failed') {
            $this->error('Error: ' . $result['error']);
            return;
        }

        $this->line('');
        $this->info('Files Processed: ' . ($result['files_processed'] ?? 0));

        if (isset($result['stats'])) {
            $this->line('');
            $this->info('Statistics:');
            $this->table(
                ['Metric', 'Count'],
                [
                    ['New Products Created', number_format($result['stats']['created'])],
                    ['Products Updated', number_format($result['stats']['updated'])],
                    ['Categories Synced', $result['stats']['categories']],
                    ['Brands Synced', $result['stats']['brands']],
                    ['Images Synced', $result['stats']['images']],
                ]
            );
        }
    }

    private function cleanupOldFiles(): void
    {
        $cleanupDays = (int) env('AUTOMATED_SYNC_CLEANUP_AFTER_DAYS', 7);

        $this->info('');
        $this->info('Cleaning up old files (older than ' . $cleanupDays . ' days)...');

        $this->cleanupService->cleanupOldExtractedFolders($cleanupDays);
        $this->cleanupService->cleanupOldDownloads($cleanupDays);

        $this->info('Cleanup completed');
    }

    private function runDryRun(): int
    {
        try {
            $this->info('Connecting to FTP server...');
            $newFiles = $this->detectionService->detectNewUpdateFiles();

            if (empty($newFiles)) {
                $this->info('No new update files detected on FTP server');
                $this->line('');
                $this->info('All update files are already processed');
                return Command::SUCCESS;
            }

            $this->info('Found ' . count($newFiles) . ' new update files to process:');
            $this->line('');

            $updateFiles = [];
            $imageFiles = [];

            foreach ($newFiles as $file) {
                if (isset($file['brand'])) {
                    $imageFiles[] = $file;
                } else {
                    $updateFiles[] = $file;
                }
            }

            if (!empty($updateFiles)) {
                $this->info('UPDATE FILES (Product Data):');
                $this->line('Location: FTP root directory /');
                $this->line('');

                $tableData = [];
                foreach ($updateFiles as $file) {
                    $tableData[] = [
                        $file['name'],
                        $this->formatFileSize($file['size']),
                        $file['path'],
                    ];
                }
                $this->table(['Filename', 'Size', 'FTP Path'], $tableData);
                $this->line('');
            }

            if (!empty($imageFiles)) {
                $this->info('IMAGE FILES (Brand Images):');
                $this->line('');

                $byBrand = [];
                foreach ($imageFiles as $file) {
                    $brand = $file['brand'];
                    if (!isset($byBrand[$brand])) {
                        $byBrand[$brand] = [];
                    }
                    $byBrand[$brand][] = $file;
                }

                foreach ($byBrand as $brand => $files) {
                    $this->line('Brand: ' . $brand);
                    $this->line('Location: FTP /' . $brand . '/');

                    $tableData = [];
                    foreach ($files as $file) {
                        $tableData[] = [
                            $file['name'],
                            $this->formatFileSize($file['size']),
                        ];
                    }
                    $this->table(['Filename', 'Size'], $tableData);
                    $this->line('');
                }
            }

            $this->info('ACTIONS THAT WILL BE PERFORMED:');
            $this->line('');
            $this->line('1. Download all ' . count($newFiles) . ' .7z files from FTP');
            $this->line('2. Extract .7z archives using 7-Zip');

            if (!empty($updateFiles)) {
                $this->line('3. Process product data from update files:');
                foreach ($updateFiles as $file) {
                    $folderName = pathinfo($file['name'], PATHINFO_FILENAME);
                    $this->line('   - ' . $folderName);
                    $this->line('     * Create new products');
                    $this->line('     * Update existing products');
                    $this->line('     * Sync product attributes');
                    $this->line('     * Map categories');
                    $this->line('     * Assign brands');
                }
            }

            if (!empty($imageFiles)) {
                $this->line('4. Process brand images:');
                foreach ($byBrand as $brand => $files) {
                    $this->line('   - ' . $brand . ' (' . count($files) . ' files)');
                }
            }

            $this->line('5. Mark files as processed in database');
            $this->line('6. Send email notification to: ' . env('AUTOMATED_SYNC_EMAIL'));
            $this->line('7. Cleanup downloaded archives');

            $this->line('');
            $this->info('To execute sync, run without --dry-run flag:');
            $this->line('  sudo -u www-data php artisan sync:automated-ftp-sync');

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Dry run failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
