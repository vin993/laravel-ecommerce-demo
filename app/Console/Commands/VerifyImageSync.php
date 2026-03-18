<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VerifyImageSync extends Command
{
    protected $signature = 'ari:verify-images';
    protected $description = 'Verify image sync status by comparing filesystem with database';

    public function handle()
    {
        $this->info('Starting image sync verification...');
        $this->newLine();

        $baseImagePath = storage_path('app/datastream/images');
        
        $stats = [
            'filesystem' => [],
            'total_filesystem_images' => 0,
            'database' => [],
            'total_db_products_with_images' => 0,
        ];

        $this->info('=== FILESYSTEM IMAGE COUNT ===');
        
        $manufacturers = ['HelmetHouse', 'Honda', 'Kawasaki', 'PartsUnlimited', 'Polaris', 'SeaDoo', 'Sullivans', 'Yamaha'];
        
        foreach ($manufacturers as $mfr) {
            $mfrPath = $baseImagePath . '/' . $mfr;
            
            if (!is_dir($mfrPath)) {
                $this->warn("Directory not found: {$mfrPath}");
                continue;
            }

            $subfolders = scandir($mfrPath);
            $mfrStats = [
                'subfolders' => [],
                'total_images' => 0,
            ];

            foreach ($subfolders as $subfolder) {
                if ($subfolder === '.' || $subfolder === '..') {
                    continue;
                }

                $subfolderPath = $mfrPath . '/' . $subfolder;
                
                if (is_dir($subfolderPath)) {
                    $imageCount = $this->countImagesInDirectory($subfolderPath);
                    $mfrStats['subfolders'][$subfolder] = $imageCount;
                    $mfrStats['total_images'] += $imageCount;
                }
            }

            $stats['filesystem'][$mfr] = $mfrStats;
            $stats['total_filesystem_images'] += $mfrStats['total_images'];

            $this->line("{$mfr}: {$mfrStats['total_images']} images in " . count($mfrStats['subfolders']) . " subfolders");
        }

        $this->newLine();
        $this->info("TOTAL FILESYSTEM IMAGES: " . number_format($stats['total_filesystem_images']));
        $this->newLine();

        $this->info('=== DATABASE IMAGE STATUS ===');
        
        $totalProducts = DB::table('products')->count();
        $this->line("Total products: " . number_format($totalProducts));

        $productsWithImages = DB::table('products as p')
            ->join('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->distinct('p.id')
            ->count('p.id');
        
        $stats['total_db_products_with_images'] = $productsWithImages;
        
        $this->line("Products with images: " . number_format($productsWithImages));
        
        $productsWithoutImages = $totalProducts - $productsWithImages;
        $this->line("Products without images: " . number_format($productsWithoutImages));
        
        $imagePercentage = ($productsWithImages / $totalProducts) * 100;
        $this->line("Image coverage: " . number_format($imagePercentage, 2) . "%");

        $this->newLine();
        $this->info('=== TOTAL IMAGE RECORDS IN product_images ===');
        
        $totalImageRecords = DB::table('product_images')->count();
        $this->line("Total image records: " . number_format($totalImageRecords));

        $avgImagesPerProduct = $productsWithImages > 0 ? $totalImageRecords / $productsWithImages : 0;
        $this->line("Average images per product: " . number_format($avgImagesPerProduct, 2));

        $this->newLine();
        $this->info('=== ds_images TABLE STATUS ===');
        
        $dsImagesTotal = DB::table('ds_images')->count();
        $this->line("Total records in ds_images: " . number_format($dsImagesTotal));

        $dsImagesWithPaths = DB::table('ds_images')
            ->where('image1', '!=', '')
            ->whereNotNull('image1')
            ->count();
        $this->line("ds_images with image1 path: " . number_format($dsImagesWithPaths));

        $this->newLine();
        $this->info('=== UNSYNCED IMAGES ANALYSIS ===');
        
        $unsyncedCount = DB::table('ds_images as di')
            ->leftJoin('product_images as pi', function($join) {
                $join->on('di.partmaster_id', '=', DB::raw('CAST(SUBSTRING_INDEX(pi.path, "/", -1) AS UNSIGNED)'));
            })
            ->where('di.image1', '!=', '')
            ->whereNotNull('di.image1')
            ->whereNull('pi.id')
            ->count();

        $this->line("Images in ds_images not synced to product_images: " . number_format($unsyncedCount));

        $this->newLine();
        $this->info('=== DETAILED SUBFOLDER BREAKDOWN ===');
        
        foreach ($stats['filesystem'] as $mfr => $data) {
            $this->line("\n{$mfr}:");
            foreach ($data['subfolders'] as $subfolder => $count) {
                $this->line("  {$subfolder}: " . number_format($count) . " images");
            }
        }

        $this->newLine();
        $this->info('=== SUMMARY ===');
        $this->line("Filesystem images: " . number_format($stats['total_filesystem_images']));
        $this->line("Database products with images: " . number_format($stats['total_db_products_with_images']));
        $this->line("Database image records: " . number_format($totalImageRecords));
        $this->line("Image coverage: " . number_format($imagePercentage, 2) . "%");
        
        if ($unsyncedCount > 0) {
            $this->newLine();
            $this->warn("There are " . number_format($unsyncedCount) . " unsynced images that could be added to products.");
            $this->warn("Run: sudo -u www-data php artisan ari:sync-multiple-images --batch=1000");
        } else {
            $this->newLine();
            $this->info("All available images appear to be synced!");
        }

        return 0;
    }

    private function countImagesInDirectory($directory)
    {
        $count = 0;
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
