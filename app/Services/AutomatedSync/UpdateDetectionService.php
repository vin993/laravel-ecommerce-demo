<?php

namespace App\Services\AutomatedSync;

use App\Services\DataStream\FtpService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateDetectionService
{
    private FtpService $ftpService;

    public function __construct(FtpService $ftpService)
    {
        $this->ftpService = $ftpService;
    }

    public function detectNewUpdateFiles(): array
    {
        Log::info('[AutoSync] Detecting new update files on FTP server');

        $this->ftpService->connect();
        $allFiles = $this->ftpService->listFiles();
        $this->ftpService->disconnect();

        $updateFiles = $this->filterUpdateFiles($allFiles);
        $newFiles = $this->filterUnprocessedFiles($updateFiles);
        $sortedFiles = $this->sortFilesByDate($newFiles);

        Log::info('[AutoSync] Found ' . count($sortedFiles) . ' new update files', [
            'files' => array_column($sortedFiles, 'name')
        ]);

        return $sortedFiles;
    }

    private function filterUpdateFiles(array $files): array
    {
        return array_filter($files, function($file) {
            $name = $file['name'];
            return str_contains($name, 'Update') && str_ends_with($name, '.7z');
        });
    }

    private function filterUnprocessedFiles(array $files): array
    {
        $unprocessed = [];

        foreach ($files as $file) {
            if (!$this->isFileProcessed($file)) {
                $unprocessed[] = $file;
            }
        }

        return $unprocessed;
    }

    private function isFileProcessed(array $fileInfo): bool
    {
        $tracking = DB::table('ari_ftp_file_tracking')
            ->where('filename', $fileInfo['name'])
            ->where('processed_by_automation', true)
            ->where('status', 'processed')
            ->first();

        return $tracking !== null;
    }

    private function sortFilesByDate(array $files): array
    {
        usort($files, function($a, $b) {
            $dateA = $this->extractDateFromFilename($a['name']);
            $dateB = $this->extractDateFromFilename($b['name']);
            return $dateA <=> $dateB;
        });

        return $files;
    }

    private function extractDateFromFilename(string $filename): string
    {
        if (preg_match('/Update(\d{8})/', $filename, $matches)) {
            return $matches[1];
        }
        return '00000000';
    }

    public function getLatestProcessedUpdate(): ?string
    {
        $latest = DB::table('ari_ftp_file_tracking')
            ->where('file_type', 'update')
            ->where('processed_by_automation', true)
            ->where('status', 'processed')
            ->orderBy('remote_modified_at', 'desc')
            ->first();

        return $latest ? $latest->filename : null;
    }
}
