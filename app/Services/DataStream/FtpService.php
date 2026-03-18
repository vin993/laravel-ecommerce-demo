<?php

namespace App\Services\DataStream;

use Illuminate\Support\Facades\DB;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class FtpService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $localStoragePath;

    public function __construct()
    {
        $this->host = env('DATASTREAM_FTP_HOST', 'data.ari-accessorydata.com');
        $this->port = env('DATASTREAM_FTP_PORT', 22);
        $this->username = env('DATASTREAM_FTP_USERNAME', 'Jonesborocycle');
        $this->password = env('DATASTREAM_FTP_PASSWORD', 'Duffel!Earwig1!Happening');
        $this->localStoragePath = storage_path('app/datastream/downloads');

        // Create local storage directory if it doesn't exist
        if (!file_exists($this->localStoragePath)) {
            mkdir($this->localStoragePath, 0755, true);
        }
    }

    public function connect(): void
    {
        Log::info("Preparing SFTP connection to: {$this->host}:{$this->port}");

        // Test connection with a simple command
        $result = $this->executeSftpCommand('ls');
        if ($result === false || $result === null || empty(trim($result))) {
            throw new Exception("Failed to connect to SFTP server {$this->host}:{$this->port}");
        }

        Log::info("Successfully connected to SFTP server");
    }

    public function disconnect(): void
    {
        // No persistent connection to disconnect
        Log::info("SFTP connection closed");
    }

    public function listFiles(string $remotePath = '.'): array
    {
        Log::info("Listing files from SFTP server");

        $files = [];

        // List files in root directory
        $output = $this->executeSftpCommand("ls -la {$remotePath}");
        if ($output !== false && $output !== null && !empty(trim($output))) {
            $files = array_merge($files, $this->parseFileList($output, $remotePath));
        }

        // List files in brand directories
        $brandDirs = ['Honda', 'Kawasaki', 'Yamaha', 'Polaris', 'SeaDoo', 'HelmetHouse', 'PartsUnlimited', 'Sullivans'];
        foreach ($brandDirs as $brand) {
            $output = $this->executeSftpCommand("ls -la {$brand}");
            if ($output !== false && $output !== null && !empty(trim($output))) {
                $brandFiles = $this->parseFileList($output, $brand);
                foreach ($brandFiles as &$file) {
                    $file['brand'] = $brand;
                }
                $files = array_merge($files, $brandFiles);
            }
        }

        Log::info("Listed " . count($files) . " files from SFTP server");
        return $files;
    }

    public function downloadFile(array $fileInfo, $progressCallback = null): string
    {
        $remotePath = $fileInfo['path'];
        $localPath = $this->localStoragePath . '/' . basename($fileInfo['name']);

        // Create subdirectory if it's a brand file
        if (isset($fileInfo['brand'])) {
            $brandDir = $this->localStoragePath . '/' . $fileInfo['brand'];
            if (!file_exists($brandDir)) {
                mkdir($brandDir, 0755, true);
            }
            $localPath = $brandDir . '/' . basename($fileInfo['name']);
        }

        Log::info("Downloading {$remotePath} to {$localPath}");

        // Download using curl SFTP with progress
        $url = "sftp://{$this->host}/{$remotePath}";
        
        // Debug logging
        Log::info("Download URL: {$url}");
        Log::info("Local path: {$localPath}");
        Log::info("Remote path: {$remotePath}");
        
        if ($progressCallback && is_callable($progressCallback)) {
            // Download with progress monitoring
            $this->downloadWithProgress($url, $localPath, $fileInfo, $progressCallback);
        } else {
            // Regular download without progress
            $command = sprintf(
                'curl -u %s:%s --insecure "%s" -o "%s" --verbose',
                escapeshellarg($this->username),
                escapeshellarg($this->password),
                $url,
                $localPath
            );
            Log::info("Executing command: {$command}");
            $result = shell_exec($command);
            Log::info("Command result: {$result}");
        }

        // Verify download - check if file exists and has reasonable content
        if (!file_exists($localPath)) {
            throw new Exception("Download failed - file not found: {$remotePath}");
        }
        
        $actualSize = filesize($localPath);
        if ($actualSize === 0) {
            throw new Exception("Download failed - empty file: {$remotePath}");
        }
        
        // Log actual vs expected file size (expected sizes are estimates)
        Log::info("Downloaded {$remotePath}: estimated={$fileInfo['size']}, actual={$actualSize}");
        
        // Update fileInfo with actual size for tracking
        $fileInfo['size'] = $actualSize;

        // Track the download
        $this->trackFileDownload($fileInfo, $localPath);

        Log::info("Successfully downloaded {$remotePath}");
        return $localPath;
    }

    public function isFileAlreadyProcessed(array $fileInfo): bool
    {
        try {
            $tracking = DB::table('ari_ftp_file_tracking')
                ->where('filename', $fileInfo['name'])
                ->where('file_size', $fileInfo['size'])
                ->where('remote_modified_at', $fileInfo['modified'])
                ->where('status', 'processed')
                ->first();

            return $tracking !== null;
        } catch (Exception $e) {
            Log::warning('Failed to check file processing status: ' . $e->getMessage());
            return false; // Assume not processed if we can't check
        }
    }

    private function executeSftpCommand(string $command)
    {
        // Use curl SFTP instead of SSH/SFTP batch mode
        if (strpos($command, 'ls') === 0) {
            // Extract path from ls command
            $path = trim(str_replace(['ls -la', 'ls'], '', $command));
            if (empty($path) || $path === '.') {
                $path = '';
            } else {
                $path = trim($path, '/');
            }

            // Build curl SFTP URL
            $url = "sftp://{$this->host}";
            if (!empty($path)) {
                $url .= "/{$path}/";
            } else {
                $url .= "/";
            }

            // Use curl to list directory contents
            $curlCommand = sprintf(
                'curl -u %s:%s --insecure "%s" --list-only 2>/dev/null',
                escapeshellarg($this->username),
                escapeshellarg($this->password),
                $url
            );

            $output = shell_exec($curlCommand);

            // Convert simple file list to ls -la format for parsing
            if (!empty($output)) {
                $output = $this->convertCurlOutputToLsFormat($output, $path);
            }

            return $output;
        }

        // For non-ls commands, return empty (not supported with curl)
        return '';
    }

    private function parseFileList(string $output, string $basePath = '.'): array
    {
        $files = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            // Parse ls -la output: permissions links owner group size date time filename
            if (preg_match('/^-[rwx-]+\s+\d+\s+\w+\s+\w+\s+(\d+)\s+(\w+\s+\d+\s+[\d:]+)\s+(.+)$/', trim($line), $matches)) {
                $size = (int)$matches[1];
                $dateStr = $matches[2];
                $filename = $matches[3];

                // Skip . and .. entries
                if ($filename === '.' || $filename === '..') {
                    continue;
                }

                $filePath = $basePath === '.' ? $filename : $basePath . '/' . $filename;

                $files[] = [
                    'name' => $filename,
                    'path' => $filePath,
                    'size' => $size,
                    'modified' => date('Y-m-d H:i:s', strtotime($dateStr)),
                    'is_dir' => false
                ];
            }
        }

        return $files;
    }

    private function trackFileDownload(array $fileInfo, string $localPath): void
    {
        try {
            $fileHash = hash_file('md5', $localPath);

            DB::table('ari_ftp_file_tracking')->updateOrInsert(
                [
                    'filename' => $fileInfo['name']
                ],
                [
                    'file_type' => $this->determineFileType($fileInfo['name']),
                    'remote_path' => $fileInfo['path'],
                    'file_size' => $fileInfo['size'],
                    'file_hash' => $fileHash,
                    'remote_modified_at' => $fileInfo['modified'],
                    'last_downloaded_at' => now(),
                    'status' => 'downloaded',
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );
        } catch (Exception $e) {
            Log::warning('Failed to track file download: ' . $e->getMessage());
        }
    }

    private function determineFileType(string $filename): string
    {
        if (str_contains($filename, 'Full.7z')) {
            return 'main';
        } elseif (str_contains($filename, 'Update')) {
            return 'update';
        } else {
            return 'image';
        }
    }

    public function getLocalStoragePath(): string
    {
        return $this->localStoragePath;
    }

    private function convertCurlOutputToLsFormat(string $curlOutput, string $path = ''): string
    {
        $lines = explode("\n", trim($curlOutput));
        $lsFormatLines = [];

        foreach ($lines as $line) {
            $filename = trim($line);
            if (empty($filename) || $filename === '.' || $filename === '..') {
                continue;
            }

            // Skip directories (they don't have extensions or are known directories)
            $knownDirs = ['Honda', 'Kawasaki', 'Yamaha', 'Polaris', 'SeaDoo', 'HelmetHouse', 'PartsUnlimited', 'Sullivans'];
            if (in_array($filename, $knownDirs)) {
                continue;
            }

            // Use 0 as file size since we don't know the real size
            // This will cause the progress to show as unknown initially
            $fileSize = 0;

            // Create fake ls -la format for file parsing
            $lsFormatLines[] = sprintf(
                "-rw-r--r-- 1 owner group %d %s %s",
                $fileSize,
                date('M d H:i'), // current date as placeholder
                $filename
            );
        }

        return implode("\n", $lsFormatLines);
    }

    private function downloadWithProgress(string $url, string $localPath, array $fileInfo, callable $progressCallback): void
    {
        // Log the download details for debugging
        Log::info("Starting download with progress: {$url} to {$localPath}");
        
        $expectedSize = $fileInfo['size'] ?? 0;
        $startTime = time();
        
        // For now, let's use a simple synchronous download with periodic progress updates
        $command = sprintf(
            'curl -u %s:%s --insecure "%s" -o "%s"',
            escapeshellarg($this->username),
            escapeshellarg($this->password),
            $url,
            $localPath
        );
        
        Log::info("Executing download command: {$command}");
        
        // Initial progress update
        call_user_func($progressCallback, [
            'downloaded' => 0,
            'total' => $expectedSize,
            'progress' => 0,
            'speed' => 0,
            'filename' => basename($fileInfo['name'])
        ]);
        
        // Start the download and monitor it
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        $process = proc_open($command, $descriptorSpec, $pipes);
        
        if (is_resource($process)) {
            // Close stdin
            fclose($pipes[0]);
            
            // Monitor progress by checking file size
            $lastSize = 0;
            $maxWaitTime = 600; // 10 minutes max wait
            $waitStart = time();
            $noProgressCount = 0;
            
            while (true) {
                // Clear file status cache to get accurate file size
                clearstatcache(true, $localPath);
                $currentSize = file_exists($localPath) ? filesize($localPath) : 0;
                $elapsed = time() - $startTime;
                
                // Always update progress, even if size hasn't changed (for activity indication)
                $progress = $expectedSize > 0 ? min(100, ($currentSize / $expectedSize) * 100) : 0;
                $speed = $elapsed > 0 ? ($currentSize / $elapsed) : 0;
                
                call_user_func($progressCallback, [
                    'downloaded' => $currentSize,
                    'total' => $expectedSize,
                    'progress' => $progress,
                    'speed' => $speed,
                    'filename' => basename($fileInfo['name'])
                ]);
                
                if ($currentSize > $lastSize) {
                    $lastSize = $currentSize;
                    $noProgressCount = 0;
                    Log::debug("Download progress: {$currentSize} bytes, speed: {$speed} B/s");
                } else {
                    $noProgressCount++;
                }
                
                // Check if process is still running
                $status = proc_get_status($process);
                if (!$status['running']) {
                    Log::info("Download process finished with exit code: {$status['exitcode']}");
                    break;
                }
                
                // Check for timeout
                if (time() - $waitStart > $maxWaitTime) {
                    Log::warning("Download timeout after {$maxWaitTime} seconds");
                    proc_terminate($process);
                    break;
                }
                
                // Wait before next check
                usleep(1000000); // 1 second for better responsiveness
            }
            
            // Get any error output
            $errors = stream_get_contents($pipes[2]);
            if (!empty($errors)) {
                Log::warning("Download errors: {$errors}");
            }
            
            // Close pipes and process
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        
        // Final progress update
        $finalSize = file_exists($localPath) ? filesize($localPath) : 0;
        call_user_func($progressCallback, [
            'downloaded' => $finalSize,
            'total' => $expectedSize,
            'progress' => 100,
            'speed' => 0,
            'filename' => basename($fileInfo['name']),
            'completed' => true
        ]);
        
        Log::info("Download completed. Final size: {$finalSize} bytes");
    }
}
