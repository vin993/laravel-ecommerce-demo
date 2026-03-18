<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class VerifyImageSyncStatus extends Command
{
    protected $signature = 'ari:verify-image-status';
    protected $description = 'Comprehensive image sync status verification';

    protected $imageSourcePath = '/var/www/html/test14/storage/app/datastream/images';
    protected $imageBrands = ['HelmetHouse', 'Honda', 'Kawasaki', 'PartsUnlimited', 'Polaris', 'SeaDoo', 'Sullivans', 'Yamaha'];

    public function handle()
    {
        $this->info('=== COMPREHENSIVE IMAGE SYNC VERIFICATION ===');
        $this->newLine();

        $totalProducts = DB::table('products')->count();
        $productsWithImages = DB::table('product_images')->distinct('product_id')->count();
        $productsWithoutImages = $totalProducts - $productsWithImages;
        $totalImageRecords = DB::table('product_images')->count();

        $this->info('PRODUCT STATUS');
        $this->table(['Metric', 'Count', 'Percentage'], [
            ['Total Products', number_format($totalProducts), '100%'],
            ['Products WITH Images', number_format($productsWithImages), number_format(($productsWithImages / $totalProducts) * 100, 2) . '%'],
            ['Products WITHOUT Images', number_format($productsWithoutImages), number_format(($productsWithoutImages / $totalProducts) * 100, 2) . '%'],
            ['Total Image Records', number_format($totalImageRecords), ''],
        ]);

        if ($productsWithImages > 0) {
            $avgImages = round($totalImageRecords / $productsWithImages, 2);
            $this->line("Average images per product: {$avgImages}");
        }

        $this->newLine();
        $this->info('FILESYSTEM STATUS');

        $totalFilesystemImages = 0;
        $brandStats = [];

        foreach ($this->imageBrands as $brand) {
            $brandPath = "{$this->imageSourcePath}/{$brand}";
            if (File::exists($brandPath)) {
                $count = $this->countImagesRecursive($brandPath);
                $brandStats[] = [$brand, number_format($count)];
                $totalFilesystemImages += $count;
            } else {
                $brandStats[] = [$brand, '0 (not found)'];
            }
        }
        $brandStats[] = ['TOTAL', number_format($totalFilesystemImages)];

        $this->table(['Brand Folder', 'Image Files'], $brandStats);

        $this->newLine();
        $this->info('STAGING TABLE STATUS (ari_staging_images)');

        $totalStaging = DB::table('ari_staging_images')->count();
        $processed = DB::table('ari_staging_images')->where('processed', 1)->count();
        $unprocessed = DB::table('ari_staging_images')->where('processed', 0)->count();

        $validImages = DB::table('ari_staging_images')
            ->whereRaw("JSON_EXTRACT(raw_data, '$.hiresimagename') != 'No_Image.jpg'")
            ->whereRaw("JSON_EXTRACT(raw_data, '$.hiresimagename') != 'Avail_soon.jpg'")
            ->whereRaw("JSON_EXTRACT(raw_data, '$.hiresimagename') IS NOT NULL")
            ->count();

        $this->table(['Metric', 'Count'], [
            ['Total Staging Records', number_format($totalStaging)],
            ['Processed', number_format($processed)],
            ['Unprocessed', number_format($unprocessed)],
            ['Valid Image Names (in JSON)', number_format($validImages)],
        ]);

        $this->newLine();
        $this->info('IMAGE SOURCE AVAILABILITY');
        
        $this->line('Staging records with valid images: ' . number_format($validImages));
        $this->line('Products currently without images: ' . number_format($productsWithoutImages));
        $this->line('Filesystem image files available: ' . number_format($totalFilesystemImages));

        $this->newLine();
        $this->info('IMAGES.TXT SOURCE FILE');

        $imagesFile = '/var/www/html/test14/storage/app/datastream/extracted/JonesboroCycleFull/Images.txt';
        if (File::exists($imagesFile)) {
            $lines = exec("wc -l {$imagesFile}");
            $this->line("Location: {$imagesFile}");
            $this->line("Lines: {$lines}");
        } else {
            $this->warn("Images.txt not found at {$imagesFile}");
        }

        $this->newLine();
        $this->info('=== SYNC RECOMMENDATIONS ===');

        if ($productsWithoutImages > 0) {
            $this->warn("You have " . number_format($productsWithoutImages) . " products without images");
            $this->newLine();
            $this->info('To sync images, run:');
            $this->line('sudo -u www-data php artisan ari:sync-multiple-images --batch=1000');
            $this->newLine();
            $this->line('To test first (dry run):');
            $this->line('sudo -u www-data php artisan ari:sync-multiple-images --batch=100 --dry-run');
        } else {
            $this->info('All products have images!');
        }

        $this->newLine();
        $this->info('NOTES:');
        $this->line('- The sync command only processes products WITHOUT existing images (safe)');
        $this->line('- Images are matched via SKU -> partmaster_id -> staging images');
        $this->line('- Not all products have source images available in DataStream');
        $this->line('- Some products may not match if SKU mapping is missing');

        return Command::SUCCESS;
    }

    protected function countImagesRecursive($directory)
    {
        $count = 0;
        $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!is_dir($directory)) {
            return 0;
        }

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
