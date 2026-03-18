<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CheckImageSyncProgress extends Command
{
    protected $signature = 'ari:check-images';
    protected $description = 'Check image sync progress and statistics';

    protected $imageSourcePath = '/var/www/html/test14/storage/app/datastream/images';
    protected $imageBrands = ['HelmetHouse', 'Honda', 'Kawasaki', 'PartsUnlimited', 'Polaris', 'SeaDoo', 'Sullivans', 'Yamaha'];

    public function handle()
    {
        $this->info('Checking image sync progress...');
        $this->newLine();

        // Check ds_images table status
        $dsImagesCount = DB::table('ds_images')->count();
        $this->info("DataStream Images Table:");
        $this->table(['Metric', 'Count'], [
            ['Total ds_images records', number_format($dsImagesCount)],
        ]);
        $this->newLine();

        if ($dsImagesCount == 0) {
            $this->warn('ds_images table is empty. Run: php artisan ari:sync-images to populate it');
            return Command::SUCCESS;
        }

        // Check available image files on server (including subfolders)
        $totalImageFiles = 0;
        $imageFilesByBrand = [];

        foreach ($this->imageBrands as $brand) {
            $brandPath = "{$this->imageSourcePath}/{$brand}";
            if (File::exists($brandPath)) {
                $count = $this->countImagesRecursive($brandPath);
                $imageFilesByBrand[$brand] = $count;
                $totalImageFiles += $count;
            } else {
                $imageFilesByBrand[$brand] = 0;
            }
        }

        $this->info("Available Image Files on Server:");
        $brandTable = [];
        foreach ($imageFilesByBrand as $brand => $count) {
            $brandTable[] = [$brand, number_format($count)];
        }
        $brandTable[] = ['TOTAL', number_format($totalImageFiles)];
        $this->table(['Brand Folder', 'Image Files'], $brandTable);
        $this->newLine();

        // Check product image sync status
        $productsWithImages = DB::table('products as p')
            ->join('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->distinct('p.id')
            ->count();

        $productsWithoutImages = DB::table('products as p')
            ->leftJoin('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->whereNull('pi.id')
            ->count();

        $totalProducts = DB::table('products')->count();

        // Products that have DataStream images available
        $productsWithDsImages = DB::table('products as p')
            ->join('ds_images as img', 'p.id', '=', 'img.part_id')
            ->whereNotNull('img.hi_res_image_name')
            ->where('img.hi_res_image_name', '!=', 'No_Image.jpg')
            ->where('img.hi_res_image_name', '!=', 'Avail_soon.jpg')
            ->distinct('p.id')
            ->count();

        // Products with synced DataStream images
        $productsSyncedImages = DB::table('ds_images')
            ->whereNotNull('local_image_path')
            ->distinct('part_id')
            ->count();

        $this->info("Product Image Status:");
        $this->table(['Metric', 'Count', 'Percentage'], [
            ['Total Products', number_format($totalProducts), '100%'],
            ['Products with ANY Images', number_format($productsWithImages), number_format(($productsWithImages / $totalProducts) * 100, 2) . '%'],
            ['Products without Images', number_format($productsWithoutImages), number_format(($productsWithoutImages / $totalProducts) * 100, 2) . '%'],
            ['Products with DataStream Images Available', number_format($productsWithDsImages), number_format(($productsWithDsImages / $totalProducts) * 100, 2) . '%'],
            ['Products with Synced DataStream Images', number_format($productsSyncedImages), number_format(($productsSyncedImages / $totalProducts) * 100, 2) . '%'],
        ]);
        $this->newLine();

        // Show next sync command
        if ($productsWithoutImages > 0) {
            $this->info("Next steps to sync images:");
            $this->line("1. Test with dry run: php artisan ari:sync-images --batch=100 --dry-run");
            $this->line("2. Start sync: php artisan ari:sync-images --batch=1000");
            $this->newLine();
        } else {
            $this->info("All products have images!");
        }

        // Check for missing image files
        $this->checkMissingImageFiles();

        return Command::SUCCESS;
    }

    protected function checkMissingImageFiles()
    {
        $this->info("Checking for missing image files...");

        $missingCount = DB::table('ds_images as img')
            ->join('products as p', 'img.part_id', '=', 'p.id')
            ->whereNotNull('img.hi_res_image_name')
            ->where('img.hi_res_image_name', '!=', 'No_Image.jpg')
            ->where('img.hi_res_image_name', '!=', 'Avail_soon.jpg')
            ->whereNull('img.local_image_path')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('product_images as pi')
                      ->whereRaw('pi.product_id = p.id');
            })
            ->get()
            ->filter(function ($image) {
                return !$this->findImageFile($image->hi_res_image_name);
            })
            ->count();

        if ($missingCount > 0) {
            $this->warn("Found {$missingCount} products with missing image files on server");
        } else {
            $this->info("All referenced image files are available on server");
        }
    }

    protected function findImageFile($imageName)
    {
        foreach ($this->imageBrands as $brand) {
            $path = "{$this->imageSourcePath}/{$brand}/{$imageName}";
            if (File::exists($path)) {
                return $path;
            }
        }
        return null;
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
