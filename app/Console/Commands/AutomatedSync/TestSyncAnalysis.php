<?php

namespace App\Console\Commands\AutomatedSync;

use App\Services\AutomatedSync\UpdateDetectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class TestSyncAnalysis extends Command
{
    protected $signature = 'sync:test-analysis
                            {--show-files : Show list of files that would be processed}';

    protected $description = 'Analyze what the automated sync would process (safe, read-only)';

    private UpdateDetectionService $detectionService;

    public function __construct(UpdateDetectionService $detectionService)
    {
        parent::__construct();
        $this->detectionService = $detectionService;
    }

    public function handle()
    {
        $this->info('========================================');
        $this->info('AUTOMATED SYNC ANALYSIS (READ-ONLY)');
        $this->info('========================================');
        $this->newLine();

        $this->info('Step 1: Current Database State');
        $this->displayCurrentStats();
        $this->newLine();

        $this->info('Step 2: Detecting Unprocessed Files');
        $newFiles = $this->detectionService->detectNewUpdateFiles();

        if (empty($newFiles)) {
            $this->warn('No new update files detected.');
            $this->info('All files have been processed.');
            return 0;
        }

        $this->info('Found ' . count($newFiles) . ' unprocessed files:');
        $this->newLine();

        foreach ($newFiles as $file) {
            $fileName = $file['name'];
            $this->line("  - {$fileName}");
            $this->line("    Size: " . $this->formatBytes($file['size'] ?? 0));

            $extractedDate = $this->extractDateFromFilename($fileName);
            if ($extractedDate) {
                $this->line("    Date: {$extractedDate}");
            }

            if ($this->option('show-files')) {
                $folderName = str_replace('.7z', '', $fileName);
                $this->analyzeUpdateFolder($folderName);
            }

            $this->newLine();
        }

        $this->info('Step 3: Operations That Would Run');
        $this->displayOperationsList();
        $this->newLine();

        $this->info('Step 4: Estimated Processing Time');
        $this->displayTimeEstimate(count($newFiles));
        $this->newLine();

        $this->info('========================================');
        $this->info('To run actual sync:');
        $this->warn('sudo -u www-data php artisan sync:automated-ftp-sync');
        $this->info('========================================');

        return 0;
    }

    private function displayCurrentStats(): void
    {
        $stats = [
            ['Metric', 'Current Count'],
            ['Total Products', number_format($this->safeCount('products'))],
            ['Configurable Products', number_format(DB::table('products')->where('type', 'configurable')->count())],
            ['Variant Groups', number_format($this->safeCount('ds_variant_groups'))],
            ['Product Flat Records', number_format($this->safeCount('product_flat'))],
            ['Product Categories', number_format($this->safeCount('product_categories'))],
            ['Products with Brands', number_format(DB::table('product_attribute_values')->where('attribute_id', 25)->count())],
            ['Products with Images', number_format(DB::table('product_images')->distinct('product_id')->count())],
            ['Vehicle Fitment Links', number_format($this->safeCount('product_vehicle_fitment'))],
        ];

        $this->table($stats[0], array_slice($stats, 1));
    }

    private function safeCount(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function analyzeUpdateFolder(string $folderName): void
    {
        $folderPath = storage_path("app/datastream/extracted/{$folderName}");

        if (!File::exists($folderPath)) {
            $this->warn("    Folder not found on disk: {$folderPath}");
            return;
        }

        $files = File::files($folderPath);
        $this->line("    Contains " . count($files) . " files:");

        $importantFiles = [
            'Partmaster.txt' => 'Products',
            'PartPriceInv.txt' => 'Prices & Inventory',
            'Categories.txt' => 'Categories',
            'ManufacturerIndex.txt' => 'Brands',
            'Images.txt' => 'Image References',
            'VehicleTypes.txt' => 'Vehicle Types',
            'Makes.txt' => 'Vehicle Makes',
            'Models.txt' => 'Vehicle Models',
            'Years.txt' => 'Vehicle Years',
            'TypeMakeModelYear.txt' => 'Vehicle Combinations',
            'Fitment.txt' => 'Fitment Data',
        ];

        foreach ($importantFiles as $fileName => $description) {
            $filePath = $folderPath . '/' . $fileName;
            if (File::exists($filePath)) {
                $size = File::size($filePath);
                $this->line("      ✓ {$description}: " . $this->formatBytes($size));
            }
        }
    }

    private function displayOperationsList(): void
    {
        $operations = [
            ['Operation', 'Description', 'Risk Level'],
            ['Create Products', 'Insert new products from update folder', 'Safe'],
            ['Update Products', 'Update existing product data', 'Safe'],
            ['Sync Attributes', 'Update product specifications', 'Safe'],
            ['Map Categories', 'Assign products to categories', 'Safe'],
            ['Assign Brands', 'Link products to manufacturers', 'Safe'],
            ['Build Variants', 'Create configurable product groups', 'Safe'],
            ['Sync Vehicle Fitment', 'Update vehicle compatibility data', 'Safe'],
            ['Sync Images', 'Download and link product images', 'Safe'],
            ['Rebuild Product Flat', 'Refresh product search index', 'Safe'],
        ];

        $this->table($operations[0], array_slice($operations, 1));
        $this->newLine();
        $this->info('All operations use transactions and error handling.');
        $this->info('Existing data is preserved - only new/updated records are affected.');
    }

    private function displayTimeEstimate(int $fileCount): void
    {
        $estimatePerFile = 30;
        $totalMinutes = $fileCount * $estimatePerFile;
        $hours = floor($totalMinutes / 60);
        $minutes = $totalMinutes % 60;

        $this->info("Processing {$fileCount} update folder(s):");
        $this->line("  Estimated time: ~{$hours}h {$minutes}m (varies by file size)");
        $this->line("  Small folders (<100MB): 15-30 minutes each");
        $this->line("  Large folders (>100MB): 1-3 hours each");
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    private function extractDateFromFilename(string $filename): ?string
    {
        if (preg_match('/Update(\d{4})(\d{2})(\d{2})/', $filename, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }
        return null;
    }
}
