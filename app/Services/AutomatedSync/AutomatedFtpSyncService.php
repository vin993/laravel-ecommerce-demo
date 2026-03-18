<?php

namespace App\Services\AutomatedSync;

use App\Models\AutomatedSync\FtpSyncLog;
use App\Services\DataStream\FtpService;
use App\Services\DataStream\ArchiveService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class AutomatedFtpSyncService
{
    private FtpService $ftpService;
    private ArchiveService $archiveService;
    private UpdateDetectionService $detectionService;
    private CleanupService $cleanupService;
    private SyncNotificationService $notificationService;

    public function __construct(
        FtpService $ftpService,
        ArchiveService $archiveService,
        UpdateDetectionService $detectionService,
        CleanupService $cleanupService,
        SyncNotificationService $notificationService
    ) {
        $this->ftpService = $ftpService;
        $this->archiveService = $archiveService;
        $this->detectionService = $detectionService;
        $this->cleanupService = $cleanupService;
        $this->notificationService = $notificationService;
    }

    public function runSync(bool $skipImages = false, bool $force = false): array
    {
        $syncLog = $this->createSyncLog();

        try {
            $syncLog->markAsRunning();
            Log::info('[AutoSync] Starting automated FTP sync', ['sync_log_id' => $syncLog->id]);

            $newFiles = $this->detectionService->detectNewUpdateFiles();

            if (empty($newFiles) && !$force) {
                Log::info('[AutoSync] No new update files detected');
                $syncLog->update(['status' => 'completed', 'completed_at' => now()]);
                return [
                    'status' => 'no_updates',
                    'message' => 'No new update files detected',
                    'sync_log_id' => $syncLog->id
                ];
            }

            if ($force && empty($newFiles)) {
                Log::warning('[AutoSync] Force mode enabled but no files found');
            }

            $syncLog->update(['update_files_detected' => array_column($newFiles, 'name')]);

            $downloadedFiles = $this->downloadFiles($newFiles, $syncLog);
            $extractedPaths = $this->extractArchives($downloadedFiles, $syncLog);

            foreach ($extractedPaths as $extractedPath) {
                $folderName = basename($extractedPath);
                Log::info('[AutoSync] Processing folder: ' . $folderName);

                $this->processUpdateFolder($extractedPath, $folderName, $syncLog, $skipImages);
                $syncLog->addProcessedFile($folderName);

                $this->markFileAsProcessed($folderName, $syncLog->id);
            }

            $hasErrors = $syncLog->error_message !== null;
            if ($hasErrors) {
                $syncLog->markAsPartialSuccess();
            } else {
                $syncLog->markAsCompleted();
            }

            $this->cleanupService->cleanupDownloadedArchives($downloadedFiles);
            $this->cleanupService->cleanupExtractedFolders($extractedPaths);

            $this->notificationService->sendSuccessNotification($syncLog);

            Log::info('[AutoSync] Sync completed successfully', ['sync_log_id' => $syncLog->id]);

            return [
                'status' => $hasErrors ? 'partial_success' : 'success',
                'sync_log_id' => $syncLog->id,
                'files_processed' => count($extractedPaths),
                'stats' => [
                    'created' => $syncLog->new_products_created,
                    'updated' => $syncLog->products_updated,
                    'categories' => $syncLog->categories_synced,
                    'brands' => $syncLog->brands_synced,
                    'variants' => $syncLog->variants_synced,
                    'images' => $syncLog->images_synced,
                ]
            ];

        } catch (\Exception $e) {
            Log::error('[AutoSync] Sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $syncLog->markAsFailed($e->getMessage(), $e->getTraceAsString());
            $this->notificationService->sendFailureNotification($syncLog);

            return [
                'status' => 'failed',
                'error' => $e->getMessage(),
                'sync_log_id' => $syncLog->id
            ];
        }
    }

    private function createSyncLog(): FtpSyncLog
    {
        return FtpSyncLog::create([
            'sync_date' => now()->toDateString(),
            'status' => 'pending',
        ]);
    }

    private function downloadFiles(array $files, FtpSyncLog $syncLog): array
    {
        Log::info('[AutoSync] Downloading ' . count($files) . ' files');

        $this->ftpService->connect();
        $downloadedFiles = [];

        foreach ($files as $file) {
            try {
                $localPath = $this->ftpService->downloadFile($file);
                $downloadedFiles[] = $localPath;
                Log::info('[AutoSync] Downloaded: ' . $file['name']);
            } catch (\Exception $e) {
                Log::error('[AutoSync] Failed to download file', [
                    'file' => $file['name'],
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        $this->ftpService->disconnect();

        return $downloadedFiles;
    }

    private function extractArchives(array $downloadedFiles, FtpSyncLog $syncLog): array
    {
        Log::info('[AutoSync] Extracting ' . count($downloadedFiles) . ' archives');

        $extractedPaths = [];

        foreach ($downloadedFiles as $archivePath) {
            try {
                $extractedPath = $this->archiveService->extractArchive($archivePath);
                $extractedPaths[] = $extractedPath;
                Log::info('[AutoSync] Extracted: ' . basename($archivePath));
            } catch (\Exception $e) {
                Log::error('[AutoSync] Failed to extract archive', [
                    'archive' => basename($archivePath),
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        }

        return $extractedPaths;
    }

    private function processUpdateFolder(string $folderPath, string $folderName, FtpSyncLog $syncLog, bool $skipImages): void
    {
        Log::info('[AutoSync] Processing update folder: ' . $folderName);

        $stats = [
            'created' => 0,
            'updated' => 0,
            'categories' => 0,
            'brands' => 0,
            'variants' => 0,
            'images' => 0,
            'vehicle_fitments' => 0,
            'product_flat' => 0,
        ];

        try {
            $stats = array_merge($stats, $this->createAndUpdateProducts($folderName));
            $stats['categories'] = $this->syncCategories();
            $stats['brands'] = $this->syncBrands();

            $stats['variants'] = $this->buildVariants($folderName);
            $stats['vehicle_fitments'] = $this->syncVehicleFitment($folderName);

            if (!$skipImages) {
                $stats['images'] = $this->syncImages($folderName);
            }

            $stats['product_flat'] = $this->rebuildProductFlat();

            $syncLog->incrementStats($stats);

        } catch (\Exception $e) {
            Log::error('[AutoSync] Error processing folder: ' . $folderName, [
                'error' => $e->getMessage()
            ]);

            $currentError = $syncLog->error_message ?? '';
            $newError = $currentError . "\n" . $folderName . ': ' . $e->getMessage();
            $syncLog->update(['error_message' => trim($newError)]);
        }
    }

    private function createAndUpdateProducts(string $folderName): array
    {
        $created = 0;
        $updated = 0;

        Artisan::call('ari:apply-update', [
            '--path' => $folderName,
            '--batch' => 10000,
            '--new-only' => true,
        ]);
        $output = Artisan::output();
        if (preg_match('/New Products Created.*?(\d+)/', $output, $matches)) {
            $created = (int)$matches[1];
        }

        Artisan::call('ari:apply-update', [
            '--path' => $folderName,
            '--batch' => 10000,
            '--update-only' => true,
        ]);
        $output = Artisan::output();
        if (preg_match('/Existing Products Updated.*?(\d+)/', $output, $matches)) {
            $updated = (int)$matches[1];
        }

        if ($created > 0 || $updated > 0) {
            Artisan::call('ari:sync-update-attributes', [
                '--path' => $folderName,
                '--batch' => 10000,
            ]);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    private function syncCategories(): int
    {
        Artisan::call('ari:map-categories', ['--batch' => 5000]);
        return 1;
    }

    private function syncBrands(): int
    {
        Artisan::call('ari:assign-product-brands', ['--batch' => 10000]);
        return 1;
    }

    private function syncImages(string $folderName): int
    {
        Artisan::call('ari:sync-update-images', ['--folder' => $folderName]);
        return 1;
    }

    private function buildVariants(string $folderName): int
    {
        Log::info('[AutoSync] Building variant groups for new/updated products');

        try {
            Artisan::call('datastream:build-variant-groups', ['--force' => true]);

            $variantCount = DB::table('ds_variant_groups')->count();
            $configurableCount = DB::table('products')->where('type', 'configurable')->count();
            Log::info("[AutoSync] Variant groups: {$variantCount}, Configurable products: {$configurableCount}");

            return $variantCount;
        } catch (\Exception $e) {
            Log::error('[AutoSync] Failed to build variants', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function syncVehicleFitment(string $folderName): int
    {
        Log::info('[AutoSync] Syncing vehicle fitment data from: ' . $folderName);

        try {
            Artisan::call('vehicle:sync-lookup-tables', ['--path' => $folderName]);

            Artisan::call('vehicle:sync-tmmy', ['--path' => $folderName]);

            Artisan::call('vehicle:sync-applications', ['--path' => $folderName]);

            Artisan::call('vehicle:sync-app-combo', ['--path' => $folderName]);

            Artisan::call('vehicle:sync-fitment', [
                '--path' => $folderName,
                '--skip-part-combo' => true,
            ]);

            Artisan::call('vehicle:link-products', ['--batch' => 5000]);

            $fitmentCount = DB::table('product_vehicle_fitment')->count();
            Log::info("[AutoSync] Vehicle fitment links: {$fitmentCount}");

            return $fitmentCount;
        } catch (\Exception $e) {
            Log::error('[AutoSync] Failed to sync vehicle fitment', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function rebuildProductFlat(): int
    {
        Log::info('[AutoSync] Rebuilding product flat table');

        try {
            Artisan::call('ari:rebuild-indexes', ['--product-flat-only' => true]);

            $flatCount = DB::table('product_flat')->count();
            Log::info("[AutoSync] Product flat records: {$flatCount}");

            return $flatCount;
        } catch (\Exception $e) {
            Log::error('[AutoSync] Failed to rebuild product flat', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function markFileAsProcessed(string $fileName, int $syncLogId): void
    {
        DB::table('ari_ftp_file_tracking')
            ->where('filename', 'like', '%' . $fileName . '%')
            ->update([
                'status' => 'processed',
                'processed_by_automation' => true,
                'auto_sync_log_id' => $syncLogId,
                'updated_at' => now(),
            ]);
    }
}
