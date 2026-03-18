<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\DataStream\FtpService;
use App\Services\DataStream\ArchiveService;
use App\Services\DataStream\StagingTransformerService;
use Exception;

class DataStreamCleanup extends Command
{
    protected $signature = 'datastream:cleanup 
                            {--force : Skip confirmation prompts}
                            {--files-only : Only cleanup files, keep database data}
                            {--db-only : Only cleanup database, keep files}';

    protected $description = 'Clean up all DataStream data, files, and reset for fresh sync';

    protected FtpService $ftpService;
    protected ArchiveService $archiveService;
    protected StagingTransformerService $transformer;

    public function __construct(
        FtpService $ftpService,
        ArchiveService $archiveService,
        StagingTransformerService $transformer
    ) {
        parent::__construct();
        $this->ftpService = $ftpService;
        $this->archiveService = $archiveService;
        $this->transformer = $transformer;
    }

    public function handle()
    {
        $force = $this->option('force');
        $filesOnly = $this->option('files-only');
        $dbOnly = $this->option('db-only');

        $this->warn('🧹 DataStream Cleanup Tool');
        $this->line('This will clean up:');
        
        if (!$dbOnly) {
            $this->line('  • Downloaded archive files (.7z)');
            $this->line('  • Extracted CSV files');
            $this->line('  • Temporary extraction directories');
        }
        
        if (!$filesOnly) {
            $this->line('  • All staging table data');
            $this->line('  • All DataStream main table data');
            $this->line('  • All sync operation logs');
            $this->line('  • All file tracking records');
        }

        if (!$force) {
            $this->error('⚠️  WARNING: This will permanently delete all DataStream data!');
            if (!$this->confirm('Are you sure you want to continue?')) {
                $this->info('Cleanup cancelled.');
                return 0;
            }
        }

        try {
            $totalCleaned = 0;

            // 1. Cleanup Files
            if (!$dbOnly) {
                $this->info('Step 1: Cleaning up files...');
                $filesCleaned = $this->cleanupFiles();
                $totalCleaned += $filesCleaned;
                $this->info("✅ Cleaned up {$filesCleaned} files and directories");
            }

            // 2. Cleanup Database Tables
            if (!$filesOnly) {
                $this->info('Step 2: Cleaning up database tables...');
                $recordsCleaned = $this->cleanupDatabase();
                $totalCleaned += $recordsCleaned;
                $this->info("✅ Cleaned up {$recordsCleaned} database records");
            }

            // 3. Reset Auto-increment IDs
            if (!$filesOnly) {
                $this->info('Step 3: Resetting table auto-increment counters...');
                $this->resetAutoIncrements();
                $this->info('✅ Reset all table counters');
            }

            $this->info("🎉 Cleanup complete! Total items cleaned: {$totalCleaned}");
            $this->line('');
            $this->info('You can now run a fresh sync:');
            $this->comment('php artisan datastream:sync --type=full --cleanup');

            return 0;

        } catch (Exception $e) {
            $this->error('❌ Cleanup failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function cleanupFiles(): int
    {
        $cleaned = 0;
        $storagePath = $this->ftpService->getLocalStoragePath();
        $extractPath = $this->archiveService->getExtractBasePath();
        
        $this->line("Cleaning FTP storage: {$storagePath}");
        $this->line("Cleaning extraction path: {$extractPath}");

        // Clean FTP storage
        if (is_dir($storagePath)) {
            $items = scandir($storagePath);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                $itemPath = $storagePath . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    $this->removeDirectory($itemPath);
                    $this->comment("Removed directory: {$item}");
                } else {
                    unlink($itemPath);
                    $this->comment("Removed file: {$item}");
                }
                $cleaned++;
            }
        }

        // Clean extraction path
        if (is_dir($extractPath)) {
            $items = scandir($extractPath);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                
                $itemPath = $extractPath . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    $this->removeDirectory($itemPath);
                    $this->comment("Removed extraction: {$item}");
                    $cleaned++;
                }
            }
        }

        return $cleaned;
    }

    private function cleanupDatabase(): int
    {
        $totalRecords = 0;
        
        // Use the existing transformer service to clear DS tables
        $this->transformer->clearAllDataStreamTables();
        
        // Clear staging tables
        $stagingTables = [
            'ari_staging_image_downloads',
            'ari_staging_generic', 
            'ari_staging_part_price_inv',
            'ari_staging_distributor_inventory',
            'ari_staging_fitment',
            'ari_staging_images',
            'ari_staging_partmaster',
        ];
        
        foreach ($stagingTables as $table) {
            try {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $count = DB::table($table)->count();
                    if ($count > 0) {
                        DB::table($table)->truncate();
                        $totalRecords += $count;
                        $this->comment("Cleared {$table}: {$count} records");
                    }
                }
            } catch (Exception $e) {
                $this->warn("Could not clear table {$table}: " . $e->getMessage());
            }
        }

        // Clear sync operation tables
        $syncTables = ['ds_ftp_file_trackings', 'ds_ftp_sync_operations', 'ari_ftp_file_tracking', 'ari_ftp_sync_operations'];
        foreach ($syncTables as $table) {
            try {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $count = DB::table($table)->count();
                    if ($count > 0) {
                        DB::table($table)->truncate();
                        $totalRecords += $count;
                        $this->comment("Cleared {$table}: {$count} records");
                    }
                }
            } catch (Exception $e) {
                $this->warn("Could not clear table {$table}: " . $e->getMessage());
            }
        }

        return $totalRecords;
    }

    private function resetAutoIncrements(): void
    {
        $tables = [
            'ds_ftp_sync_operations',
            'ari_ftp_sync_operations', 
            'ari_staging_partmaster',
            'ari_staging_images',
            'ari_staging_fitment',
            'ari_staging_distributor_inventory', 
            'ari_staging_part_price_inv',
            'ari_staging_generic',
            'ds_brands',
            'ds_manufacturers',
            'ds_partmaster',
            'ds_part_price_inv'
        ];

        foreach ($tables as $table) {
            try {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    DB::statement("ALTER TABLE {$table} AUTO_INCREMENT = 1");
                }
            } catch (Exception $e) {
                $this->comment("Could not reset auto-increment for {$table}: " . $e->getMessage());
            }
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) return;

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_dir($filePath)) {
                $this->removeDirectory($filePath);
            } else {
                unlink($filePath);
            }
        }
        rmdir($path);
    }
}
