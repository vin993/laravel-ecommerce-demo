<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DataStream\CsvParserService;
use App\Services\DataStream\MicroBatchService;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DataStreamProcessSingleFile extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'datastream:process-single-file 
                            {file_path : Path to the CSV file to process}
                            {table_name : Target staging table name}
                            {--operation-id= : Sync operation ID}';

    /**
     * The console command description.
     */
    protected $description = 'Process a single DataStream CSV file with memory isolation';

    protected CsvParserService $csvParser;
    protected MicroBatchService $microBatchService;

    public function __construct(
        CsvParserService $csvParser,
        MicroBatchService $microBatchService = null
    ) {
        parent::__construct();
        $this->csvParser = $csvParser;
        $this->microBatchService = $microBatchService ?? new MicroBatchService();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = $this->argument('file_path');
        $tableName = $this->argument('table_name');
        $operationId = $this->option('operation-id');

        // Set conservative memory limits to prevent crashes
        ini_set('memory_limit', '512M'); // Much more conservative limit
        ini_set('max_execution_time', 0);
        
        // Log initial memory state
        $initialStats = $this->microBatchService->getMemoryStats();
        $this->info("🔧 Initial memory: {$initialStats['current_mb']}MB (Limit: {$initialStats['limit_mb']}MB)");

        // Set operation ID in session
        if ($operationId) {
            session(['current_sync_operation_id' => $operationId]);
        }

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $fileName = basename($filePath);
        $fileSize = filesize($filePath);
        $fileSizeStr = $this->formatFileSize($fileSize);

        $this->info("🔄 Processing: {$fileName} ({$fileSizeStr}) → {$tableName}");

        try {
            $startTime = microtime(true);
            
            // Clear the staging table for this file type
            $this->csvParser->clearStagingTable($tableName);
            
            // Monitor memory before processing
            $preProcessStats = $this->microBatchService->getMemoryStats();
            if ($preProcessStats['should_circuit_break']) {
                $this->error("⚠️ Memory usage too high before processing starts");
                $this->microBatchService->emergencyMemoryShutdown("Pre-processing check");
            }
            
            // Choose parser based on file size to prevent memory exhaustion
            if ($fileSize > 300 * 1024 * 1024) { // > 300MB files
                $this->info("🧠 Using zero-memory parser for large file ({$fileSizeStr})");
                $result = $this->csvParser->parseZeroMemoryCsvFile($filePath, $tableName);
            } else {
                $this->info("⚡ Using ultra-light parser for file ({$fileSizeStr})");
                $result = $this->csvParser->parseUltraLightCsvFile($filePath, $tableName);
            }
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            $records = $result['total_records'] ?? 0;

            // Enhanced memory and performance stats
            $finalStats = $this->microBatchService->getMemoryStats();
            $strategy = $fileSize > 300 * 1024 * 1024 ? 'zero-memory' : 'ultra-light';

            $this->info("✅ Completed: {$fileName}");
            $this->info("   Strategy: {$strategy}");
            $this->info("   Records: {$records}");
            $this->info("   Duration: {$duration}s");
            $this->info("   Memory: {$finalStats['current_mb']}MB (Peak: {$finalStats['peak_mb']}MB)");
            $this->info("   Usage: {$finalStats['usage_percent']}% of limit");
            
            // Warn if memory usage was high
            if ($finalStats['is_high']) {
                $this->warn("⚠️ High memory usage detected during processing");
            }

            // Enhanced final cleanup with monitoring
            $beforeCleanup = $this->microBatchService->getMemoryStats();
            $this->forceMemoryCleanup();
            $afterCleanup = $this->microBatchService->getMemoryStats();
            
            $memoryFreed = $beforeCleanup['current_mb'] - $afterCleanup['current_mb'];
            if ($memoryFreed > 0) {
                $this->info("🧹 Memory cleanup freed: {$memoryFreed}MB");
            }

            return 0;

        } catch (Exception $e) {
            $errorStats = $this->microBatchService->getMemoryStats();
            
            $this->error("❌ Failed to process {$fileName}: " . $e->getMessage());
            $this->error("   Memory at failure: {$errorStats['current_mb']}MB ({$errorStats['usage_percent']}%)");
            
            Log::error("DataStream single file processing failed", [
                'file' => $filePath,
                'table' => $tableName,
                'error' => $e->getMessage(),
                'memory_stats' => $errorStats,
                'strategy' => $fileSize > 300 * 1024 * 1024 ? 'zero-memory' : 'ultra-light'
            ]);
            
            // Emergency cleanup before exit
            try {
                $this->microBatchService->forceMemoryCleanup();
            } catch (Exception $cleanupError) {
                $this->error("Cleanup also failed: " . $cleanupError->getMessage());
            }
            
            return 1;
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

    private function forceMemoryCleanup(): void
    {
        // Ultra-aggressive cleanup for process isolation
        for ($i = 0; $i < 3; $i++) {
            gc_collect_cycles();
            DB::disconnect();
            if ($i < 2) { // Don't reconnect on final iteration
                DB::reconnect();
            }
        }

        // Clear all Laravel caches
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Reset memory peak tracking
        memory_get_peak_usage(true);
    }
}
