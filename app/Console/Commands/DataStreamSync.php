<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DataStream\FtpService;
use App\Services\DataStream\ArchiveService;
use App\Services\DataStream\CsvParserService;
use App\Services\DataStream\OptimizedStagingTransformerService;
use App\Services\DataStream\FileFilterService;
use App\Models\DataStream\FtpSyncOperation;
use Exception;

class DataStreamSync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'datastream:sync 
                            {--type=incremental : Type of sync (full|incremental|images)}
                            {--force : Force sync even if files haven\'t changed}
                            {--dry-run : Show what would be synced without actually syncing}
                            {--cleanup : Delete downloaded and extracted files after successful sync}
                            {--resume : Resume from last failed operation}
                            {--skip-download : Skip download phase and process existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync DataStream FTP data to local database';

    protected FtpService $ftpService;
    protected ArchiveService $archiveService;
    protected CsvParserService $csvParser;
    protected OptimizedStagingTransformerService $transformer;
    protected FileFilterService $fileFilter;

    public function __construct(
        FtpService $ftpService,
        ArchiveService $archiveService,
        CsvParserService $csvParser,
        OptimizedStagingTransformerService $transformer,
        FileFilterService $fileFilter
    ) {
        parent::__construct();
        $this->ftpService = $ftpService;
        $this->archiveService = $archiveService;
        $this->csvParser = $csvParser;
        $this->transformer = $transformer;
        $this->fileFilter = $fileFilter;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $syncType = $this->option('type');
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        $this->info("Starting DataStream sync - Type: {$syncType}");
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No actual changes will be made');
        }

        $operation = null;
        $downloadedFiles = [];
        $extractedFiles = [];

        try {
            // Check if database tables exist before starting
            $this->validateDatabaseStructure();
            
            // Create sync operation record
            $operation = FtpSyncOperation::create([
                'operation_type' => $syncType,
                'status' => 'started',
                'started_at' => now(),
                'notes' => "Started via command line with options: type={$syncType}, force={$force}, dry-run={$dryRun}"
            ]);

            $this->info("Created sync operation ID: {$operation->id}");
            
            // Set operation ID in session for CSV parser
            session(['current_sync_operation_id' => $operation->id]);

            // Step 1: Connect and list remote files
            $this->info('Step 1: Connecting to FTP server...');
            $this->ftpService->connect();
            $remoteFiles = $this->ftpService->listFiles();
            $this->info("Found " . count($remoteFiles) . " files on FTP server");

            // Step 2: Determine what files to download
            $filesToDownload = $this->determineFilesToDownload($remoteFiles, $syncType, $force);
            
            if (empty($filesToDownload)) {
                $this->info('No files need to be downloaded.');
                $operation->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'notes' => 'No files to download'
                ]);
                return 0;
            }

            $this->info('Found ' . count($filesToDownload) . ' files to download:');
            foreach ($filesToDownload as $file) {
                $this->line('  - ' . $file['name']);
            }
            $this->line('');

            if ($dryRun) {
                $this->info('DRY RUN: Would download ' . count($filesToDownload) . ' files');
                return 0;
            }

            // Step 3: Download files with retry mechanism
            $this->info('Step 2: Downloading files...');
            $downloadedFiles = $this->downloadFilesWithRetry($filesToDownload);

            // Step 4: Extract archives with error handling
            $this->info('Step 3: Extracting archives...');
            $extractedFiles = $this->extractArchivesWithErrorHandling($downloadedFiles);
            $this->info("Extracted " . count($extractedFiles) . " CSV files");

            // Step 5: Apply intelligent file filtering for memory efficiency
            $this->info('Step 4: Applying intelligent file filtering...');
            
            // Add file sizes to extracted files for filtering
            foreach ($extractedFiles as &$file) {
                $file['size'] = file_exists($file['path']) ? filesize($file['path']) : 0;
            }
            
            // Apply intelligent filtering
            $filterResults = $this->fileFilter->filterFiles($extractedFiles, $syncType, true);
            $filteredFiles = $filterResults['filtered'];
            $skippedFiles = $filterResults['skipped'];
            $tooLargeFiles = $filterResults['too_large'];
            
            // Show filtering results
            $this->info("File filtering results:");
            $this->info("  ✅ Files to process: " . count($filteredFiles));
            if (!empty($skippedFiles)) {
                $this->warn("  ⏭️  Skipped files: " . count($skippedFiles));
                foreach ($skippedFiles as $skipped) {
                    $this->line("     - {$skipped['file']} ({$skipped['reason']})");
                }
            }
            if (!empty($tooLargeFiles)) {
                $this->error("  🚫 Files too large: " . count($tooLargeFiles));
                foreach ($tooLargeFiles as $large) {
                    $this->line("     - {$large['file']} ({$large['size']} > {$large['limit']})");
                }
                $this->line('');
                $this->warn("💡 Large files can be processed separately with: php artisan datastream:sync --type=critical");
            }
            $this->line('');
            
            if (empty($filteredFiles)) {
                $this->warn('No files remain after filtering. Consider adjusting sync type or file size limits.');
                $operation->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'notes' => 'No files to process after filtering'
                ]);
                return 0;
            }
            
            // Show processing order
            $this->info('Processing order (critical files first, then by size):');
            foreach (array_slice($filteredFiles, 0, 10) as $i => $file) {
                $fileSizeStr = $this->formatFileSize($file['size']);
                $fileName = basename($file['name']);
                $priority = $file['priority'] ?? 5;
                $category = $file['size_category'] ?? 'unknown';
                $this->line("  " . ($i + 1) . ". {$fileName} ({$fileSizeStr}) [Priority: {$priority}, {$category}]");
            }
            if (count($filteredFiles) > 10) {
                $this->line("  ... and " . (count($filteredFiles) - 10) . " more files");
            }
            $this->line('');

            // Step 6: Parse CSV files with robust error handling
            $this->info('Step 5: Processing filtered CSV files with memory isolation...');
            $processResults = $this->processFilesWithRobustErrorHandling($filteredFiles, $operation);
            
            $totalRecords = $processResults['total_records'];
            $successfulFiles = $processResults['successful_files'];
            $failedFiles = $processResults['failed_files'];
            
            $this->info("✅ Parsed {$totalRecords} records from {$successfulFiles} CSV files to staging tables");
            if ($failedFiles > 0) {
                $this->warn("⚠️  {$failedFiles} files failed to process");
            }

            // Step 7: Transform staging data to DataStream tables with error handling
            $this->info('Step 6: Transforming data to DataStream tables...');
            $transformResults = $this->transformDataWithErrorHandling();
            $totalTransformed = array_sum($transformResults);
            $this->info("Transformation complete: {$totalTransformed} records processed");

            // Update operation status
            $operation->update([
                'status' => $failedFiles > 0 ? 'completed_with_errors' : 'completed',
                'completed_at' => now(),
                'total_records' => $totalRecords,
                'files_downloaded' => count($downloadedFiles),
                'files_processed' => $successfulFiles,
                'files_failed' => $failedFiles
            ]);

            $this->info('✅ DataStream sync completed successfully!');
            
            // Optional cleanup
            if ($this->option('cleanup')) {
                $this->cleanupFiles($downloadedFiles, $extractedFiles);
            }
            
            return $failedFiles > 0 ? 1 : 0;

        } catch (Exception $e) {
            $this->error('❌ Sync failed: ' . $e->getMessage());
            $this->error('📍 Error location: ' . $e->getFile() . ':' . $e->getLine());
            
            if (isset($operation)) {
                $operation->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error_details' => [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => $e->getTraceAsString()
                    ]
                ]);
            }
            
            // Emergency cleanup
            if (!empty($downloadedFiles) || !empty($extractedFiles)) {
                $this->warn('🧹 Performing emergency cleanup...');
                try {
                    $this->cleanupFiles($downloadedFiles, $extractedFiles);
                } catch (Exception $cleanupError) {
                    $this->error('Failed to cleanup files: ' . $cleanupError->getMessage());
                }
            }
            
            return 1;
        } finally {
            // Always cleanup FTP connection
            try {
                $this->ftpService->disconnect();
            } catch (Exception $e) {
                $this->warn('Failed to disconnect FTP: ' . $e->getMessage());
            }
        }
    }

    private function determineFilesToDownload(array $remoteFiles, string $syncType, bool $force): array
    {
        // Implementation depends on sync type
        switch ($syncType) {
            case 'full':
                // Download main file + all recent updates
                return array_filter($remoteFiles, function($file) {
                    return str_contains($file['name'], 'Full.7z') || 
                           str_contains($file['name'], 'Update');
                });
                
            case 'incremental':
                // Download only new update files
                return array_filter($remoteFiles, function($file) {
                    return str_contains($file['name'], 'Update') && 
                           !$this->ftpService->isFileAlreadyProcessed($file);
                });
                
            case 'images':
                // Download brand image archives
                return array_filter($remoteFiles, function($file) {
                    return in_array(pathinfo($file['name'], PATHINFO_DIRNAME), [
                        'Honda', 'Kawasaki', 'Yamaha', 'Polaris', 'SeaDoo'
                    ]);
                });
                
            default:
                return [];
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
    
    private function cleanupFiles(array $downloadedFiles, array $extractedFiles): void
    {
        $this->info('🧹 Cleaning up downloaded and extracted files...');
        
        $deletedFiles = 0;
        $deletedDirs = 0;
        
        // Delete downloaded archive files
        foreach ($downloadedFiles as $filePath) {
            if (file_exists($filePath)) {
                unlink($filePath);
                $deletedFiles++;
            }
        }
        
        // Delete extracted directories
        $extractedDirs = [];
        foreach ($extractedFiles as $csvFile) {
            $extractDir = dirname($csvFile['path']);
            if (!in_array($extractDir, $extractedDirs)) {
                $extractedDirs[] = $extractDir;
            }
        }
        
        foreach ($extractedDirs as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
                $deletedDirs++;
            }
        }
        
        $this->info("✅ Cleanup complete: Deleted {$deletedFiles} files and {$deletedDirs} directories");
    }
    
    private function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
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
    
    /**
     * Order files by size (smallest first) for memory-efficient processing
     */
    private function orderFilesBySize(array $files): array
    {
        usort($files, function($a, $b) {
            $sizeA = file_exists($a['path']) ? filesize($a['path']) : 0;
            $sizeB = file_exists($b['path']) ? filesize($b['path']) : 0;
            return $sizeA <=> $sizeB; // Ascending order (smallest first)
        });
        
        return $files;
    }
    
    /**
     * Process a single file in a separate process for memory isolation
     */
    private function processFileInIsolation(string $filePath, string $tableName, int $operationId): int
    {
        // Use Laravel's Artisan::call to run in separate process
        $command = [
            'datastream:process-single-file',
            'file_path' => $filePath,
            'table_name' => $tableName,
            '--operation-id' => $operationId
        ];
        
        try {
            // For Windows, we need to use the php artisan command directly
            $laravelRoot = base_path();
            $phpPath = 'php'; // Assumes PHP is in PATH
            
            $commandLine = sprintf(
                '%s "%s/artisan" datastream:process-single-file "%s" "%s" --operation-id=%d',
                $phpPath,
                $laravelRoot,
                $filePath,
                $tableName,
                $operationId
            );
            
            // Execute the command and capture the exit code
            $output = [];
            $exitCode = 0;
            
            exec($commandLine . ' 2>&1', $output, $exitCode);
            
            // Log the output for debugging
            if (!empty($output)) {
                $this->line('  Output: ' . implode("\n  ", $output));
            }
            
            return $exitCode;
            
        } catch (Exception $e) {
            $this->error("Failed to execute isolated process: " . $e->getMessage());
            return 1;
        }
    }
    
    /**
     * Validate database structure before starting sync
     */
    private function validateDatabaseStructure(): void
    {
        $this->info('🔍 Validating database structure...');
        
        $requiredTables = [
            'ds_ftp_sync_operations',
            'ari_staging_generic', 
            'ari_staging_partmaster',
            'ari_staging_images',
            'ari_staging_fitment',
            'ari_staging_distributor_inventory',
            'ari_staging_part_price_inv',
            'ds_vehicle_types',
            'ds_makes',
            'ds_models',
            'ds_years',
            'ds_manufacturers',
            'ds_brands',
            'ds_distributors',
            'ds_attributes',
            'ds_groups',
            'ds_categories',
            'ds_applications',
            'ds_products',
            'ds_pricing',
            'ds_inventory',
            'ds_images',
            'ds_fitment',
            'ds_product_attributes',
            'ds_groupings'
        ];
        
        $missingTables = [];
        
        foreach ($requiredTables as $table) {
            try {
                \DB::table($table)->limit(1)->count();
            } catch (Exception $e) {
                $missingTables[] = $table;
            }
        }
        
        if (!empty($missingTables)) {
            $this->error('❌ Missing required database tables:');
            foreach ($missingTables as $table) {
                $this->line('  - ' . $table);
            }
            $this->line('');
            $this->warn('💡 Please run migrations: php artisan migrate');
            throw new Exception('Required database tables are missing. Please run migrations.');
        }
        
        $this->info('✅ Database structure validation passed');
    }
    
    /**
     * Download files with retry mechanism
     */
    private function downloadFilesWithRetry(array $filesToDownload): array
    {
        $downloadedFiles = [];
        $maxRetries = 3;
        
        foreach ($filesToDownload as $index => $file) {
            $this->info("Downloading file " . ($index + 1) . "/" . count($filesToDownload) . ": {$file['name']}");
            
            $retryCount = 0;
            $downloaded = false;
            
            while ($retryCount <= $maxRetries && !$downloaded) {
                try {
                    // Create a progress callback for this file
                    $progressCallback = function($data) {
                        static $progressBar = null;
                        static $totalSize = null;
                        
                        // Update total size if we discover it during download
                        if ($totalSize === null || $totalSize == 0) {
                            $totalSize = $data['total'] ?? 0;
                        }
                        
                        // Initialize progress bar on first call
                        if ($progressBar === null) {
                            if ($totalSize > 0) {
                                // We know the total size, use percentage progress
                                $progressBar = $this->output->createProgressBar(100);
                                $progressBar->setFormat(' %current%% [%bar%] %percent:3s%% - %message%');
                            } else {
                                // Unknown size, use indefinite progress (just show downloaded amount)
                                $progressBar = $this->output->createProgressBar(0);
                                $progressBar->setFormat(' [%bar%] - %message%');
                            }
                            $progressBar->setMessage('Downloading: ' . $data['filename']);
                            $progressBar->start();
                        }
                        
                        if ($progressBar !== null) {
                            $speed = isset($data['speed']) ? $this->formatFileSize($data['speed']) . '/s' : '0 B/s';
                            $downloaded = isset($data['downloaded']) ? $this->formatFileSize($data['downloaded']) : '0 B';
                            
                            if ($totalSize > 0) {
                                // Show progress with known total
                                $total = $this->formatFileSize($totalSize);
                                $percent = (int)$data['progress'];
                                $progressBar->setMessage("Downloading: {$data['filename']} ({$downloaded}/{$total}) - {$speed}");
                                $progressBar->setProgress($percent);
                            } else {
                                // Show progress without known total
                                $progressBar->setMessage("Downloading: {$data['filename']} ({$downloaded}) - {$speed}");
                                $progressBar->advance();
                            }
                            
                            // Finish progress bar when download is complete
                            if (isset($data['completed']) && $data['completed']) {
                                $progressBar->finish();
                                $this->line('');
                                $progressBar = null; // Reset for next file
                                $totalSize = null; // Reset total size
                            }
                        }
                    };
                    
                    $localPath = $this->ftpService->downloadFile($file, $progressCallback);
                    $downloadedFiles[] = $localPath;
                    $downloaded = true;
                    
                    if ($retryCount > 0) {
                        $this->info("✅ Successfully downloaded {$file['name']} on retry #{$retryCount}");
                    }
                    
                } catch (Exception $e) {
                    $retryCount++;
                    
                    if ($retryCount <= $maxRetries) {
                        $this->warn("⚠️ Download failed for {$file['name']}, retrying ({$retryCount}/{$maxRetries})...");
                        sleep(5); // Wait before retry
                        
                        // Reconnect to FTP
                        try {
                            $this->ftpService->disconnect();
                            $this->ftpService->connect();
                        } catch (Exception $reconnectError) {
                            $this->error("Failed to reconnect to FTP: " . $reconnectError->getMessage());
                        }
                    } else {
                        $this->error("❌ Failed to download {$file['name']} after {$maxRetries} retries: " . $e->getMessage());
                        throw $e;
                    }
                }
            }
        }
        
        return $downloadedFiles;
    }
    
    /**
     * Extract archives with error handling
     */
    private function extractArchivesWithErrorHandling(array $downloadedFiles): array
    {
        $extractedFiles = [];
        
        foreach ($downloadedFiles as $archivePath) {
            if (str_ends_with($archivePath, '.7z')) {
                try {
                    $this->info("Extracting: " . basename($archivePath));
                    $extractedPath = $this->archiveService->extractArchive($archivePath);
                    $csvFiles = $this->archiveService->getDataStreamCsvFiles($extractedPath);
                    $extractedFiles = array_merge($extractedFiles, $csvFiles);
                    $this->info("✅ Extracted " . count($csvFiles) . " CSV files from " . basename($archivePath));
                } catch (Exception $e) {
                    $this->error("❌ Failed to extract " . basename($archivePath) . ": " . $e->getMessage());
                    // Continue with other files instead of failing completely
                    continue;
                }
            }
        }
        
        return $extractedFiles;
    }
    
    /**
     * Process files with robust error handling and recovery
     */
    private function processFilesWithRobustErrorHandling(array $filteredFiles, FtpSyncOperation $operation): array
    {
        $totalRecords = 0;
        $successfulFiles = 0;
        $failedFiles = 0;
        $failedFilesList = [];
        
        $csvProgressBar = $this->output->createProgressBar(count($filteredFiles));
        $csvProgressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - Processing: %message%');
        $csvProgressBar->setMessage('Starting...');
        
        foreach ($filteredFiles as $index => $csvFile) {
            $fileName = basename($csvFile['name']);
            $fileSize = file_exists($csvFile['path']) ? filesize($csvFile['path']) : 0;
            $fileSizeStr = $this->formatFileSize($fileSize);
            
            $this->info("Processing file " . ($index + 1) . "/" . count($filteredFiles) . ": {$fileName} ({$fileSizeStr})");
            $csvProgressBar->setMessage($fileName);
            
            $startTime = microtime(true);
            $tableName = $this->csvParser->getTableNameFromFile($csvFile['name']);
            
            // Multiple retry attempts for each file
            $maxFileRetries = 2;
            $fileRetryCount = 0;
            $fileProcessed = false;
            
            while ($fileRetryCount <= $maxFileRetries && !$fileProcessed) {
                try {
                    // Use separate process for memory isolation
                    $exitCode = $this->processFileInIsolation($csvFile['path'], $tableName, $operation->id);
                    
                    if ($exitCode === 0) {
                        // Get record count from staging table
                        $records = $this->csvParser->getStagingTableRowCount($tableName);
                        $totalRecords += $records;
                        $successfulFiles++;
                        $fileProcessed = true;
                        
                        $endTime = microtime(true);
                        $duration = round($endTime - $startTime, 2);
                        
                        $this->info("✅ Processed {$fileName}: {$records} records in {$duration}s");
                        
                        if ($fileRetryCount > 0) {
                            $this->info("✅ File processed successfully on retry #{$fileRetryCount}");
                        }
                    } else {
                        throw new Exception("Process exited with code {$exitCode}");
                    }
                    
                } catch (Exception $e) {
                    $fileRetryCount++;
                    
                    if ($fileRetryCount <= $maxFileRetries) {
                        $this->warn("⚠️ File processing failed for {$fileName}, retrying ({$fileRetryCount}/{$maxFileRetries}): " . $e->getMessage());
                        
                        // Clear any partial data from staging table
                        try {
                            \DB::table($tableName)
                                ->where('sync_operation_id', $operation->id)
                                ->whereNull('processed_at')
                                ->delete();
                        } catch (Exception $cleanupError) {
                            $this->warn("Failed to cleanup partial data: " . $cleanupError->getMessage());
                        }
                        
                        // Force garbage collection
                        gc_collect_cycles();
                        sleep(2); // Brief pause before retry
                    } else {
                        $failedFiles++;
                        $failedFilesList[] = $fileName;
                        $this->error("❌ Failed to process {$fileName} after {$maxFileRetries} retries: " . $e->getMessage());
                        
                        // Log the failure but continue with other files
                        \Log::error("DataStream file processing failed: {$fileName}", [
                            'file_path' => $csvFile['path'],
                            'table_name' => $tableName,
                            'operation_id' => $operation->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            }
            
            $csvProgressBar->advance();
            
            // Light cleanup after each file
            gc_collect_cycles();
            
            $currentMemory = $this->formatFileSize(memory_get_usage(true));
            $this->info("  Main process memory: {$currentMemory}");
        }
        
        $csvProgressBar->setMessage('Complete!');
        $csvProgressBar->finish();
        $this->line('');
        
        // Log failed files if any
        if (!empty($failedFilesList)) {
            $this->warn('📝 Failed files list:');
            foreach ($failedFilesList as $failedFile) {
                $this->line('  - ' . $failedFile);
            }
        }
        
        return [
            'total_records' => $totalRecords,
            'successful_files' => $successfulFiles,
            'failed_files' => $failedFiles,
            'failed_files_list' => $failedFilesList
        ];
    }
    
    /**
     * Transform staging data with error handling
     */
    private function transformDataWithErrorHandling(): array
    {
        $results = [];
        
        try {
            // Check staging data exists before transformation
            $stagingTables = [
                'ari_staging_generic',
                'ari_staging_partmaster',
                'ari_staging_images',
                'ari_staging_fitment',
                'ari_staging_distributor_inventory',
                'ari_staging_part_price_inv'
            ];
            
            $totalStagingRecords = 0;
            foreach ($stagingTables as $table) {
                try {
                    $count = \DB::table($table)->whereNull('processed_at')->count();
                    $totalStagingRecords += $count;
                    if ($count > 0) {
                        $this->info("📊 {$table}: {$count} unprocessed records");
                    }
                } catch (Exception $e) {
                    $this->warn("⚠️ Could not check {$table}: " . $e->getMessage());
                }
            }
            
            if ($totalStagingRecords === 0) {
                $this->warn('No unprocessed staging data found for transformation');
                return [];
            }
            
            $this->info("📈 Total staging records to transform: {$totalStagingRecords}");
            
            // Run transformation with try-catch for each step
            $results = $this->transformer->transformAllStagingData();
            
            return $results;
            
        } catch (Exception $e) {
            $this->error('❌ Transformation failed: ' . $e->getMessage());
            $this->error('📍 Error location: ' . $e->getFile() . ':' . $e->getLine());
            
            // Log detailed error for debugging
            \Log::error('DataStream transformation failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}
