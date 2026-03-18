<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FindFirstUnimported extends Command
{
    protected $signature = 'ari:find-first-unimported {--start=160000}';
    protected $description = 'Find the first unimported product in Partmaster.txt';

    private $basePath;

    public function handle()
    {
        $startLine = (int) $this->option('start');

        $this->info('Finding first unimported product...');
        $this->info("Starting search from line {$startLine}");
        $this->newLine();

        $this->basePath = '/var/www/html/test14/storage/app/datastream/extracted/JonesboroCycleFull';

        if (!File::exists($this->basePath . '/Partmaster.txt')) {
            $this->error('Partmaster.txt not found');
            return Command::FAILURE;
        }

        // Load kits to filter
        $kits = DB::table('ds_kit_index')->pluck('primary_partmaster_id')->toArray();
        $kitsFlipped = array_flip($kits);

        $file = $this->basePath . '/Partmaster.txt';
        $lines = File::lines($file);
        $header = null;
        $lineNumber = 0;
        $productCount = 0;
        $checkedCount = 0;

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

            // Skip until we reach start point
            if ($productCount < $startLine) {
                continue;
            }

            // Build SKU
            $long = $row['ManufacturerNumberLong'] ?? '';
            $short = $row['ManufacturerNumberShort'] ?? '';
            $id = $row['ID'] ?? '';
            $sku = $long ?: ($short ?: "ARI-{$id}");

            // Check if exists
            $exists = DB::table('products')->where('sku', $sku)->exists();

            $checkedCount++;

            if (!$exists) {
                $this->newLine();
                $this->info('✓ Found first unimported product!');
                $this->table(['Field', 'Value'], [
                    ['SKU', $sku],
                    ['Line Number', $lineNumber],
                    ['Product Count', $productCount],
                    ['Name', $row['ItemName'] ?? 'N/A'],
                ]);

                $this->newLine();
                $this->info('Resume Command:');
                $this->line("php artisan ari:fast-import --skip={$productCount} --batch=50000");

                return Command::SUCCESS;
            }

            if ($checkedCount % 100 === 0) {
                $this->line("Checked {$checkedCount} products, all exist so far... (product #{$productCount})");
            }

            // Safety: stop after checking 10,000 products
            if ($checkedCount > 10000) {
                $this->warn('Checked 10,000 products and all exist.');
                $this->warn('All products from {$startLine} to ' . ($productCount) . ' are already imported.');
                $this->newLine();
                $this->info('Try searching from a higher start point:');
                $this->line('php artisan ari:find-first-unimported --start=' . ($productCount + 1000));
                return Command::SUCCESS;
            }
        }

        $this->warn('Reached end of file. All products are imported!');
        return Command::SUCCESS;
    }
}
