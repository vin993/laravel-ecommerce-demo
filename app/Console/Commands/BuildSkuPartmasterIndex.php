<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BuildSkuPartmasterIndex extends Command
{
    protected $signature = 'datastream:build-sku-index';
    protected $description = 'Build SKU to Partmaster ID index';

    private $basePath;

    public function handle()
    {
        $this->info('Building SKU-Partmaster index...');

        $this->basePath = $this->detectLatestExtractedPath();
        if (!$this->basePath) {
            $this->error('No extracted data folders found');
            return Command::FAILURE;
        }

        $this->info("Reading from: {$this->basePath}");

        try {
            $this->buildIndex();
            $this->info('SKU-Partmaster index built successfully!');

            $count = DB::table('ds_sku_partmaster_index')->count();
            $this->info("Total records: {$count}");

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function buildIndex(): void
    {
        $this->info('Creating index table...');

        DB::statement('DROP TABLE IF EXISTS ds_sku_partmaster_index');
        DB::statement('
            CREATE TABLE ds_sku_partmaster_index (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sku VARCHAR(100) NOT NULL,
                partmaster_id VARCHAR(50) NOT NULL,
                INDEX idx_sku (sku),
                INDEX idx_partmaster (partmaster_id)
            )
        ');

        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            $this->error('Partmaster.txt not found');
            return;
        }

        $this->info('Reading Partmaster file...');
        $lines = File::lines($file);
        $header = null;
        $data = [];
        $batch = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $csv = str_getcsv($line);
            if (!$header) {
                $header = array_map(fn($h) => trim($h, '"'), $csv);
                continue;
            }

            if (count($header) !== count($csv)) continue;

            $row = array_combine($header, $csv);
            if ($row && isset($row['ID'])) {
                $partmasterId = trim($row['ID'], '"');
                $long = trim($row['ManufacturerNumberLong'] ?? '', '"');
                $short = trim($row['ManufacturerNumberShort'] ?? '', '"');

                if ($long) {
                    $data[] = [
                        'sku' => $long,
                        'partmaster_id' => $partmasterId,
                    ];
                } elseif ($short) {
                    $data[] = [
                        'sku' => $short,
                        'partmaster_id' => $partmasterId,
                    ];
                }

                $data[] = [
                    'sku' => "ARI-{$partmasterId}",
                    'partmaster_id' => $partmasterId,
                ];

                if (count($data) >= 5000) {
                    DB::table('ds_sku_partmaster_index')->insert($data);
                    $batch += count($data);
                    $data = [];
                    $this->line("Progress: {$batch} records");
                }
            }
        }

        if (!empty($data)) {
            DB::table('ds_sku_partmaster_index')->insert($data);
            $batch += count($data);
        }

        $this->line("Total: {$batch} records");
    }

    private function detectLatestExtractedPath(): ?string
    {
        $baseExtractedPath = '/var/www/html/test14/storage/app/datastream/extracted';

        $fullPath = $baseExtractedPath . '/JonesboroCycleFull';
        if (File::exists($fullPath . '/Partmaster.txt')) {
            return $fullPath;
        }

        if (File::exists($baseExtractedPath)) {
            $directories = File::directories($baseExtractedPath);
            $updateFolders = [];

            foreach ($directories as $dir) {
                $folderName = basename($dir);
                if (strpos($folderName, 'JonesboroCycleUpdate') === 0) {
                    $updateFolders[] = $dir;
                }
            }

            if (!empty($updateFolders)) {
                rsort($updateFolders);
                foreach ($updateFolders as $folder) {
                    if (File::exists($folder . '/Partmaster.txt')) {
                        return $folder;
                    }
                }
            }
        }

        return null;
    }
}
