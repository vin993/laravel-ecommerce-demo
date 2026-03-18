<?php

namespace App\Services\DataStream;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Kawasaki FTP Service
 * 
 * Extends base FTP service with Kawasaki-specific configuration
 */
class KawasakiFtpService extends FtpService
{
    protected string $host;
    protected int $port;
    protected string $username;
    protected string $password;
    protected string $localStoragePath;
    /** @var resource|object|null */
    protected $ftpConnection = null;

    public function __construct()
    {
        parent::__construct();
        
        // Override with Kawasaki FTP credentials from .env
        $this->host = env('KAWASAKI_FTP_HOST', 'kawasakidata.gofuse.com');
        $this->port = env('KAWASAKI_FTP_PORT', 21);
        $this->username = env('KAWASAKI_FTP_USERNAME', 'Owner10139');
        $this->password = env('KAWASAKI_FTP_PASSWORD', 'Ninja10155!');
        $this->localStoragePath = storage_path('app/kawasaki/downloads');
        
        // Create local storage directory if it doesn't exist
        if (!file_exists($this->localStoragePath)) {
            mkdir($this->localStoragePath, 0755, true);
        }
        
        Log::info("KawasakiFtpService initialized with host: {$this->host}");
    }

    /**
     * Connect using plain FTP (not SFTP)
     */
    public function connect(): void
    {
        try {
            Log::channel('kawasaki_sync')->info("Connecting to Kawasaki FTP: {$this->host}:{$this->port}");

            $this->ftpConnection = ftp_connect($this->host, $this->port, 30);
            if (!$this->ftpConnection) {
                throw new Exception("Failed to connect to Kawasaki FTP server {$this->host}:{$this->port}");
            }

            if (!ftp_login($this->ftpConnection, $this->username, $this->password)) {
                ftp_close($this->ftpConnection);
                $this->ftpConnection = null;
                throw new Exception("Failed to authenticate to Kawasaki FTP server {$this->host}:{$this->port}");
            }

            ftp_pasv($this->ftpConnection, true);
            Log::channel('kawasaki_sync')->info("Successfully connected to Kawasaki FTP server");
        } catch (Exception $e) {
            Log::channel('kawasaki_sync')->error('Kawasaki FTP connection failed', [
                'host' => $this->host,
                'port' => $this->port,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function disconnect(): void
    {
        if ($this->ftpConnection !== null) {
            ftp_close($this->ftpConnection);
        }

        $this->ftpConnection = null;
        Log::channel('kawasaki_sync')->info("Kawasaki FTP connection closed");
    }

    /**
     * List files using plain FTP
     */
    public function listFiles(string $remotePath = '.'): array
    {
        try {
            if ($this->ftpConnection === null) {
                throw new Exception('Kawasaki FTP is not connected. Call connect() before listFiles().');
            }

            Log::channel('kawasaki_sync')->info("Listing files from Kawasaki FTP server (path: {$remotePath})");

            $files = $this->listFilesFromDirectory($remotePath === '' ? '.' : $remotePath);

            if ($remotePath === '.' || $remotePath === '') {
                $partsDataFiles = $this->listFilesFromDirectory('PartsData', 'Kawasaki');
                $files = array_merge($files, $partsDataFiles);
            }

            Log::channel('kawasaki_sync')->info("Listed " . count($files) . " files from Kawasaki FTP server");
            return $files;
        } catch (Exception $e) {
            Log::channel('kawasaki_sync')->error('Kawasaki FTP listFiles failed', [
                'remote_path' => $remotePath,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    protected function listFilesFromDirectory(string $directory, ?string $brand = null): array
    {
        $rawList = ftp_rawlist($this->ftpConnection, $directory);
        if ($rawList === false) {
            $rawList = [];
        }

        $files = [];

        foreach ($rawList as $line) {
            $fileInfo = $this->parseRawListLine($line, $directory, $brand);
            if ($fileInfo === null) {
                continue;
            }

            $files[] = $fileInfo;
        }

        if (!empty($files)) {
            return $files;
        }

        $nlist = ftp_nlist($this->ftpConnection, $directory);
        if ($nlist === false) {
            return [];
        }

        foreach ($nlist as $entry) {
            $name = basename(trim($entry));
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            $baseDir = trim($directory, './');
            $path = $baseDir === '' ? $name : $baseDir . '/' . $name;
            $files[] = [
                'name' => $name,
                'path' => $path,
                'size' => 0,
                'modified' => date('Y-m-d H:i:s'),
                'is_dir' => false,
            ];

            if ($brand) {
                $files[count($files) - 1]['brand'] = $brand;
            }
        }

        return $files;
    }

    protected function parseRawListLine(string $line, string $directory, ?string $brand = null): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        if (!preg_match('/^([\-ld])[rwx\-]{9}\s+\d+\s+\S+\s+\S+\s+(\d+)\s+([A-Za-z]{3})\s+(\d{1,2})\s+(\d{4}|\d{1,2}:\d{2})\s+(.+)$/', $line, $matches)) {
            return null;
        }

        $type = $matches[1];
        $size = (int) $matches[2];
        $month = $matches[3];
        $day = $matches[4];
        $yearOrTime = $matches[5];
        $name = trim($matches[6]);

        if ($name === '.' || $name === '..') {
            return null;
        }

        $isDir = $type === 'd';
        if ($isDir) {
            return null;
        }

        if (str_contains($yearOrTime, ':')) {
            $modifiedTs = strtotime("{$month} {$day} " . date('Y') . " {$yearOrTime}");
        } else {
            $modifiedTs = strtotime("{$month} {$day} {$yearOrTime} 00:00");
        }

        $baseDir = trim($directory, './');
        $path = $baseDir === '' ? $name : $baseDir . '/' . $name;

        $result = [
            'name' => $name,
            'path' => $path,
            'size' => $size,
            'modified' => $modifiedTs ? date('Y-m-d H:i:s', $modifiedTs) : date('Y-m-d H:i:s'),
            'is_dir' => false,
        ];

        if ($brand) {
            $result['brand'] = $brand;
        }

        return $result;
    }

    /**
     * Download file using plain FTP (not SFTP)
     */
    public function downloadFile(array $fileInfo, $progressCallback = null): string
    {
        try {
            if ($this->ftpConnection === null) {
                throw new Exception('Kawasaki FTP is not connected. Call connect() before downloadFile().');
            }

            $remotePath = $fileInfo['path'];
            $localPath = $this->localStoragePath . '/' . basename($fileInfo['name'] ?? $remotePath);

            if (isset($fileInfo['brand']) && $fileInfo['brand'] === 'Kawasaki') {
                $brandDir = $this->localStoragePath . '/Kawasaki';
                if (!file_exists($brandDir)) {
                    mkdir($brandDir, 0755, true);
                }
                $localPath = $brandDir . '/' . basename($fileInfo['name'] ?? $remotePath);
            }

            Log::channel('kawasaki_sync')->info("Downloading {$remotePath} to {$localPath}");

            if (!ftp_get($this->ftpConnection, $localPath, $remotePath, FTP_BINARY)) {
                throw new Exception("Download failed from FTP: {$remotePath}");
            }

            // Verify download
            if (!file_exists($localPath)) {
                throw new Exception("Download failed - file not found: {$remotePath}");
            }

            $actualSize = filesize($localPath);
            if ($actualSize === 0) {
                throw new Exception("Download failed - empty file: {$remotePath}");
            }

            Log::channel('kawasaki_sync')->info("Downloaded {$remotePath}: size={$actualSize} bytes");

            return $localPath;
        } catch (Exception $e) {
            Log::channel('kawasaki_sync')->error('Kawasaki FTP download failed', [
                'remote_path' => $fileInfo['path'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
