<?php

namespace App\Services\DataStream;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class ArchiveService
{
    private string $sevenZipPath;
    private string $extractBasePath;

    public function __construct()
    {
        $defaultPath = PHP_OS_FAMILY === 'Windows'
            ? '"C:\Program Files\7-Zip\7z.exe"'
            : '7z';

        $this->sevenZipPath = env('SEVEN_ZIP_PATH', $defaultPath);
        $this->extractBasePath = storage_path('app/datastream/extracted');

        if (!file_exists($this->extractBasePath)) {
            mkdir($this->extractBasePath, 0755, true);
        }
    }

    public function listArchiveContents(string $archivePath): array
    {
        if (!file_exists($archivePath)) {
            throw new Exception("Archive file not found: {$archivePath}");
        }

        $command = "{$this->sevenZipPath} l \"{$archivePath}\"";
        $output = [];
        $returnCode = 0;
        
        Log::info("Listing archive contents: {$command}");
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new Exception("Failed to list archive contents. Return code: {$returnCode}");
        }

        $files = [];
        $inFileList = false;
        
        foreach ($output as $line) {
            // Start capturing file list after the header
            if (strpos($line, '----------') !== false) {
                $inFileList = !$inFileList;
                continue;
            }

            if ($inFileList && trim($line) !== '') {
                // Parse the line: Date Time Attr Size Compressed Name
                if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s+[\.D][RHS\.A\.]*\s+(\d+)\s+\d*\s*(.+)$/', $line, $matches)) {
                    $size = (int) $matches[1];
                    $filename = trim($matches[2]);
                    
                    // Skip directories
                    if (!str_ends_with($filename, '/')) {
                        $files[] = [
                            'name' => $filename,
                            'size' => $size
                        ];
                    }
                }
            }
        }

        Log::info("Found " . count($files) . " files in archive");
        return $files;
    }

    public function extractArchive(string $archivePath, ?string $destinationPath = null): string
    {
        if (!file_exists($archivePath)) {
            throw new Exception("Archive file not found: {$archivePath}");
        }

        $archiveName = pathinfo($archivePath, PATHINFO_FILENAME);
        $extractPath = $destinationPath ?: $this->extractBasePath . '/' . $archiveName;

        // Clean up existing extraction directory
        if (file_exists($extractPath)) {
            $this->removeDirectory($extractPath);
        }

        // Create extraction directory
        if (!mkdir($extractPath, 0755, true)) {
            throw new Exception("Failed to create extraction directory: {$extractPath}");
        }

        $command = "{$this->sevenZipPath} x \"{$archivePath}\" -o\"{$extractPath}\" -y";
        $output = [];
        $returnCode = 0;
        
        Log::info("Extracting archive: {$command}");
        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("7-Zip extraction failed. Output: " . implode("\n", $output));
            throw new Exception("Failed to extract archive. Return code: {$returnCode}");
        }

        Log::info("Successfully extracted archive to: {$extractPath}");
        return $extractPath;
    }

    public function extractSpecificFiles(string $archivePath, array $fileNames, ?string $destinationPath = null): string
    {
        if (!file_exists($archivePath)) {
            throw new Exception("Archive file not found: {$archivePath}");
        }

        $archiveName = pathinfo($archivePath, PATHINFO_FILENAME);
        $extractPath = $destinationPath ?: $this->extractBasePath . '/' . $archiveName . '_partial';

        // Clean up existing extraction directory
        if (file_exists($extractPath)) {
            $this->removeDirectory($extractPath);
        }

        // Create extraction directory
        if (!mkdir($extractPath, 0755, true)) {
            throw new Exception("Failed to create extraction directory: {$extractPath}");
        }

        foreach ($fileNames as $fileName) {
            $command = "{$this->sevenZipPath} e \"{$archivePath}\" \"{$fileName}\" -o\"{$extractPath}\" -y";
            $output = [];
            $returnCode = 0;
            
            Log::debug("Extracting specific file: {$command}");
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::warning("Failed to extract file {$fileName} from archive. Return code: {$returnCode}");
            }
        }

        Log::info("Successfully extracted specific files to: {$extractPath}");
        return $extractPath;
    }

    public function getExtractedFiles(string $extractPath): array
    {
        if (!file_exists($extractPath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($extractPath . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $files[] = [
                    'name' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'relative_path' => $relativePath,
                    'size' => $file->getSize(),
                    'extension' => $file->getExtension()
                ];
            }
        }

        return $files;
    }

    public function cleanupExtractedFiles(string $extractPath): void
    {
        if (file_exists($extractPath)) {
            $this->removeDirectory($extractPath);
            Log::info("Cleaned up extracted files: {$extractPath}");
        }
    }

    public function getDataStreamCsvFiles(string $extractPath): array
    {
        $csvFiles = [];
        $allFiles = $this->getExtractedFiles($extractPath);

        // Define the important CSV files we're looking for
        $importantFiles = [
            'applications.txt',
            'attributes.txt',
            'groups.txt',
            'images.txt',
            'inventories.txt',
            'parts.txt',
            'pricing.txt',
            'categories.txt',
            'years.txt',
            'makes.txt',
            'models.txt',
            'engines.txt',
            'vehicletypes.txt'
        ];

        foreach ($allFiles as $file) {
            if (strtolower($file['extension']) === 'txt' || 
                in_array(strtolower($file['name']), array_map('strtolower', $importantFiles))) {
                $csvFiles[] = $file;
            }
        }

        return $csvFiles;
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

    public function getExtractBasePath(): string
    {
        return $this->extractBasePath;
    }
}
