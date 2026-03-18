<?php

namespace App\Services\AutomatedSync;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class CleanupService
{
    private string $downloadsPath;
    private string $extractedPath;

    public function __construct()
    {
        $this->downloadsPath = storage_path('app/datastream/downloads');
        $this->extractedPath = storage_path('app/datastream/extracted');
    }

    public function cleanupDownloadedArchives(array $downloadedFiles): void
    {
        Log::info('[AutoSync] Cleaning up downloaded archives', ['count' => count($downloadedFiles)]);

        $deleted = 0;
        foreach ($downloadedFiles as $filePath) {
            if (File::exists($filePath)) {
                File::delete($filePath);
                $deleted++;
            }
        }

        Log::info('[AutoSync] Deleted ' . $deleted . ' archive files');
    }

    public function cleanupExtractedFolders(array $extractedPaths): void
    {
        Log::info('[AutoSync] Cleaning up extracted folders', ['count' => count($extractedPaths)]);

        $deleted = 0;
        foreach ($extractedPaths as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
                $deleted++;
            }
        }

        Log::info('[AutoSync] Deleted ' . $deleted . ' extracted folders');
    }

    public function cleanupOldExtractedFolders(int $daysOld = 7): void
    {
        Log::info('[AutoSync] Cleaning up old extracted folders (older than ' . $daysOld . ' days)');

        if (!File::exists($this->extractedPath)) {
            return;
        }

        $directories = File::directories($this->extractedPath);
        $cutoffTime = now()->subDays($daysOld)->timestamp;
        $deleted = 0;

        foreach ($directories as $dir) {
            $lastModified = File::lastModified($dir);

            if ($lastModified < $cutoffTime) {
                File::deleteDirectory($dir);
                $deleted++;
                Log::debug('[AutoSync] Deleted old folder: ' . basename($dir));
            }
        }

        Log::info('[AutoSync] Deleted ' . $deleted . ' old folders');
    }

    public function cleanupOldDownloads(int $daysOld = 7): void
    {
        Log::info('[AutoSync] Cleaning up old downloads (older than ' . $daysOld . ' days)');

        if (!File::exists($this->downloadsPath)) {
            return;
        }

        $files = File::files($this->downloadsPath);
        $cutoffTime = now()->subDays($daysOld)->timestamp;
        $deleted = 0;

        foreach ($files as $file) {
            $lastModified = File::lastModified($file->getPathname());

            if ($lastModified < $cutoffTime) {
                File::delete($file->getPathname());
                $deleted++;
            }
        }

        Log::info('[AutoSync] Deleted ' . $deleted . ' old download files');
    }

    public function getStorageStats(): array
    {
        $downloadsSize = $this->getDirectorySize($this->downloadsPath);
        $extractedSize = $this->getDirectorySize($this->extractedPath);

        $diskFreeSpace = disk_free_space(storage_path());
        $diskTotalSpace = disk_total_space(storage_path());

        return [
            'downloads_size_mb' => round($downloadsSize / 1024 / 1024, 2),
            'extracted_size_mb' => round($extractedSize / 1024 / 1024, 2),
            'disk_free_gb' => round($diskFreeSpace / 1024 / 1024 / 1024, 2),
            'disk_total_gb' => round($diskTotalSpace / 1024 / 1024 / 1024, 2),
            'disk_usage_percent' => round((($diskTotalSpace - $diskFreeSpace) / $diskTotalSpace) * 100, 2),
        ];
    }

    private function getDirectorySize(string $path): int
    {
        if (!File::exists($path)) {
            return 0;
        }

        $size = 0;
        foreach (File::allFiles($path) as $file) {
            $size += $file->getSize();
        }

        return $size;
    }
}
