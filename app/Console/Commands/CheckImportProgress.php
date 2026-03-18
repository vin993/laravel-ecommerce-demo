<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CheckImportProgress extends Command
{
    protected $signature = 'ari:check-progress {--verify-skip : Verify skip value by checking actual Partmaster file}';
    protected $description = 'Check product import progress and calculate next skip value';

    public function handle()
    {
        $this->info('Checking import progress...');
        $this->newLine();

        $totalProducts = DB::table('products')->count();
        $simpleProducts = DB::table('products')->where('type', 'simple')->whereNull('parent_id')->count();
        $configurableProducts = DB::table('products')->where('type', 'configurable')->count();
        $variantProducts = DB::table('products')->whereNotNull('parent_id')->count();

        $productsWithoutAttrs = DB::selectOne("
            SELECT COUNT(DISTINCT p.id) as count
            FROM products p
            LEFT JOIN product_attribute_values pav ON p.id = pav.product_id
            WHERE pav.id IS NULL
        ")->count;

        $this->table(['Metric', 'Count'], [
            ['Total Products', number_format($totalProducts)],
            ['Simple Products', number_format($simpleProducts)],
            ['Configurable Products', number_format($configurableProducts)],
            ['Variant Products', number_format($variantProducts)],
            ['Products WITHOUT Attributes', number_format($productsWithoutAttrs)],
        ]);

        $this->newLine();

        $basePath = '/var/www/html/test14/storage/app/datastream/extracted/JonesboroCycleFull';
        if (!File::exists($basePath . '/Partmaster.txt')) {
            $this->warn('Partmaster.txt not found at ' . $basePath);
            return Command::FAILURE;
        }

        $kitsCount = 0;
        if (File::exists($basePath . '/Kits.txt')) {
            $lines = File::lines($basePath . '/Kits.txt');
            $header = null;
            foreach ($lines as $line) {
                if (!$header) {
                    $header = true;
                    continue;
                }
                $kitsCount++;
            }
        }

        $partmasterLines = 0;
        $lines = File::lines($basePath . '/Partmaster.txt');
        foreach ($lines as $line) {
            $partmasterLines++;
        }
        $partmasterLines--; // Remove header

        $totalToImport = $partmasterLines - $kitsCount;

        $this->info('DataStream Catalog Info:');
        $this->table(['Metric', 'Count'], [
            ['Total in Partmaster.txt', number_format($partmasterLines)],
            ['Parent Kit Products (filtered)', number_format($kitsCount)],
            ['Individual Products to Import', number_format($totalToImport)],
        ]);

        $this->newLine();

        $remaining = $totalToImport - $totalProducts;
        $percentComplete = ($totalProducts / $totalToImport) * 100;

        $this->info('Progress:');
        $this->table(['Metric', 'Value'], [
            ['Products Imported', number_format($totalProducts)],
            ['Remaining to Import', number_format($remaining)],
            ['Progress', number_format($percentComplete, 2) . '%'],
        ]);

        $this->newLine();

        // Verify skip value if requested
        if ($this->option('verify-skip')) {
            $this->info('🔍 Verifying correct skip value...');
            $this->line('This will scan Partmaster.txt to find first unimported product (may take 1-2 minutes)');
            $this->newLine();

            $correctSkip = $this->findFirstUnimported($basePath, $totalProducts);

            if ($correctSkip !== null) {
                $this->newLine();
                $this->info('✓ Verified Resume Command:');
                $this->line("php artisan ari:fast-import --skip={$correctSkip} --batch=50000");
            }
        } else {
            if ($remaining > 0) {
                $this->info('Estimated Resume Command:');
                $this->line('php artisan ari:fast-import --skip=' . $totalProducts . ' --batch=100000');

                $this->newLine();
                $this->warn('⚠ This is an ESTIMATE. To verify the exact skip value, run:');
                $this->line('php artisan ari:check-progress --verify-skip');

                $this->newLine();
                $this->info('Batch Options:');
                $this->line('  Small batch  : --batch=10000  (for testing)');
                $this->line('  Medium batch : --batch=50000  (recommended)');
                $this->line('  Large batch  : --batch=100000 (faster, more memory)');
            } else {
                $this->info('All products imported! ✓');
                $this->newLine();
                $this->info('Next Steps:');
                $this->line('1. Build variant groups: php artisan datastream:build-variant-groups');
                $this->line('2. Convert to variants: php artisan ari:update-existing-products --batch=5000');
                $this->line('3. Rebuild indexes: php artisan ari:rebuild-indexes');
            }
        }

        $this->newLine();

        $productsWithCategories = DB::table('product_categories')
            ->distinct('product_id')
            ->count('product_id');

        $this->info('Category Mapping:');
        $this->table(['Metric', 'Count'], [
            ['Products with Categories', number_format($productsWithCategories)],
            ['Products without Categories', number_format($totalProducts - $productsWithCategories)],
        ]);

        if ($productsWithCategories < $totalProducts) {
            $this->newLine();
            $this->info('Resume Category Mapping:');
            $this->line('php artisan ari:map-categories --batch=5000 --skip=' . $productsWithCategories);
        }

        $this->newLine();

        if ($productsWithoutAttrs > 0) {
            $this->info('Attribute Sync Status:');
            $this->table(['Metric', 'Value'], [
                ['Products with Attributes', number_format($totalProducts - $productsWithoutAttrs)],
                ['Products without Attributes', number_format($productsWithoutAttrs)],
                ['Sync Progress', number_format((($totalProducts - $productsWithoutAttrs) / $totalProducts) * 100, 2) . '%'],
            ]);

            $this->newLine();
            $this->info('Resume Attribute Sync:');

            $synced = $totalProducts - $productsWithoutAttrs;
            $this->line('php artisan ari:sync-attributes --batch=10000 --skip=' . $synced);

            $this->newLine();
            $this->info('Or run multiple batches:');
            $skip1 = $synced;
            $skip2 = $synced + 10000;
            $skip3 = $synced + 20000;
            $this->line("php artisan ari:sync-attributes --batch=10000 --skip={$skip1} && \\");
            $this->line("php artisan ari:sync-attributes --batch=10000 --skip={$skip2} && \\");
            $this->line("php artisan ari:sync-attributes --batch=10000 --skip={$skip3}");
        } else {
            $this->newLine();
            $this->info('All products have attributes!');
        }

        return Command::SUCCESS;
    }

    private function findFirstUnimported(string $basePath, int $startFrom): ?int
    {
        // Load kits to filter
        $kits = DB::table('ds_kit_index')->pluck('primary_partmaster_id')->toArray();
        $kitsFlipped = array_flip($kits);

        $file = $basePath . '/Partmaster.txt';
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

            // Skip until we're near the current imported count
            if ($productCount < ($startFrom - 100)) {
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
                $this->info('✓ Found first unimported product!');
                $this->table(['Field', 'Value'], [
                    ['SKU', $sku],
                    ['Product Name', $row['ItemName'] ?? 'N/A'],
                    ['Line in Partmaster', $lineNumber],
                    ['Product Count (after filtering kits)', $productCount],
                ]);

                return $productCount;
            }

            if ($checkedCount % 100 === 0) {
                $this->line("  Verified {$checkedCount} products, all exist... (at product #{$productCount})");
            }

            // Safety: stop after checking 5,000 products
            if ($checkedCount > 5000) {
                $this->warn('Checked 5,000 products and all exist.');
                $this->warn('Something may be wrong. All products appear to be imported already.');
                return null;
            }
        }

        $this->warn('Reached end of Partmaster.txt. All products appear to be imported!');
        return null;
    }
}
