<?php

namespace App\Console\Commands\UpdateFiles;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class AnalyzeUpdate extends Command
{
    protected $signature = 'ari:analyze-update 
                            {--path= : Path to update folder (e.g., JonesboroCycleUpdate20240801)}
                            {--dry-run : Show analysis without making changes}';

    protected $description = 'Analyze incremental update folder to identify new, updated, and unchanged products';

    private $basePath;
    private $kits = [];
    private $existingSkus = [];

    public function handle()
    {
        $updateFolder = $this->option('path');
        $dryRun = $this->option('dry-run');

        if (!$updateFolder) {
            $this->error('Please provide --path option');
            return Command::FAILURE;
        }

        $this->basePath = '/var/www/html/test14/storage/app/datastream/extracted/' . $updateFolder;

        if (!File::exists($this->basePath)) {
            $this->error("Update folder not found: {$this->basePath}");
            return Command::FAILURE;
        }

        $this->info("Analyzing update folder: {$updateFolder}");
        $this->info("Path: {$this->basePath}");

        try {
            $this->loadKits();
            $existingSkusArray = DB::table('products')->pluck('sku')->toArray();
            $this->existingSkus = array_flip($existingSkusArray);
            $this->info("Loaded " . count($this->existingSkus) . " existing SKUs from database");

            $analysis = $this->analyzePartmaster();
            
            $this->displayAnalysis($analysis);

            if (!$dryRun) {
                $this->saveAnalysisToFile($updateFolder, $analysis);
            }

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Analysis failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function analyzePartmaster(): array
    {
        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            throw new Exception('Partmaster.txt not found in update folder');
        }

        $this->info('Reading Partmaster.txt...');

        $newProducts = [];
        $existingProducts = [];
        $kitProducts = 0;
        $totalLines = 0;

        $kitsFlipped = array_flip($this->kits);
        $lines = File::lines($file);
        $header = null;

        foreach ($lines as $line) {
            $totalLines++;

            if ($totalLines % 10000 === 0) {
                $this->line("  Reading line {$totalLines}...");
            }

            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if (!$row) continue;
            
            $row = $this->normalizeRowKeys($row);
            if (!isset($row['ID'])) continue;

            foreach ($row as $key => $value) {
                $row[$key] = trim($value, '"');
            }

            $partId = $row['ID'];
            if (isset($kitsFlipped[$partId])) {
                $kitProducts++;
                continue;
            }

            $sku = $this->getProductSku($row);

            if (isset($this->existingSkus[$sku])) {
                $existingProducts[] = $sku;
            } else {
                $newProducts[] = [
                    'sku' => $sku,
                    'name' => $row['ItemName'] ?? 'N/A',
                    'partmaster_id' => $partId
                ];
            }
        }

        $this->info("Total lines read: {$totalLines}");

        return [
            'update_folder' => basename($this->basePath),
            'total_products_in_update' => count($newProducts) + count($existingProducts),
            'new_products' => $newProducts,
            'new_products_count' => count($newProducts),
            'existing_products_count' => count($existingProducts),
            'kit_products_skipped' => $kitProducts,
            'total_in_database' => count($this->existingSkus)
        ];
    }

    private function displayAnalysis(array $analysis): void
    {
        $this->line('');
        $this->info('=== ANALYSIS RESULTS ===');
        $this->line('');
        
        $this->table(['Metric', 'Count'], [
            ['Update Folder', $analysis['update_folder']],
            ['Total Products in Update', number_format($analysis['total_products_in_update'])],
            ['New Products (to create)', number_format($analysis['new_products_count'])],
            ['Existing Products (to update)', number_format($analysis['existing_products_count'])],
            ['Kit Products (skipped)', number_format($analysis['kit_products_skipped'])],
            ['Total in Database', number_format($analysis['total_in_database'])]
        ]);

        $this->line('');
        
        if ($analysis['new_products_count'] > 0) {
            $this->info('Sample of NEW products (first 10):');
            $sample = array_slice($analysis['new_products'], 0, 10);
            foreach ($sample as $product) {
                $this->line("  SKU: {$product['sku']} - {$product['name']}");
            }
            if ($analysis['new_products_count'] > 10) {
                $this->line("  ... and " . ($analysis['new_products_count'] - 10) . " more");
            }
        } else {
            $this->warn('No new products found in this update');
        }

        $this->line('');
    }

    private function saveAnalysisToFile(string $updateFolder, array $analysis): void
    {
        $filename = storage_path("logs/update_analysis_{$updateFolder}_" . date('Y-m-d_His') . ".json");
        File::put($filename, json_encode($analysis, JSON_PRETTY_PRINT));
        $this->info("Analysis saved to: {$filename}");
    }

    private function loadKits(): void
    {
        $file = $this->basePath . '/Kits.txt';
        if (!File::exists($file)) {
            $this->warn('Kits.txt not found in update folder');
            return;
        }

        $lines = File::lines($file);
        $header = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if (!$row) continue;
            
            $row = $this->normalizeRowKeys($row);
            if (isset($row['Primary_PartmasterID'])) {
                $this->kits[] = trim($row['Primary_PartmasterID'], '"');
            }
        }

        $this->info("Loaded " . count($this->kits) . " kit products from update folder");
    }

    private function getProductSku(array $product): string
    {
        $long = $product['ManufacturerNumberLong'] ?? '';
        $short = $product['ManufacturerNumberShort'] ?? '';
        $id = $product['ID'] ?? '';

        return $long ?: ($short ?: "ARI-{$id}");
    }

    private function normalizeRowKeys(array $row): array
    {
        $normalized = [];
        $keyMap = [
            'id' => 'ID',
            'manufacturernumberlong' => 'ManufacturerNumberLong',
            'manufacturernumbershort' => 'ManufacturerNumberShort',
            'itemname' => 'ItemName',
            'itemdescription' => 'ItemDescription',
            'manufacturerid' => 'ManufacturerID',
            'primary_partmasterid' => 'Primary_PartmasterID'
        ];

        foreach ($row as $key => $value) {
            $lowerKey = strtolower($key);
            $normalizedKey = $keyMap[$lowerKey] ?? $key;
            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }
}
