<?php

namespace App\Services\DataStream;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MicroBatchService
{
    private static $staticTimestamp = null;
    private static $staticSyncOperationId = null;
    private int $connectionResetCounter = 0;
    
    public function __construct()
    {
        // Initialize static values once
        if (self::$staticTimestamp === null) {
            self::$staticTimestamp = date('Y-m-d H:i:s');
        }
        
        if (self::$staticSyncOperationId === null) {
            self::$staticSyncOperationId = session('current_sync_operation_id', 1);
        }
    }

    /**
     * Ultra-lightweight batch insert without any JSON storage
     */
    public function insertMicroBatch(string $tableName, array $batch, int $dbResetInterval = 50): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            $records = [];
            
            foreach ($batch as $row) {
                $record = $this->buildMinimalRecord($tableName, $row);
                $records[] = $record;
            }
            
            // Direct SQL insert to minimize memory usage
            DB::table($tableName)->insert($records);
            
            // Immediate memory cleanup
            unset($records, $batch);
            
            // Connection management
            $this->connectionResetCounter++;
            if ($this->connectionResetCounter >= $dbResetInterval) {
                $this->resetDatabaseConnection();
                $this->connectionResetCounter = 0;
            }
            
            // Safe garbage collection with error handling
            try {
                if (!$this->isMemoryUsageHigh(90)) {
                    gc_collect_cycles();
                }
            } catch (Exception $e) {
                Log::warning("Garbage collection failed in insertMicroBatch: " . $e->getMessage());
            }
            
        } catch (Exception $e) {
            Log::error("Micro-batch insert failed", [
                'table' => $tableName,
                'batch_size' => count($batch),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Build minimal record structure without JSON storage
     */
    private function buildMinimalRecord(string $tableName, array $row): array
    {
        $record = [
            'processed' => 0,
            'created_at' => self::$staticTimestamp,
            'updated_at' => self::$staticTimestamp,
            'raw_data' => json_encode($row) // Store actual CSV row data
        ];
        
        if (str_starts_with($tableName, 'ari_staging_')) {
            $record['sync_operation_id'] = self::$staticSyncOperationId;
            $record['ari_id'] = $row['id'] ?? 'NA';
            
            // Add only essential mapped fields based on table
            $record = array_merge($record, $this->mapEssentialFieldsForTable($row, $tableName));
        }
        
        return $record;
    }
    
    /**
     * Map only essential fields for specific table types to reduce memory
     */
    private function mapEssentialFieldsForTable(array $row, string $tableName): array
    {
        $mapped = [];
        
        switch ($tableName) {
            case 'ari_staging_partmaster':
                $mapped['manufacturer_id'] = $row['manufacturer_id'] ?? 'NA';
                $mapped['manufacturer_number_short'] = $this->limitString($row['manufacturer_number_short'] ?? 'NA', 100);
                $mapped['manufacturer_number_long'] = $this->limitString($row['manufacturer_number_long'] ?? 'NA', 100);
                $mapped['item_name'] = $this->limitString($row['item_name'] ?? 'NA', 200);
                $mapped['item_description'] = $row['item_description'] ?? 'NA';
                $mapped['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? 'NA';
                break;
                
            case 'ari_staging_images':
                $mapped['partmaster_id'] = $row['partmaster_id'] ?? 'NA';
                $mapped['hi_res_image_name'] = $this->limitString($row['hi_res_image_name'] ?? 'NA', 200);
                $mapped['date_modified'] = $row['date_modified'] ?? 'NA';
                $mapped['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? 'NA';
                break;
                
            case 'ari_staging_fitment':
                $mapped['tmmy_id'] = $row['tmmy_id'] ?? 'NA';
                $mapped['part_to_app_combo_id'] = $row['part_to_app_combo_id'] ?? 'NA';
                $mapped['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? 'NA';
                break;
                
            case 'ari_staging_distributor_inventory':
                $mapped['part_price_inv_id'] = $row['partpriceinvid'] ?? $row['part_price_inv_id'] ?? 'NA';
                $mapped['qty'] = $row['qty'] ?? 'NA';
                $mapped['distributor_warehouse_id'] = $row['distributor_warehouse_id'] ?? 'NA';
                $mapped['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? 'NA';
                break;
                
            case 'ari_staging_part_price_inv':
                $mapped['distributor_id'] = $row['distributor_id'] ?? 'NA';
                $mapped['distributor_part_number_short'] = $this->limitString($row['distributor_part_number_short'] ?? 'NA', 100);
                $mapped['distributor_part_number_long'] = $this->limitString($row['distributor_part_number_long'] ?? 'NA', 100);
                $mapped['partmaster_id'] = $row['partmaster_id'] ?? 'NA';
                $mapped['msrp'] = $row['msrp'] ?? 'NA';
                $mapped['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? 'NA';
                break;
                
            case 'ari_staging_generic':
                $mapped['entity_name'] = $this->getEntityNameFromData($row);
                break;
        }
        
        return $mapped;
    }
    
    /**
     * Limit string length to prevent database errors
     */
    private function limitString(?string $value, int $maxLength): string
    {
        if ($value === null || $value === '') {
            return 'NA';
        }
        
        return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
    }
    
    /**
     * Get entity name from data structure - enhanced for DataStream format
     */
    private function getEntityNameFromData(array $row): string
    {
        // Get the fields available in the row
        $fields = array_keys($row);
        $fieldsLower = array_map('strtolower', $fields);
        $fieldString = implode('|', $fieldsLower);
        
        // For DataStream format, use field pattern analysis
        // Makes, Models, Years, Brands etc. typically have: id, description, UpdateFlag
        if (count($fieldsLower) <= 3 && in_array('id', $fieldsLower) && in_array('description', $fieldsLower)) {
            // For simple reference tables, return 'makes' as default
            // This will be improved later with filename context
            return 'makes';
        }
        
        // Original field-based detection for other formats
        if (isset($row['make_name']) || isset($row['makename'])) {
            return 'makes';
        } elseif (isset($row['model_name']) || isset($row['modelname'])) {
            return 'models';
        } elseif (isset($row['year_value']) || isset($row['year'])) {
            return 'years';
        } elseif (isset($row['brand_name']) || isset($row['brandname'])) {
            return 'brands';
        } elseif (isset($row['manufacturer_name']) || isset($row['manufacturername'])) {
            return 'manufacturers';
        } elseif (isset($row['distributor_name']) || isset($row['distributorname'])) {
            return 'distributors';
        } elseif (isset($row['attribute_name']) || isset($row['attributename'])) {
            return 'attributes';
        } elseif (isset($row['group_name']) || isset($row['groupname'])) {
            return 'groups';
        } elseif (isset($row['category_name']) || isset($row['categoryname'])) {
            return 'categories';
        } elseif (isset($row['application_name']) || isset($row['applicationname'])) {
            return 'applications';
        }
        
        return 'unknown';
    }
    
    /**
     * Determine entity type from field context for DataStream reference tables
     */
    private function determineEntityFromContext(array $fieldsLower): string
    {
        // This is a temporary solution - ideally we'd pass filename context
        // For now, return 'reference' and handle in the command
        return 'reference';
    }
    
    /**
     * Reset database connection to prevent memory buildup
     */
    private function resetDatabaseConnection(): void
    {
        try {
            DB::disconnect();
            DB::reconnect();
            Log::debug("Database connection reset successfully");
        } catch (Exception $e) {
            Log::warning("Failed to reset database connection: " . $e->getMessage());
        }
    }
    
    /**
     * Get optimal batch size based on file size and memory constraints
     */
    public function getOptimalBatchSize(int $fileSize): int
    {
        // Extremely conservative batch sizes to prevent memory exhaustion
        if ($fileSize > 300 * 1024 * 1024) {        // > 300MB
            return 1;   // Single record processing for massive files
        } elseif ($fileSize > 200 * 1024 * 1024) {  // > 200MB
            return 2;   // Tiny batches for very large files
        } elseif ($fileSize > 100 * 1024 * 1024) {  // > 100MB
            return 5;   // Small batches for large files
        } elseif ($fileSize > 50 * 1024 * 1024) {   // > 50MB
            return 10;  // Medium batches
        } else {
            return 25; // Larger batches for smaller files
        }
    }
    
    /**
     * Force aggressive memory cleanup with safety checks
     */
    public function forceMemoryCleanup(): void
    {
        try {
            // Check current memory before cleanup
            $beforeCleanup = memory_get_usage(true);
            
            // Safer garbage collection with exception handling
            for ($i = 0; $i < 2; $i++) {
                try {
                    gc_collect_cycles();
                } catch (Exception $e) {
                    Log::warning("Garbage collection failed: " . $e->getMessage());
                    break; // Stop trying if GC fails
                }
            }
            
            // Force database cleanup - disconnect all connections
            try {
                DB::disconnect();
                // Clear Laravel's connection resolver cache
                DB::purge();
                // Reconnect with fresh connection
                DB::reconnect();
            } catch (Exception $e) {
                Log::warning("Database cleanup failed: " . $e->getMessage());
            }
            
            // Clear OPcache if available
            if (function_exists('opcache_reset')) {
                try {
                    opcache_reset();
                } catch (Exception $e) {
                    Log::warning("OPcache reset failed: " . $e->getMessage());
                }
            }
            
            // Reset memory peak tracking
            memory_get_peak_usage(true);
            
            $afterCleanup = memory_get_usage(true);
            $memoryFreed = $beforeCleanup - $afterCleanup;
            
            if ($memoryFreed > 0) {
                Log::info("Memory cleanup freed: " . number_format($memoryFreed / 1024 / 1024, 2) . "MB");
            }
            
        } catch (Exception $e) {
            Log::error("Memory cleanup failed: " . $e->getMessage());
            // Emergency memory cleanup attempt
            try {
                DB::disconnect();
                gc_collect_cycles();
            } catch (Exception $emergencyError) {
                Log::critical("Emergency memory cleanup failed: " . $emergencyError->getMessage());
            }
        }
    }
    
    /**
     * Check if memory usage is approaching limits
     */
    public function isMemoryUsageHigh(float $thresholdPercent = 80.0): bool
    {
        $memoryLimit = $this->getMemoryLimitInBytes();
        $currentUsage = memory_get_usage(true);
        
        if ($memoryLimit <= 0) {
            return false; // Can't determine limit
        }
        
        $usagePercent = ($currentUsage / $memoryLimit) * 100;
        return $usagePercent >= $thresholdPercent;
    }
    
    /**
     * Get memory limit in bytes
     */
    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit == -1) {
            return -1; // No limit
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        switch ($unit) {
            case 'g':
                return $value * 1024 * 1024 * 1024;
            case 'm':
                return $value * 1024 * 1024;
            case 'k':
                return $value * 1024;
            default:
                return $value;
        }
    }
    
    /**
     * Insert single record immediately without batching to minimize memory usage
     */
    public function insertSingleRecord(string $tableName, array $row): void
    {
        if (empty($row)) {
            return;
        }

        try {
            $record = $this->buildMinimalRecord($tableName, $row);
            
            // Direct single-record insert
            DB::table($tableName)->insert($record);
            
            // Immediate cleanup
            unset($record, $row);
            
            // Connection management
            $this->connectionResetCounter++;
            if ($this->connectionResetCounter >= 100) { // Reset less frequently for single records
                $this->resetDatabaseConnection();
                $this->connectionResetCounter = 0;
                
                // Safe garbage collection
                try {
                    if (!$this->isMemoryUsageHigh(90)) {
                        gc_collect_cycles();
                    }
                } catch (Exception $e) {
                    Log::warning("Garbage collection failed in insertSingleRecord: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            Log::error("Single record insert failed", [
                'table' => $tableName,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Check if we should use single-record processing based on memory usage
     */
    public function shouldUseSingleRecordMode(): bool
    {
        $currentMemory = memory_get_usage(true);
        $memoryMB = $currentMemory / (1024 * 1024);
        
        // Switch to single-record mode if memory usage exceeds 400MB (more conservative)
        return $memoryMB > 400;
    }
    
    /**
     * Memory circuit breaker - stop processing if memory is critically high
     */
    public function shouldTriggerMemoryCircuitBreaker(): bool
    {
        $currentMemory = memory_get_usage(true);
        $memoryMB = $currentMemory / (1024 * 1024);
        
        // Circuit breaker at 450MB to prevent hitting 512MB limit
        return $memoryMB > 450;
    }
    
    /**
     * Get detailed memory statistics
     */
    public function getMemoryStats(): array
    {
        $currentMemory = memory_get_usage(true);
        $peakMemory = memory_get_peak_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();
        
        $currentMB = round($currentMemory / 1024 / 1024, 2);
        $peakMB = round($peakMemory / 1024 / 1024, 2);
        $limitMB = $memoryLimit > 0 ? round($memoryLimit / 1024 / 1024, 2) : -1;
        
        $usagePercent = $memoryLimit > 0 ? round(($currentMemory / $memoryLimit) * 100, 2) : 0;
        
        return [
            'current_mb' => $currentMB,
            'peak_mb' => $peakMB,
            'limit_mb' => $limitMB,
            'usage_percent' => $usagePercent,
            'is_high' => $this->isMemoryUsageHigh(80),
            'should_circuit_break' => $this->shouldTriggerMemoryCircuitBreaker()
        ];
    }
    
    /**
     * Process memory emergency shutdown
     */
    public function emergencyMemoryShutdown(string $context = ""): void
    {
        $stats = $this->getMemoryStats();
        
        Log::critical("Emergency memory shutdown triggered", [
            'context' => $context,
            'memory_stats' => $stats,
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ]);
        
        // Emergency cleanup
        try {
            DB::disconnect();
            DB::purge();
        } catch (Exception $e) {
            Log::error("Emergency DB cleanup failed: " . $e->getMessage());
        }
        
        // Force exit with error code
        exit(139); // Same error code as segfault to indicate memory issue
    }
}
