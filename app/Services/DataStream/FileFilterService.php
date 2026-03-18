<?php

namespace App\Services\DataStream;

use Illuminate\Support\Facades\Log;

class FileFilterService
{
    // Maximum file size to process (500MB limit)
    private const MAX_FILE_SIZE = 500 * 1024 * 1024; // 500MB
    
    // Critical files that must be processed
    private const CRITICAL_FILES = [
        'partmaster',
        'parts', 
        'part_price_inv',
        'pricing',
        'distributor_inventory',
        'inventories'
    ];
    
    // Optional files that can be skipped for initial sync
    private const OPTIONAL_FILES = [
        'fitment',                    // Large fitment data - process separately
        'images',                     // Image metadata - process separately  
        'attributes',                 // Product attributes - nice to have
        'applications',               // Applications - secondary priority
        'categories',                 // Categories - secondary priority
        'groups',                     // Groups - secondary priority
        'partincatalogs'             // Catalog references - low priority
    ];
    
    // Files to completely skip (too large or unnecessary)
    private const SKIP_FILES = [
        'partmasterdescriptionformatted' // 336MB description file - skip for now
    ];

    /**
     * Filter files based on size, priority, and sync type
     */
    public function filterFiles(array $files, string $syncType = 'incremental', bool $skipLargeFiles = true): array
    {
        $filtered = [];
        $skipped = [];
        $tooLarge = [];
        
        foreach ($files as $file) {
            $fileName = strtolower(pathinfo($file['name'], PATHINFO_FILENAME));
            $fileSize = $file['size'] ?? 0;
            
            // Skip files that are explicitly in skip list
            if ($this->shouldSkipFile($fileName)) {
                $skipped[] = [
                    'file' => $file['name'],
                    'reason' => 'In skip list',
                    'size' => $this->formatFileSize($fileSize)
                ];
                continue;
            }
            
            // Skip files that are too large if enabled
            if ($skipLargeFiles && $fileSize > self::MAX_FILE_SIZE) {
                $tooLarge[] = [
                    'file' => $file['name'],
                    'reason' => 'Exceeds size limit',
                    'size' => $this->formatFileSize($fileSize),
                    'limit' => $this->formatFileSize(self::MAX_FILE_SIZE)
                ];
                continue;
            }
            
            // Filter based on sync type
            if ($this->shouldIncludeForSyncType($fileName, $syncType)) {
                $file['priority'] = $this->getFilePriority($fileName);
                $file['size_category'] = $this->getSizeCategory($fileSize);
                $filtered[] = $file;
            }
        }
        
        // Sort by priority (critical files first) and then by size (smallest first)
        usort($filtered, function($a, $b) {
            // Priority first (lower number = higher priority)
            if ($a['priority'] !== $b['priority']) {
                return $a['priority'] <=> $b['priority'];
            }
            // Then by size (smaller first)
            return ($a['size'] ?? 0) <=> ($b['size'] ?? 0);
        });
        
        // Log filtering results
        Log::info("File filtering complete", [
            'sync_type' => $syncType,
            'total_files' => count($files),
            'filtered_files' => count($filtered),
            'skipped_files' => count($skipped),
            'too_large_files' => count($tooLarge),
            'skip_large_files' => $skipLargeFiles
        ]);
        
        if (!empty($skipped)) {
            Log::info("Skipped files", ['files' => $skipped]);
        }
        
        if (!empty($tooLarge)) {
            Log::warning("Files too large to process", ['files' => $tooLarge]);
        }
        
        return [
            'filtered' => $filtered,
            'skipped' => $skipped,
            'too_large' => $tooLarge
        ];
    }
    
    /**
     * Check if a file should be completely skipped
     */
    private function shouldSkipFile(string $fileName): bool
    {
        foreach (self::SKIP_FILES as $skipPattern) {
            if (strpos($fileName, $skipPattern) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if file should be included for specific sync type
     */
    private function shouldIncludeForSyncType(string $fileName, string $syncType): bool
    {
        switch ($syncType) {
            case 'critical':
                // Only process absolutely critical files
                return $this->isCriticalFile($fileName);
                
            case 'incremental':
                // Process critical files + some optional ones, but skip heavy files
                return $this->isCriticalFile($fileName) || $this->isOptionalFile($fileName);
                
            case 'full':
                // Process everything except explicitly skipped files
                return true;
                
            case 'images':
                // Only image-related files
                return strpos($fileName, 'image') !== false;
                
            case 'fitment':
                // Only fitment-related files
                return strpos($fileName, 'fitment') !== false;
                
            default:
                return $this->isCriticalFile($fileName);
        }
    }
    
    /**
     * Check if file is critical for basic functionality
     */
    private function isCriticalFile(string $fileName): bool
    {
        foreach (self::CRITICAL_FILES as $criticalPattern) {
            if (strpos($fileName, $criticalPattern) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Check if file is optional but useful
     */
    private function isOptionalFile(string $fileName): bool
    {
        foreach (self::OPTIONAL_FILES as $optionalPattern) {
            if (strpos($fileName, $optionalPattern) !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get processing priority for file (lower = higher priority)
     */
    private function getFilePriority(string $fileName): int
    {
        // Priority 1: Critical reference data
        if (in_array($fileName, ['brands', 'manufacturers', 'vehicle_types', 'makes', 'models', 'years'])) {
            return 1;
        }
        
        // Priority 2: Core product data
        if (strpos($fileName, 'partmaster') !== false || strpos($fileName, 'parts') !== false) {
            return 2;
        }
        
        // Priority 3: Pricing and inventory
        if (strpos($fileName, 'price') !== false || strpos($fileName, 'inventory') !== false) {
            return 3;
        }
        
        // Priority 4: Optional data
        if ($this->isOptionalFile($fileName)) {
            return 4;
        }
        
        // Priority 5: Everything else
        return 5;
    }
    
    /**
     * Categorize file by size for appropriate processing strategy
     */
    private function getSizeCategory(int $fileSize): string
    {
        if ($fileSize > 400 * 1024 * 1024) {        // > 400MB
            return 'massive';
        } elseif ($fileSize > 200 * 1024 * 1024) {  // > 200MB
            return 'very_large';
        } elseif ($fileSize > 50 * 1024 * 1024) {   // > 50MB
            return 'large';
        } elseif ($fileSize > 10 * 1024 * 1024) {   // > 10MB
            return 'medium';
        } else {
            return 'small';
        }
    }
    
    /**
     * Get recommended processing strategy based on file characteristics
     */
    public function getProcessingStrategy(array $file): array
    {
        $fileName = strtolower(pathinfo($file['name'], PATHINFO_FILENAME));
        $fileSize = $file['size'] ?? 0;
        $sizeCategory = $this->getSizeCategory($fileSize);
        
        $strategy = [
            'batch_size' => 1000,
            'memory_limit' => '2G',
            'use_streaming' => false,
            'use_chunking' => false,
            'skip_json_storage' => false,
            'db_reset_interval' => 100
        ];
        
        // Adjust strategy based on file size
        switch ($sizeCategory) {
            case 'massive': // > 400MB
                $strategy['batch_size'] = 10;
                $strategy['memory_limit'] = '1G';
                $strategy['use_streaming'] = true;
                $strategy['use_chunking'] = true;
                $strategy['skip_json_storage'] = true;
                $strategy['db_reset_interval'] = 10;
                break;
                
            case 'very_large': // > 200MB
                $strategy['batch_size'] = 25;
                $strategy['memory_limit'] = '1.5G';
                $strategy['use_streaming'] = true;
                $strategy['skip_json_storage'] = true;
                $strategy['db_reset_interval'] = 20;
                break;
                
            case 'large': // > 50MB
                $strategy['batch_size'] = 50;
                $strategy['memory_limit'] = '2G';
                $strategy['use_streaming'] = true;
                $strategy['skip_json_storage'] = true;
                $strategy['db_reset_interval'] = 50;
                break;
                
            case 'medium': // > 10MB
                $strategy['batch_size'] = 100;
                $strategy['skip_json_storage'] = true;
                break;
                
            case 'small': // < 10MB
                $strategy['batch_size'] = 500;
                break;
        }
        
        return $strategy;
    }
    
    /**
     * Format file size to human readable format
     */
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
