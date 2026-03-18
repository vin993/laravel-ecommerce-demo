<?php

namespace App\Services\DataStream;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CsvParserService
{
    private array $headerMappings = [];
    private MicroBatchService $microBatchService;

    public function __construct(MicroBatchService $microBatchService = null)
    {
        // Define header normalization mappings
        $this->initializeHeaderMappings();
        $this->microBatchService = $microBatchService ?? new MicroBatchService();
    }

    public function parseCsvFile(string $filePath, string $tableName): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found: {$filePath}");
        }

        Log::info("Parsing CSV file: {$filePath} for table: {$tableName}");
        $fileSize = file_exists($filePath) ? filesize($filePath) : 0;
        Log::info("File size: " . number_format($fileSize) . " bytes");

        // Set aggressive memory limits for large files
        ini_set('memory_limit', '8G');
        ini_set('max_execution_time', 0);
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Failed to open CSV file: {$filePath}");
        }

        $headers = [];
        $lineNumber = 0;
        $totalProcessed = 0;
        $batchSize = $this->calculateOptimalBatchSize($fileSize);
        $memoryCheckInterval = 10; // Much more frequent memory checks
        $batchCount = 0;
        $maxMemoryUsage = 0;

        try {
            // Auto-detect delimiter by reading first line
            $firstLine = fgets($handle);
            rewind($handle);
            
            $delimiter = "\t"; // default to tab
            if (strpos($firstLine, ',') !== false && strpos($firstLine, '"') !== false) {
                $delimiter = ","; // switch to comma if we see quoted comma-delimited data
            }
            
            Log::info("Using delimiter: " . ($delimiter === "\t" ? 'TAB' : 'COMMA') . " and batch size: {$batchSize} for file: {$filePath}");
            
            // Process line by line to minimize memory usage
            $currentBatch = [];
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($lineNumber === 1) {
                    // Process headers
                    $headers = $this->normalizeHeaders($row, $tableName);
                    continue;
                }

                if (empty($headers)) {
                    Log::warning("No headers found in CSV file: {$filePath}");
                    break;
                }

                // Create minimal associative array - only store essential fields
                $rowData = $this->createMinimalRowData($row, $headers, $tableName);
                
                $currentBatch[] = $rowData;

                // Process in smaller batches more frequently
                if (count($currentBatch) >= $batchSize) {
                    $this->insertBatchDirectly($tableName, $currentBatch);
                    $totalProcessed += count($currentBatch);
                    $batchCount++;
                    
                    // More aggressive memory management
                    if ($batchCount % $memoryCheckInterval == 0) {
                        $memoryUsage = $this->formatBytes(memory_get_usage(true));
                        $peakMemory = $this->formatBytes(memory_get_peak_usage(true));
                        Log::info("Processed {$totalProcessed} rows from {$filePath}. Memory: {$memoryUsage}, Peak: {$peakMemory}");
                        
                        // Ultra-aggressive memory management
                        gc_collect_cycles();
                        
                        // Reset database connection every 1000 batches to prevent memory buildup
                        if ($batchCount % 100 == 0) {
                            DB::disconnect();
                            DB::reconnect();
                            Log::info("Database connection reset at batch {$batchCount}");
                        }
                        
                        // Emergency memory check - force cleanup if memory exceeds 3GB
                        $currentMemoryBytes = memory_get_usage(true);
                        if ($currentMemoryBytes > 3 * 1024 * 1024 * 1024) {
                            Log::warning("Memory usage exceeded 3GB, forcing aggressive cleanup");
                            gc_collect_cycles();
                            DB::disconnect();
                            DB::reconnect();
                        }
                    }
                    
                    // Completely clear and recreate array to free memory
                    unset($currentBatch);
                    $currentBatch = [];
                }
            }

            // Process remaining batch
            if (!empty($currentBatch)) {
                $this->insertBatchDirectly($tableName, $currentBatch);
                $totalProcessed += count($currentBatch);
                Log::info("Processed final batch - {$totalProcessed} total rows from {$filePath}");
                unset($currentBatch);
            }

        } finally {
            fclose($handle);
            gc_collect_cycles(); // Final garbage collection
        }

        $finalMemory = $this->formatBytes(memory_get_usage(true));
        $peakMemory = $this->formatBytes(memory_get_peak_usage(true));
        Log::info("✅ Successfully parsed {$totalProcessed} records from CSV file: {$filePath} to table {$tableName}. Final Memory: {$finalMemory}, Peak: {$peakMemory}");
        
        // Return summary instead of all data to save memory
        return [
            'total_records' => $totalProcessed,
            'table_name' => $tableName,
            'file_path' => $filePath
        ];
    }

    public function getFileHeaders(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Failed to open CSV file: {$filePath}");
        }

        $headers = [];
        
        // Auto-detect delimiter by reading first line
        $firstLine = fgets($handle);
        rewind($handle);
        
        $delimiter = "\t"; // default to tab
        if (strpos($firstLine, ',') !== false && strpos($firstLine, '"') !== false) {
            $delimiter = ","; // switch to comma if we see quoted comma-delimited data
        }
        
        if (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $headers = array_map('trim', $row);
        }

        fclose($handle);
        return $headers;
    }

    public function previewCsvData(string $filePath, int $rowCount = 5): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found: {$filePath}");
        }

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Failed to open CSV file: {$filePath}");
        }

        $data = [];
        $headers = [];
        $lineNumber = 0;

        try {
            // Auto-detect delimiter by reading first line
            $firstLine = fgets($handle);
            rewind($handle);
            
            $delimiter = "\t"; // default to tab
            if (strpos($firstLine, ',') !== false && strpos($firstLine, '"') !== false) {
                $delimiter = ","; // switch to comma if we see quoted comma-delimited data
            }
            
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false && $lineNumber <= $rowCount) {
                $lineNumber++;

                if ($lineNumber === 1) {
                    $headers = array_map('trim', $row);
                    continue;
                }

                $rowData = [];
                foreach ($headers as $index => $header) {
                    $value = isset($row[$index]) ? trim($row[$index]) : null;
                    $rowData[$header] = $value === '' ? null : $value;
                }

                $data[] = $rowData;
            }
        } finally {
            fclose($handle);
        }

        return [
            'headers' => $headers,
            'data' => $data,
            'total_preview_rows' => count($data)
        ];
    }


    private function normalizeHeaders(array $headers, string $tableName): array
    {
        $normalized = [];
        
        foreach ($headers as $header) {
            $originalHeader = trim($header);
            $normalizedHeader = $this->normalizeHeaderName($originalHeader, $tableName);
            $normalized[] = $normalizedHeader;
        }

        return $normalized;
    }

    private function normalizeHeaderName(string $header, string $tableName): string
    {
        // Remove extra spaces and convert to lowercase
        $normalized = strtolower(trim($header));
        
        // Apply table-specific mappings
        $tableKey = strtolower(basename($tableName, '_staging'));
        if (isset($this->headerMappings[$tableKey])) {
            foreach ($this->headerMappings[$tableKey] as $pattern => $replacement) {
                if ($normalized === strtolower($pattern)) {
                    return $replacement;
                }
            }
        }

        // Apply global mappings
        if (isset($this->headerMappings['global'])) {
            foreach ($this->headerMappings['global'] as $pattern => $replacement) {
                if ($normalized === strtolower($pattern)) {
                    return $replacement;
                }
            }
        }

        // Default normalization
        return str_replace([' ', '-'], '_', $normalized);
    }

    private function initializeHeaderMappings(): void
    {
        $this->headerMappings = [
            'global' => [
                'id' => 'id',
                'Id' => 'id',
                'ID' => 'id',
                'partid' => 'part_id',
                'PartId' => 'part_id',
                'PartID' => 'part_id',
                'groupid' => 'group_id',
                'GroupId' => 'group_id',
                'GroupID' => 'group_id',
            ],
            'parts' => [
                'partnumber' => 'part_number',
                'PartNumber' => 'part_number',
                'partterminologyid' => 'part_terminology_id',
                'PartTerminologyId' => 'part_terminology_id',
                'manufacturerID' => 'manufacturer_id',
                'manufacturerid' => 'manufacturer_id',
            ],
            'applications' => [
                'applicationid' => 'application_id',
                'ApplicationId' => 'application_id',
                'ApplicationID' => 'application_id',
                'makeid' => 'make_id',
                'MakeId' => 'make_id',
                'MakeID' => 'make_id',
                'modelid' => 'model_id',
                'ModelId' => 'model_id',
                'ModelID' => 'model_id',
                'yearid' => 'year_id',
                'YearId' => 'year_id',
                'YearID' => 'year_id',
            ],
            'inventories' => [
                'partid' => 'part_id',
                'PartId' => 'part_id',
                'PartID' => 'part_id',
                'distributorid' => 'distributor_id',
                'DistributorId' => 'distributor_id',
                'DistributorID' => 'distributor_id',
                'quantityavailable' => 'quantity_available',
                'QuantityAvailable' => 'quantity_available',
            ],
            'pricing' => [
                'partid' => 'part_id',
                'PartId' => 'part_id',
                'PartID' => 'part_id',
                'distributorid' => 'distributor_id',
                'DistributorId' => 'distributor_id',
                'DistributorID' => 'distributor_id',
                'listprice' => 'list_price',
                'ListPrice' => 'list_price',
                'yourprice' => 'your_price',
                'YourPrice' => 'your_price',
            ],
            'attributes' => [
                'attributeid' => 'attribute_id',
                'AttributeId' => 'attribute_id',
                'AttributeID' => 'attribute_id',
                'attributename' => 'attribute_name',
                'AttributeName' => 'attribute_name',
                'attributevalue' => 'attribute_value',
                'AttributeValue' => 'attribute_value',
            ],
            'images' => [
                'imageid' => 'image_id',
                'ImageId' => 'image_id',
                'ImageID' => 'image_id',
                'partid' => 'part_id',
                'PartId' => 'part_id',
                'PartID' => 'part_id',
                'imageurl' => 'image_url',
                'ImageUrl' => 'image_url',
                'ImageURL' => 'image_url',
            ]
        ];
    }

    private function insertBatch(string $tableName, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            $timestamp = now();
            $processedBatch = array_map(function($row) use ($timestamp, $tableName) {
                // Prepare the base staging record structure
                $stagingRecord = [
                    'raw_data' => json_encode($row),
                    'processed' => false,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp
                ];
                
                // Handle different staging table structures
                if (str_starts_with($tableName, 'ari_staging_')) {
                    $stagingRecord['sync_operation_id'] = session('current_sync_operation_id', 1);
                    
                    // Add ari_id if we have an id field
                    if (isset($row['id'])) {
                        $stagingRecord['ari_id'] = $row['id'];
                    }
                    
                    // Handle specific table mappings
                    switch ($tableName) {
                        case 'ari_staging_generic':
                            $stagingRecord['entity_name'] = $this->getEntityNameFromData($row);
                            break;
                            
                        case 'ari_staging_partmaster':
                            // Map specific fields for partmaster if available
                            $stagingRecord['manufacturer_id'] = $row['manufacturer_id'] ?? null;
                            $stagingRecord['manufacturer_number_short'] = $row['manufacturer_number_short'] ?? null;
                            $stagingRecord['manufacturer_number_long'] = $row['manufacturer_number_long'] ?? null;
                            $stagingRecord['item_name'] = $row['item_name'] ?? null;
                            $stagingRecord['item_description'] = $row['item_description'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_images':
                            $stagingRecord['partmaster_id'] = $row['partmaster_id'] ?? null;
                            $stagingRecord['hi_res_image_name'] = $row['hi_res_image_name'] ?? null;
                            $stagingRecord['date_modified'] = $row['date_modified'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_fitment':
                            $stagingRecord['tmmy_id'] = $row['tmmy_id'] ?? null;
                            $stagingRecord['part_to_app_combo_id'] = $row['part_to_app_combo_id'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_distributor_inventory':
                            $stagingRecord['part_price_inv_id'] = $row['partpriceinvid'] ?? $row['part_price_inv_id'] ?? null;
                            $stagingRecord['qty'] = $row['qty'] ?? null;
                            $stagingRecord['distributor_warehouse_id'] = $row['distributor_warehouse_id'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_part_price_inv':
                            $stagingRecord['distributor_id'] = $row['distributor_id'] ?? null;
                            $stagingRecord['distributor_part_number_short'] = $row['distributor_part_number_short'] ?? null;
                            $stagingRecord['distributor_part_number_long'] = $row['distributor_part_number_long'] ?? null;
                            $stagingRecord['partmaster_id'] = $row['partmaster_id'] ?? null;
                            $stagingRecord['msrp'] = $row['msrp'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                    }
                }
                
                return $stagingRecord;
            }, $batch);

            DB::table($tableName)->insert($processedBatch);
            Log::debug("Inserted batch of " . count($batch) . " records into {$tableName}");

        } catch (Exception $e) {
            Log::error("Failed to insert batch into {$tableName}: " . $e->getMessage());
            Log::error("Table: {$tableName}, Sample record: " . json_encode($batch[0] ?? 'No data'));
            throw $e;
        }
    }
    
    private function getEntityNameFromData(array $row): string
    {
        // Enhanced detection for DataStream CSV format
        // First, get all field names to understand the structure
        $fields = array_keys($row);
        $fieldsLower = array_map('strtolower', $fields);
        $fieldString = implode('|', $fieldsLower);
        
        // Log the field structure for debugging (only for first few records)
        static $logCount = 0;
        if ($logCount < 3) {
            Log::debug("Entity detection - Fields: " . $fieldString);
            $logCount++;
        }
        
        // Enhanced patterns for DataStream format
        // Check for Makes/Make data
        if (in_array('makeid', $fieldsLower) || 
            in_array('make_id', $fieldsLower) ||
            in_array('makename', $fieldsLower) ||
            in_array('make_name', $fieldsLower) ||
            (count($fieldsLower) <= 3 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'make') !== false)) {
            return 'makes';
        }
        
        // Check for Models data
        if (in_array('modelid', $fieldsLower) || 
            in_array('model_id', $fieldsLower) ||
            in_array('modelname', $fieldsLower) ||
            in_array('model_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'model') !== false)) {
            return 'models';
        }
        
        // Check for Years data
        if (in_array('yearid', $fieldsLower) || 
            in_array('year_id', $fieldsLower) ||
            in_array('yearvalue', $fieldsLower) ||
            in_array('year_value', $fieldsLower) ||
            in_array('year', $fieldsLower) ||
            (count($fieldsLower) <= 3 && in_array('id', $fieldsLower) && (strpos($fieldString, 'year') !== false))) {
            return 'years';
        }
        
        // Check for Brands data
        if (in_array('brandid', $fieldsLower) || 
            in_array('brand_id', $fieldsLower) ||
            in_array('brandname', $fieldsLower) ||
            in_array('brand_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'brand') !== false)) {
            return 'brands';
        }
        
        // Check for Manufacturers data
        if (in_array('manufacturerid', $fieldsLower) || 
            in_array('manufacturer_id', $fieldsLower) ||
            in_array('manufacturername', $fieldsLower) ||
            in_array('manufacturer_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'manufacturer') !== false)) {
            return 'manufacturers';
        }
        
        // Check for Distributors data
        if (in_array('distributorid', $fieldsLower) || 
            in_array('distributor_id', $fieldsLower) ||
            in_array('distributorname', $fieldsLower) ||
            in_array('distributor_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'distributor') !== false)) {
            return 'distributors';
        }
        
        // Check for Attributes data
        if (in_array('attributeid', $fieldsLower) || 
            in_array('attribute_id', $fieldsLower) ||
            in_array('attributename', $fieldsLower) ||
            in_array('attribute_name', $fieldsLower) ||
            in_array('attributevalue', $fieldsLower) ||
            (strpos($fieldString, 'attribute') !== false)) {
            return 'attributes';
        }
        
        // Check for Groups data
        if (in_array('groupid', $fieldsLower) || 
            in_array('group_id', $fieldsLower) ||
            in_array('groupname', $fieldsLower) ||
            in_array('group_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'group') !== false)) {
            return 'groups';
        }
        
        // Check for Categories data
        if (in_array('categoryid', $fieldsLower) || 
            in_array('category_id', $fieldsLower) ||
            in_array('categoryname', $fieldsLower) ||
            in_array('category_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'category') !== false)) {
            return 'categories';
        }
        
        // Check for Applications data
        if (in_array('applicationid', $fieldsLower) || 
            in_array('application_id', $fieldsLower) ||
            in_array('applicationname', $fieldsLower) ||
            in_array('application_name', $fieldsLower) ||
            (strpos($fieldString, 'application') !== false)) {
            return 'applications';
        }
        
        // Check for specific DataStream patterns
        if (isset($row['catalogsid']) && isset($row['partpriceinvid'])) {
            return 'catalogs';
        }
        
        if (isset($row['partpriceinvid']) || in_array('partpriceinvid', $fieldsLower)) {
            return 'part_price_inv';
        }
        
        if (isset($row['pagenumber']) || in_array('pagenumber', $fieldsLower)) {
            return 'catalog_pages';
        }
        
        // Check for Associated Parts
        if (strpos($fieldString, 'associated') !== false || strpos($fieldString, 'part') !== false) {
            return 'associated_parts';
        }
        
        // Log unclassified records for analysis
        static $unknownLogCount = 0;
        if ($unknownLogCount < 5) {
            Log::warning("Unclassified entity - Fields: " . $fieldString . ", Sample data: " . json_encode(array_slice($row, 0, 3)));
            $unknownLogCount++;
        }
        
        return 'unknown';
    }

    public function getTableNameFromFile(string $filename): string
    {
        $basename = strtolower(pathinfo($filename, PATHINFO_FILENAME));
        
        // Map file names to staging table names (using ari_staging tables)
        $mappings = [
            'partmaster' => 'ari_staging_partmaster',
            'parts' => 'ari_staging_partmaster', // alias
            'images' => 'ari_staging_images',
            'fitment' => 'ari_staging_fitment',
            'distributor_inventory' => 'ari_staging_distributor_inventory',
            'inventories' => 'ari_staging_distributor_inventory', // alias
            'part_price_inv' => 'ari_staging_part_price_inv',
            'pricing' => 'ari_staging_part_price_inv', // alias
            // Everything else goes to generic staging
            'applications' => 'ari_staging_generic',
            'attributes' => 'ari_staging_generic',
            'groups' => 'ari_staging_generic',
            'categories' => 'ari_staging_generic',
            'years' => 'ari_staging_generic',
            'makes' => 'ari_staging_generic',
            'models' => 'ari_staging_generic',
            'engines' => 'ari_staging_generic',
            'vehicletypes' => 'ari_staging_generic',
            'brands' => 'ari_staging_generic',
            'manufacturers' => 'ari_staging_generic',
            'distributors' => 'ari_staging_generic'
        ];

        return $mappings[$basename] ?? 'ari_staging_generic';
    }

    public function clearStagingTable(string $tableName): void
    {
        try {
            DB::table($tableName)->truncate();
            Log::info("Cleared staging table: {$tableName}");
        } catch (Exception $e) {
            Log::error("Failed to clear staging table {$tableName}: " . $e->getMessage());
            throw $e;
        }
    }

    public function getStagingTableRowCount(string $tableName): int
    {
        try {
            return DB::table($tableName)->count();
        } catch (Exception $e) {
            Log::error("Failed to get row count for {$tableName}: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Calculate optimal batch size based on file size and available memory
     */
    private function calculateOptimalBatchSize(int $fileSize): int
    {
        // Ultra-aggressive batch sizes for memory efficiency
        
        // For massive files (> 500MB), use microscopic batches
        if ($fileSize > 500 * 1024 * 1024) {
            return 50; // Fitment.txt is 584MB
        }
        
        // For extremely large files (> 300MB), use tiny batches
        if ($fileSize > 300 * 1024 * 1024) {
            return 75;
        }
        
        // For very large files (> 100MB), use small batches
        if ($fileSize > 100 * 1024 * 1024) {
            return 100;
        }
        
        // For medium files (10MB - 100MB), use medium batches
        if ($fileSize > 10 * 1024 * 1024) {
            return 250;
        }
        
        // For small files, use larger batches
        return 500;
    }
    
    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    /**
     * Create minimal row data to reduce memory usage
     */
    private function createMinimalRowData(array $row, array $headers, string $tableName): array
    {
        $rowData = [];
        
        // For raw_data storage, we need to get the ORIGINAL CSV headers
        // The $headers passed here are already normalized, so we need to get originals
        // This function should receive original headers, not normalized ones
        foreach ($headers as $index => $header) {
            if (isset($row[$index])) {
                $value = trim($row[$index]);
                // Keep all fields - use lowercase for consistency but preserve data
                $rowData[strtolower($header)] = $value;
            }
        }
        
        return $rowData;
    }
    
    /**
     * Ultra-memory-efficient batch insert without JSON storage
     */
    private function insertBatchDirectly(string $tableName, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        try {
            // Use static timestamp to avoid creating millions of Carbon objects
            static $timestamp = null;
            if ($timestamp === null) {
                $timestamp = date('Y-m-d H:i:s'); // Use plain PHP date instead of Laravel's now()
            }
            
            // Cache session value to avoid repeated lookups
            static $syncOperationId = null;
            if ($syncOperationId === null) {
                $syncOperationId = session('current_sync_operation_id', 1);
            }
            
            $processedBatch = [];
            
            foreach ($batch as $row) {
                // Create minimal staging record without storing full raw_data
                $stagingRecord = [
                    'processed' => 0, // Use integer instead of boolean to save memory
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp
                ];
                
                // Handle different staging table structures
                if (str_starts_with($tableName, 'ari_staging_')) {
                    $stagingRecord['sync_operation_id'] = $syncOperationId;
                    
                    // Add ari_id if we have an id field
                    if (isset($row['id'])) {
                        $stagingRecord['ari_id'] = $row['id'];
                    }
                    
                    // Store actual CSV row data for entity classification
                    $stagingRecord['raw_data'] = json_encode($row);
                    
                    // Handle specific table mappings - only store essential fields directly
                    switch ($tableName) {
                        case 'ari_staging_generic':
                            $stagingRecord['entity_name'] = $this->getEntityNameFromData($row);
                            break;
                            
                        case 'ari_staging_partmaster':
                            $stagingRecord['manufacturer_id'] = $row['manufacturer_id'] ?? null;
                            $stagingRecord['manufacturer_number_short'] = $row['manufacturer_number_short'] ?? null;
                            $stagingRecord['manufacturer_number_long'] = $row['manufacturer_number_long'] ?? null;
                            $stagingRecord['item_name'] = $row['item_name'] ?? null;
                            $stagingRecord['item_description'] = $row['item_description'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_images':
                            $stagingRecord['partmaster_id'] = $row['partmaster_id'] ?? null;
                            $stagingRecord['hi_res_image_name'] = $row['hi_res_image_name'] ?? null;
                            $stagingRecord['date_modified'] = $row['date_modified'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_fitment':
                            $stagingRecord['tmmy_id'] = $row['tmmy_id'] ?? null;
                            $stagingRecord['part_to_app_combo_id'] = $row['part_to_app_combo_id'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_distributor_inventory':
                            $stagingRecord['part_price_inv_id'] = $row['partpriceinvid'] ?? $row['part_price_inv_id'] ?? null;
                            $stagingRecord['qty'] = $row['qty'] ?? null;
                            $stagingRecord['distributor_warehouse_id'] = $row['distributor_warehouse_id'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                            
                        case 'ari_staging_part_price_inv':
                            $stagingRecord['distributor_id'] = $row['distributor_id'] ?? null;
                            $stagingRecord['distributor_part_number_short'] = $row['distributor_part_number_short'] ?? null;
                            $stagingRecord['distributor_part_number_long'] = $row['distributor_part_number_long'] ?? null;
                            $stagingRecord['partmaster_id'] = $row['partmaster_id'] ?? null;
                            $stagingRecord['msrp'] = $row['msrp'] ?? null;
                            $stagingRecord['update_flag'] = $row['updateflag'] ?? $row['update_flag'] ?? null;
                            break;
                    }
                } else {
                    // For non-ARI tables, skip raw_data entirely
                    $stagingRecord['raw_data'] = null;
                }
                
                $processedBatch[] = $stagingRecord;
            }

            // Direct insert without chunking to reduce memory overhead
            DB::table($tableName)->insert($processedBatch);
            
            Log::debug("Inserted batch of " . count($batch) . " records into {$tableName}");
            
            // Immediate memory cleanup
            unset($processedBatch);
            $processedBatch = null;

        } catch (Exception $e) {
            Log::error("Failed to insert batch into {$tableName}: " . $e->getMessage());
            Log::error("Table: {$tableName}, Sample record keys: " . implode(', ', array_keys($batch[0] ?? [])));
            throw $e;
        }
    }
    
    /**
     * Extract only essential data fields to minimize JSON size
     */
    private function extractEssentialData(array $row, string $tableName): array
    {
        // Define essential fields per table type to reduce memory usage
        $essentialFields = [
            'ari_staging_partmaster' => ['id', 'manufacturer_id', 'item_name', 'part_number'],
            'ari_staging_images' => ['partmaster_id', 'hi_res_image_name'],
            'ari_staging_fitment' => ['part_id', 'tmmy_id', 'application_id'],
            'ari_staging_distributor_inventory' => ['part_price_inv_id', 'qty', 'distributor_id'],
            'ari_staging_part_price_inv' => ['distributor_id', 'partmaster_id', 'msrp'],
            'ari_staging_generic' => ['id', 'name'] // Generic fallback
        ];
        
        $fieldsToKeep = $essentialFields[$tableName] ?? ['id', 'name'];
        $essentialData = [];
        
        foreach ($fieldsToKeep as $field) {
            if (isset($row[$field]) && $row[$field] !== '' && $row[$field] !== null) {
                $essentialData[$field] = $row[$field];
            }
        }
        
        return $essentialData;
    }

    /**
     * ZERO-MEMORY CSV parser - processes single records without any array accumulation
     * This is the most memory-efficient method for massive files (300MB+)
     */
    public function parseZeroMemoryCsvFile(string $filePath, string $tableName): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found: {$filePath}");
        }

        $fileSize = filesize($filePath);
        Log::info("Zero-memory parsing: {$filePath} ({$this->formatBytes($fileSize)}) → {$tableName}");
        
        // Set conservative memory limit to prevent hitting 1GB
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 0);
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Failed to open CSV file: {$filePath}");
        }

        $totalProcessed = 0;
        $lineNumber = 0;
        $headers = [];
        
        Log::info("Zero-memory mode: Processing single records for file: " . basename($filePath));

        try {
            // Detect delimiter
            $firstLine = fgets($handle);
            rewind($handle);
            
            $delimiter = "\t";
            if (strpos($firstLine, ',') !== false && strpos($firstLine, '"') !== false) {
                $delimiter = ",";
            }
            
            // Process one record at a time - ZERO array accumulation
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($lineNumber === 1) {
                    $headers = $this->normalizeHeaders($row, $tableName);
                    continue;
                }

                if (empty($headers)) {
                    Log::warning("No headers found in: {$filePath}");
                    break;
                }

                // Create minimal row data
                $rowData = $this->createEssentialRowData($row, $headers);
                
                // Insert immediately - NO array accumulation
                $this->microBatchService->insertSingleRecord($tableName, $rowData);
                $totalProcessed++;
                
                // Immediate cleanup of variables
                unset($rowData, $row);
                
                // Enhanced memory monitoring every 500 records (more frequent)
                if ($totalProcessed % 500 == 0) {
                    $this->monitorMemoryDuringProcessing($totalProcessed, 'Zero-memory parser');
                    
                    // Additional safety check
                    $this->checkMemoryCircuitBreaker($totalProcessed);
                }
            }

        } finally {
            fclose($handle);
            $this->microBatchService->forceMemoryCleanup();
        }

        Log::info("Zero-memory parse complete: {$totalProcessed} records from {$filePath}");
        
        return [
            'total_records' => $totalProcessed,
            'table_name' => $tableName,
            'file_path' => $filePath
        ];
    }
    
    /**
     * Memory-optimized streaming CSV parser for single file processing
     * Uses minimal memory footprint and direct SQL inserts
     */
    public function parseStreamingCsvFile(string $filePath, string $tableName): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found: {$filePath}");
        }

        Log::info("Streaming parse: {$filePath} → {$tableName}");
        
        // Ultra-aggressive memory settings
        ini_set('memory_limit', '2G');
        ini_set('max_execution_time', 0);
        
        $fileSize = filesize($filePath);
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Failed to open CSV file: {$filePath}");
        }

        $totalProcessed = 0;
        $lineNumber = 0;
        $headers = [];
        
        // Ultra-small batch sizes for large files
        $batchSize = $this->getStreamingBatchSize($fileSize);
        
        Log::info("Streaming batch size: {$batchSize} for file size: " . $this->formatBytes($fileSize));

        try {
            // Detect delimiter
            $firstLine = fgets($handle);
            rewind($handle);
            
            $delimiter = "\t";
            if (strpos($firstLine, ',') !== false && strpos($firstLine, '"') !== false) {
                $delimiter = ",";
            }
            
            // Process file line by line with minimal memory usage
            $currentBatch = [];
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($lineNumber === 1) {
                    $headers = $this->normalizeHeaders($row, $tableName);
                    continue;
                }

                if (empty($headers)) {
                    Log::warning("No headers found in: {$filePath}");
                    break;
                }

                // Create ultra-minimal row data
                $rowData = $this->createUltraMinimalRowData($row, $headers);
                $currentBatch[] = $rowData;

                // Process in tiny batches
                if (count($currentBatch) >= $batchSize) {
                    $this->insertStreamingBatch($tableName, $currentBatch);
                    $totalProcessed += count($currentBatch);
                    
                    // Immediate memory cleanup
                    unset($currentBatch);
                    $currentBatch = [];
                    
                    // Force garbage collection every batch
                    if ($totalProcessed % ($batchSize * 10) == 0) {
                        gc_collect_cycles();
                        
                        $memory = $this->formatBytes(memory_get_usage(true));
                        Log::info("Streaming progress: {$totalProcessed} records, Memory: {$memory}");
                    }
                }
            }

            // Process final batch
            if (!empty($currentBatch)) {
                $this->insertStreamingBatch($tableName, $currentBatch);
                $totalProcessed += count($currentBatch);
                unset($currentBatch);
            }

        } finally {
            fclose($handle);
            gc_collect_cycles();
        }

        Log::info("Streaming parse complete: {$totalProcessed} records from {$filePath}");
        
        return [
            'total_records' => $totalProcessed,
            'table_name' => $tableName,
            'file_path' => $filePath
        ];
    }
    
    /**
     * Get optimal batch size for streaming based on file size
     */
    private function getStreamingBatchSize(int $fileSize): int
    {
        // Even more aggressive batch sizes for streaming
        if ($fileSize > 500 * 1024 * 1024) { // > 500MB
            return 25;  // Microscopic batches
        }
        if ($fileSize > 300 * 1024 * 1024) { // > 300MB  
            return 50;
        }
        if ($fileSize > 100 * 1024 * 1024) { // > 100MB
            return 75;
        }
        if ($fileSize > 50 * 1024 * 1024) {  // > 50MB
            return 100;
        }
        
        return 200; // For smaller files
    }
    
    /**
     * Create ultra-minimal row data (only essential fields)
     */
    private function createUltraMinimalRowData(array $row, array $headers): array
    {
        $rowData = [];
        
        // Only store absolutely essential fields to reduce memory
        foreach ($headers as $index => $normalizedHeader) {
            if (isset($row[$index])) {
                $value = trim($row[$index]);
                
                // Skip empty values and only keep critical fields
                if ($value !== '' && $value !== null && $this->isEssentialField($normalizedHeader)) {
                    $rowData[$normalizedHeader] = $value;
                }
            }
        }
        
        return $rowData;
    }
    
    /**
     * Check if a field is essential for processing
     */
    private function isEssentialField(string $fieldName): bool
    {
        // Define critical fields that must be kept - including product names!
        $essentialFields = [
            'id', 'part_id', 'manufacturer_id', 'distributor_id',
            'partmaster_id', 'tmmy_id', 'application_id',
            'part_price_inv_id', 'qty', 'msrp', 'item_name',
            'hi_res_image_name', 'update_flag',
            // Add DataStream CSV field names
            'itemname', 'itemdescription', 'manufacturerid',
            'manufacturernumbershort', 'manufacturernumberlong',
            'description', 'name', 'hiresimagename', 'partmasterid'
        ];
        
        return in_array(strtolower($fieldName), $essentialFields);
    }
    
    /**
     * Ultra-lightweight batch insert for streaming using MicroBatchService
     */
    private function insertStreamingBatch(string $tableName, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        // Use MicroBatchService for ultra-efficient processing
        $this->microBatchService->insertMicroBatch($tableName, $batch, 10);
    }
    
    /**
     * ULTRA-OPTIMIZED CSV parser - uses micro-batching and skips JSON storage entirely
     * This is the most memory-efficient method for large files
     */
    public function parseUltraLightCsvFile(string $filePath, string $tableName): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("CSV file not found: {$filePath}");
        }

        $fileSize = filesize($filePath);
        Log::info("Ultra-light parsing: {$filePath} ({$this->formatBytes($fileSize)}) → {$tableName}");
        
        // Set conservative memory limit - avoid 1GB which causes the exact error
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 0);
        
        $handle = fopen($filePath, 'r');
        if (!$handle) {
            throw new Exception("Failed to open CSV file: {$filePath}");
        }

        $totalProcessed = 0;
        $lineNumber = 0;
        $headers = [];
        
        // Get optimal batch size from MicroBatchService
        $batchSize = $this->microBatchService->getOptimalBatchSize($fileSize);
        
        Log::info("Ultra-light batch size: {$batchSize} for file: " . basename($filePath));

        try {
            // Detect delimiter
            $firstLine = fgets($handle);
            rewind($handle);
            
            $delimiter = "\t";
            if (strpos($firstLine, ',') !== false && strpos($firstLine, '"') !== false) {
                $delimiter = ",";
            }
            
            // Process with micro-batches
            $currentBatch = [];
            $batchCount = 0;
            
            while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNumber++;

                if ($lineNumber === 1) {
                    $headers = $this->normalizeHeaders($row, $tableName);
                    continue;
                }

                if (empty($headers)) {
                    Log::warning("No headers found in: {$filePath}");
                    break;
                }

                // Create complete row data for entity classification
                $rowData = $this->createMinimalRowData($row, $headers, $tableName);
                $currentBatch[] = $rowData;

                // Process in micro-batches
                if (count($currentBatch) >= $batchSize) {
                    $this->microBatchService->insertMicroBatch($tableName, $currentBatch, 10);
                    $totalProcessed += count($currentBatch);
                    $batchCount++;
                    
                    // Complete memory cleanup
                    unset($currentBatch);
                    $currentBatch = [];
                    
                    // Enhanced memory monitoring with circuit breakers
                    if ($batchCount % 10 == 0) {
                        $this->monitorMemoryDuringProcessing($totalProcessed, 'Ultra-light parser');
                        
                        // Check circuit breaker
                        $this->checkMemoryCircuitBreaker($totalProcessed);
                    }
                }
            }

            // Process final batch
            if (!empty($currentBatch)) {
                $this->microBatchService->insertMicroBatch($tableName, $currentBatch);
                $totalProcessed += count($currentBatch);
                unset($currentBatch);
            }

        } finally {
            fclose($handle);
            $this->microBatchService->forceMemoryCleanup();
        }

        Log::info("Ultra-light parse complete: {$totalProcessed} records from {$filePath}");
        
        return [
            'total_records' => $totalProcessed,
            'table_name' => $tableName,
            'file_path' => $filePath
        ];
    }
    
    /**
     * Create row data with only essential fields to minimize memory usage
     */
    private function createEssentialRowData(array $row, array $headers): array
    {
        $rowData = [];
        
        // Process only essential fields to minimize memory
        foreach ($headers as $index => $normalizedHeader) {
            if (isset($row[$index]) && $this->isEssentialField($normalizedHeader)) {
                $value = trim($row[$index]);
                if ($value !== '' && $value !== null) {
                    $rowData[$normalizedHeader] = $value;
                }
            }
        }
        
        return $rowData;
    }
    
    /**
     * Map only essential fields for specific table types
     */
    private function mapEssentialFields(array $row, string $tableName): array
    {
        $mapped = [];
        
        switch ($tableName) {
            case 'ari_staging_partmaster':
                $mapped['manufacturer_id'] = $row['manufacturer_id'] ?? null;
                $mapped['item_name'] = $row['item_name'] ?? null;
                break;
                
            case 'ari_staging_images':
                $mapped['partmaster_id'] = $row['partmaster_id'] ?? null;
                $mapped['hi_res_image_name'] = $row['hi_res_image_name'] ?? null;
                break;
                
            case 'ari_staging_fitment':
                $mapped['tmmy_id'] = $row['tmmy_id'] ?? null;
                $mapped['part_to_app_combo_id'] = $row['part_to_app_combo_id'] ?? null;
                break;
                
            case 'ari_staging_distributor_inventory':
                $mapped['part_price_inv_id'] = $row['part_price_inv_id'] ?? null;
                $mapped['qty'] = $row['qty'] ?? null;
                break;
                
            case 'ari_staging_part_price_inv':
                $mapped['distributor_id'] = $row['distributor_id'] ?? null;
                $mapped['partmaster_id'] = $row['partmaster_id'] ?? null;
                $mapped['msrp'] = $row['msrp'] ?? null;
                break;
                
            case 'ari_staging_generic':
                $mapped['entity_name'] = $this->getEntityNameFromData($row);
                break;
        }
        
        return $mapped;
    }
    
    /**
     * Monitor memory usage and trigger circuit breaker if approaching limits
     */
    private function checkMemoryCircuitBreaker(int $recordsProcessed): void
    {
        // Use MicroBatchService for consistent memory monitoring
        $stats = $this->microBatchService->getMemoryStats();
        
        // Circuit breaker at 400MB to prevent hitting 512MB limit
        if ($stats['current_mb'] > 400) {
            Log::error("Memory circuit breaker triggered", [
                'records_processed' => $recordsProcessed,
                'memory_stats' => $stats
            ]);
            
            // Trigger emergency shutdown
            $this->microBatchService->emergencyMemoryShutdown("CsvParser after {$recordsProcessed} records");
        }
        
        // Warning at 300MB
        if ($stats['current_mb'] > 300) {
            Log::warning("High memory usage detected", [
                'records_processed' => $recordsProcessed,
                'memory_stats' => $stats
            ]);
            
            // Force aggressive cleanup
            $this->microBatchService->forceMemoryCleanup();
        }
        
        // Check if we should trigger the circuit breaker
        if ($this->microBatchService->shouldTriggerMemoryCircuitBreaker()) {
            $this->microBatchService->emergencyMemoryShutdown("Circuit breaker triggered at {$recordsProcessed} records");
        }
    }
    
    /**
     * Safe memory usage check for large file processing
     */
    private function isMemorySafe(): bool
    {
        // Use MicroBatchService for consistent memory checking
        $stats = $this->microBatchService->getMemoryStats();
        
        // Return false if memory usage exceeds 350MB (much more conservative)
        return $stats['current_mb'] <= 350;
    }
    
    /**
     * Enhanced memory monitoring for large files
     */
    private function monitorMemoryDuringProcessing(int $recordsProcessed, string $context = ''): void
    {
        $stats = $this->microBatchService->getMemoryStats();
        
        // Log memory stats every 5000 records
        if ($recordsProcessed % 5000 == 0) {
            Log::info("Memory monitoring", [
                'context' => $context,
                'records_processed' => $recordsProcessed,
                'memory_stats' => $stats
            ]);
        }
        
        // Check circuit breaker conditions
        if ($stats['should_circuit_break']) {
            $this->microBatchService->emergencyMemoryShutdown("{$context} - {$recordsProcessed} records");
        }
        
        // Force cleanup if memory is high
        if ($stats['is_high']) {
            Log::warning("Forcing memory cleanup due to high usage", [
                'context' => $context,
                'records_processed' => $recordsProcessed,
                'memory_stats' => $stats
            ]);
            
            $this->microBatchService->forceMemoryCleanup();
        }
    }
}
