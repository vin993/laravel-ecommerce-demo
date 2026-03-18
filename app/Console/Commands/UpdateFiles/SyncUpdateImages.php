<?php

namespace App\Console\Commands\UpdateFiles;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductImage;

class SyncUpdateImages extends Command
{
    protected $signature = 'ari:sync-update-images {--folder=} {--all}';
    protected $description = 'Sync images from update folders for new products';

    public function handle()
    {
        $folder = $this->option('folder');
        $all = $this->option('all');

        $basePath = storage_path('app/datastream/extracted');
        
        if ($all) {
            $folders = glob($basePath . '/JonesboroCycleUpdate*');
            sort($folders);
            
            $this->info('Processing all update folders...');
            
            foreach ($folders as $folderPath) {
                $folderName = basename($folderPath);
                $this->info("\nProcessing: $folderName");
                $this->processFolder($folderPath);
            }
        } elseif ($folder) {
            $folderPath = $basePath . '/' . $folder;
            
            if (!is_dir($folderPath)) {
                $this->error("Folder not found: $folderPath");
                return 1;
            }
            
            $this->processFolder($folderPath);
        } else {
            $this->error('Please specify --folder=FolderName or --all');
            return 1;
        }

        $this->info("\nAll images synced successfully!");
        return 0;
    }

    private function processFolder($folderPath)
    {
        $imagesFile = $folderPath . '/Images.txt';
        $partmasterFile = $folderPath . '/Partmaster.txt';
        
        if (!file_exists($imagesFile)) {
            $this->warn("No Images.txt found in " . basename($folderPath));
            return;
        }

        if (!file_exists($partmasterFile)) {
            $this->warn("No Partmaster.txt found in " . basename($folderPath));
            return;
        }

        $this->info("Loading partmaster from: " . basename($folderPath));
        
        $partmasterMap = [];
        $handle = fopen($partmasterFile, 'r');
        $header = str_getcsv(fgets($handle), ',');
        $header = array_map(function($col) { return trim($col, '"'); }, $header);
        
        $idIndex = array_search('Id', $header);
        $mfgShortIndex = array_search('ManufacturerNumberShort', $header);
        
        while (($line = fgets($handle)) !== false) {
            $row = str_getcsv($line, ',');
            if (count($row) > max($idIndex, $mfgShortIndex)) {
                $id = trim($row[$idIndex], '"');
                $sku = trim($row[$mfgShortIndex], '"');
                if ($id && $sku) {
                    $partmasterMap[$id] = $sku;
                }
            }
        }
        fclose($handle);
        
        $this->info("Loaded " . count($partmasterMap) . " SKU mappings");

        $this->info("Loading images from: " . basename($folderPath));
        
        $imageData = [];
        $handle = fopen($imagesFile, 'r');
        $header = str_getcsv(fgets($handle), ',');
        $header = array_map(function($col) { return trim($col, '"'); }, $header);

        $partmasterIdIndex = array_search('PartmasterID', $header);
        $imageFileIndex = array_search('HiResImageName', $header);

        if ($partmasterIdIndex === false || $imageFileIndex === false) {
            $this->error("Required columns not found");
            fclose($handle);
            return;
        }

        while (($line = fgets($handle)) !== false) {
            $row = str_getcsv($line, ',');
            
            if (count($row) <= max($partmasterIdIndex, $imageFileIndex)) {
                continue;
            }

            $partmasterId = trim($row[$partmasterIdIndex], '"');
            $imageFile = trim($row[$imageFileIndex], '"');

            if (empty($partmasterId) || empty($imageFile)) {
                continue;
            }
            
            if (!isset($partmasterMap[$partmasterId])) {
                continue;
            }
            
            $sku = $partmasterMap[$partmasterId];

            if (!isset($imageData[$sku])) {
                $imageData[$sku] = [];
            }
            
            $imageData[$sku][] = $imageFile;
        }
        
        fclose($handle);
        
        $this->info("Found images for " . count($imageData) . " parts");

        $productsWithoutImages = Product::whereNotExists(function($query) {
            $query->select(DB::raw(1))
                  ->from('product_images')
                  ->whereRaw('product_images.product_id = products.id');
        })->get();

        $this->info("Processing " . $productsWithoutImages->count() . " products without images");

        $processed = 0;
        $added = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($productsWithoutImages->count());
        $bar->start();

        foreach ($productsWithoutImages as $product) {
            $processed++;
            
            if (!isset($imageData[$product->sku])) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $images = $imageData[$product->sku];
            $imageDir = storage_path('app/datastream/extracted/JonesboroCycleFull/Images');

            foreach ($images as $imageName) {
                $sourcePath = $imageDir . '/' . $imageName;
                
                if (!file_exists($sourcePath)) {
                    continue;
                }

                $targetDir = 'product/' . $product->id;
                $targetPath = $targetDir . '/' . $imageName;

                if (!Storage::disk('public')->exists($targetDir)) {
                    Storage::disk('public')->makeDirectory($targetDir);
                }

                if (Storage::disk('public')->exists($targetPath)) {
                    continue;
                }

                Storage::disk('public')->put($targetPath, file_get_contents($sourcePath));

                ProductImage::create([
                    'product_id' => $product->id,
                    'path' => $targetPath,
                    'type' => 'images'
                ]);

                $added++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        $this->table(
            ['Status', 'Count'],
            [
                ['Processed', $processed],
                ['Images Added', $added],
                ['Skipped', $skipped],
            ]
        );
    }
}
