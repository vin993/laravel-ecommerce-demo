<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class FixOrphanedImages extends Command
{
    protected $signature = 'ari:fix-orphaned-images {--dry-run : Show what would be fixed}';
    protected $description = 'Fix orphaned image files (files exist but no database records)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $this->info('Finding products without image records...');

        // OPTIMIZED: Get products without DB image records first
        $productsWithoutImages = DB::table('products as p')
            ->leftJoin('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->whereNull('pi.id')
            ->select('p.id', 'p.sku')
            ->get();

        $this->info("Found " . $productsWithoutImages->count() . " products without DB image records");
        $this->info('Checking which ones have orphaned files...');

        $orphanedProducts = [];
        $bar = $this->output->createProgressBar($productsWithoutImages->count());

        foreach ($productsWithoutImages as $product) {
            $dir = "/var/www/html/test14/storage/app/public/product/{$product->id}";

            // Check if directory exists and has image files
            if (File::exists($dir)) {
                $files = glob("$dir/*.{jpg,jpeg,png,gif}", GLOB_BRACE);
                if (count($files) > 0) {
                    $orphanedProducts[$product->id] = [
                        'sku' => $product->sku,
                        'files' => $files
                    ];
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->newLine();

        $this->info("Found " . count($orphanedProducts) . " products with orphaned images");
        $this->newLine();

        if (empty($orphanedProducts)) {
            $this->info('No orphaned images found!');
            return Command::SUCCESS;
        }

        $fixed = 0;
        $totalImages = 0;

        foreach ($orphanedProducts as $productId => $data) {
            $this->line("Processing Product {$productId} ({$data['sku']}): " . count($data['files']) . " orphaned images");

            $position = 1;
            foreach ($data['files'] as $filePath) {
                $filename = basename($filePath);
                $relativePath = "product/{$productId}/{$filename}";

                if ($dryRun) {
                    $this->line("  Would create DB record: {$relativePath}");
                } else {
                    // Create database record
                    DB::table('product_images')->insert([
                        'type' => 'image',
                        'path' => $relativePath,
                        'product_id' => $productId,
                        'position' => $position
                    ]);
                    $this->line("  ✓ Created DB record: {$relativePath}");
                }

                $totalImages++;
                $position++;
            }

            $fixed++;
        }

        $this->newLine();
        $this->info("Summary:");
        $this->table(['Status', 'Count'], [
            ['Products Fixed', $fixed],
            ['Total Images Fixed', $totalImages],
        ]);

        if (!$dryRun) {
            $this->info('✓ All orphaned images have been fixed!');
        }

        return Command::SUCCESS;
    }
}
