<?php

namespace App\Services\KawasakiDailySync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\DataStream\FtpService;
use Exception;

class KawasakiDailySyncService
{
    protected $changeDetector;
    protected $productHandler;
    protected $priceHandler;
    protected $inventoryHandler;
    protected $imageHandler;
    protected $ftpService;
    protected $syncId;
    
    public function __construct()
    {
        $this->changeDetector = new ChangeDetector();
        $this->productHandler = new ProductSyncHandler();
        $this->priceHandler = new PriceSyncHandler();
        $this->inventoryHandler = new InventorySyncHandler();
        $this->imageHandler = new ImageSyncHandler();
        $this->ftpService = app(\App\Services\DataStream\KawasakiFtpService::class);
    }

    /**
     * Download latest XML from FTP
     */
    public function downloadLatestXml(): string
    {
        $this->ftpService->connect();
        
        try {
            // List files in root and Kawasaki directory
            $files = $this->ftpService->listFiles('.');
            $targetFile = null;
            
            // First check root directory
            foreach ($files as $file) {
                $name = $file['name'] ?? $file['filename'] ?? '';
                if ($name === 'ItemsComplete.xml' || $name === 'ItemsIndex.xml') {
                    $targetFile = $file;
                    break;
                }
            }
            
            // If not found in root, check Kawasaki directory
            if (!$targetFile) {
                foreach ($files as $file) {
                    if (isset($file['brand']) && $file['brand'] === 'Kawasaki') {
                        $name = $file['name'] ?? $file['filename'] ?? '';
                        if ($name === 'ItemsComplete.xml' || $name === 'ItemsIndex.xml') {
                            $targetFile = $file;
                            break;
                        }
                    }
                }
            }
            
            if (!$targetFile) {
                Log::error('[DailySync] Files found on FTP: ' . json_encode(array_column($files, 'name')));
                throw new Exception('ItemsComplete.xml or ItemsIndex.xml not found on FTP server (checked root and Kawasaki directory)');
            }
            
            Log::info("[DailySync] Found remote file: " . ($targetFile['name'] ?? 'Unknown'));
            
            // Download to kawasaki directory instead of datastream
            $localDir = storage_path('app/kawasaki/downloads');
            if (!file_exists($localDir)) {
                mkdir($localDir, 0755, true);
            }
            
            $localPath = $this->ftpService->downloadFile($targetFile, $localDir);
            
            return $localPath;
        } finally {
            $this->ftpService->disconnect();
        }
    }

    /**
     * Process changes from XML file
     */
    public function processChanges(string $xmlPath, array $options = []): array
    {
        $dryRun = $options['dry_run'] ?? false;
        $limit = $options['limit'] ?? null;
        
        // Create sync state record
        $this->syncId = DB::table('kawasaki_sync_state')->insertGetId([
            'sync_date' => now(),
            'file_name' => basename($xmlPath),
            'file_checksum' => md5_file($xmlPath),
            'status' => 'running',
            'created_at' => now(),
        ]);
        
        $stats = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'prices_changed' => 0,
            'inventory_updated' => 0,
            'images_added' => 0,
        ];
        
        $startTime = time();
        
        try {
            $reader = new \XMLReader();
            $reader->open($xmlPath);
            
            while ($reader->read() && $reader->name !== 'Item');
            
            while ($reader->name === 'Item' && (!$limit || $stats['processed'] < $limit)) {
                try {
                    $node = new \SimpleXMLElement($reader->readOuterXml());
                    
                    // Detect changes
                    $result = $this->changeDetector->detectChanges($node);
                    
                    if ($result['action'] === 'skip') {
                        $stats['skipped']++;
                    } else {
                        // Process the product
                        if (!$dryRun) {
                            $this->processProduct($node, $result, $stats);
                        }
                        
                        if ($result['action'] === 'create') {
                            $stats['created']++;
                        } else {
                            $stats['updated']++;
                        }
                    }
                    
                    $stats['processed']++;
                    $reader->next('Item');
                    
                } catch (Exception $e) {
                    Log::error("[DailySync] Error processing item: " . $e->getMessage());
                    $reader->next('Item');
                }
            }
            
            $reader->close();
            
            // Update sync state
            $duration = time() - $startTime;
            DB::table('kawasaki_sync_state')->where('id', $this->syncId)->update([
                'items_processed' => $stats['processed'],
                'items_created' => $stats['created'],
                'items_updated' => $stats['updated'],
                'items_skipped' => $stats['skipped'],
                'prices_changed' => $stats['prices_changed'],
                'inventory_updated' => $stats['inventory_updated'],
                'images_added' => $stats['images_added'],
                'duration_seconds' => $duration,
                'status' => 'completed',
                'updated_at' => now(),
            ]);
            
        } catch (Exception $e) {
            DB::table('kawasaki_sync_state')->where('id', $this->syncId)->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'updated_at' => now(),
            ]);
            throw $e;
        }
        
        return $stats;
    }

    /**
     * Process a single product
     */
    protected function processProduct(\SimpleXMLElement $node, array $result, array &$stats): void
    {
        $data = $result['data'];
        $changes = $result['changes'];
        
        // Sync product (create or update)
        $productId = $this->productHandler->syncProduct($data, $result['action'], $changes);
        
        // Sync price if changed
        if (isset($changes['price'])) {
            $this->priceHandler->syncPrice(
                $productId,
                $data['price'],
                $changes['price']['old'] ?? null
            );
            $stats['prices_changed']++;
        }
        
        // Sync inventory if changed
        if (isset($changes['inventory'])) {
            $this->inventoryHandler->syncInventory($productId, $data['inventory']);
            $stats['inventory_updated']++;
        }
        
        // Sync images if changed
        if (isset($changes['images']) && !empty($data['images'])) {
            $added = $this->imageHandler->syncImages($productId, $data['images']);
            $stats['images_added'] += $added;
        }
        
        // Update snapshot
        $this->changeDetector->updateSnapshot($data['sku'], $data);
    }

    /**
     * Group new variants into configurable products
     */
    public function groupNewVariants(): int
    {
        // Get SKUs that were created/updated in this sync
        $recentSkus = DB::table('kawasaki_product_snapshots')
            ->where('last_synced_at', '>=', now()->subHours(1))
            ->pluck('sku');
        
        if ($recentSkus->isEmpty()) {
            return 0;
        }
        
        // Run variant grouping for these products
        $grouped = 0;
        
        // TODO: Implement variant grouping logic here
        // For now, return 0
        
        return $grouped;
    }

    /**
     * Clean up XML file after sync
     */
    public function cleanupXmlFile(string $xmlPath): void
    {
        if (file_exists($xmlPath)) {
            unlink($xmlPath);
            Log::info("[DailySync] Cleaned up XML file: {$xmlPath}");
        }
    }
}
