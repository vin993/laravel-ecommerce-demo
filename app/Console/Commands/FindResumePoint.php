<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FindResumePoint extends Command
{
    protected $signature = 'ari:find-resume-point';
    protected $description = 'Find the correct skip value to resume product import';

    private $basePath;

    public function handle()
    {
        $this->info('Finding resume point for product import...');
        $this->newLine();

        $this->basePath = '/var/www/html/test14/storage/app/datastream/extracted/JonesboroCycleFull';

        if (!File::exists($this->basePath . '/Partmaster.txt')) {
            $this->error('Partmaster.txt not found');
            return Command::FAILURE;
        }

        // Get last 10 imported products
        $lastProducts = DB::table('products')
            ->orderBy('id', 'desc')
            ->limit(10)
            ->pluck('sku', 'id')
            ->toArray();

        $this->info('Last 10 imported products:');
        $this->table(['ID', 'SKU'],
            array_map(fn($id, $sku) => [$id, $sku], array_keys($lastProducts), $lastProducts)
        );
        $this->newLine();

        // Load kits to filter
        $this->info('Loading kit products to filter...');
        $kits = DB::table('ds_kit_index')->pluck('primary_partmaster_id')->toArray();
        $kitsFlipped = array_flip($kits);
        $this->info('Loaded ' . count($kits) . ' kit products to filter');
        $this->newLine();

        // Scan Partmaster to find last imported SKU
        $this->info('Scanning Partmaster.txt to find resume point...');
        $this->info('(This may take 2-3 minutes)');
        $this->newLine();

        $file = $this->basePath . '/Partmaster.txt';
        $lines = File::lines($file);
        $header = null;
        $lineNumber = 0;
        $productCount = 0;
        $lastFoundLine = 0;
        $lastFoundSku = null;

        $lastSkus = array_values($lastProducts);
        $targetSku = $lastSkus[0]; // Most recent SKU

        $this->info("Looking for SKU: {$targetSku}");

        foreach ($lines as $line) {
            $lineNumber++;

            if ($lineNumber === 1) {
                $header = str_getcsv(trim($line));
                $header = array_map(fn($h) => trim($h, '"'), $header);
                continue;
            }

            $data = str_getcsv(trim($line));
            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            foreach ($row as $key => $value) {
                $row[$key] = trim($value, '"');
            }

            // Filter out kits
            $partId = $row['ID'] ?? null;
            if ($partId && isset($kitsFlipped[$partId])) {
                continue;
            }

            $productCount++;

            // Build SKU same way as import command
            $long = $row['ManufacturerNumberLong'] ?? '';
            $short = $row['ManufacturerNumberShort'] ?? '';
            $id = $row['ID'] ?? '';
            $sku = $long ?: ($short ?: "ARI-{$id}");

            // Check if this SKU matches any of our last imported products
            if (in_array($sku, $lastSkus)) {
                $lastFoundLine = $lineNumber;
                $lastFoundSku = $sku;
            }

            if ($productCount % 50000 === 0) {
                $this->line("Scanned {$productCount} products (line {$lineNumber})...");
            }
        }

        $this->newLine();

        if ($lastFoundLine > 0) {
            $this->info('✓ Found last imported product!');
            $this->table(['Metric', 'Value'], [
                ['Last imported SKU', $lastFoundSku],
                ['Found at line number', $lastFoundLine],
                ['Product count at that line', $productCount],
            ]);

            $this->newLine();

            $totalProductsInDb = DB::table('products')->count();

            // The skip value should be the product count (not line number)
            $recommendedSkip = $productCount;

            $this->info('Resume Information:');
            $this->table(['Metric', 'Value'], [
                ['Products in database', number_format($totalProductsInDb)],
                ['Products counted in Partmaster', number_format($productCount)],
                ['Difference', number_format($productCount - $totalProductsInDb)],
                ['Recommended --skip value', number_format($recommendedSkip)],
            ]);

            $this->newLine();
            $this->info('Resume Command:');
            $this->line("php artisan ari:fast-import --skip={$recommendedSkip} --batch=50000");

        } else {
            $this->warn('Could not find any of the last imported SKUs in Partmaster.txt');
            $this->warn('This might mean:');
            $this->line('1. The Partmaster.txt file has changed');
            $this->line('2. The products were imported from a different data source');
            $this->line('3. There is a SKU format mismatch');
        }

        return Command::SUCCESS;
    }
}
