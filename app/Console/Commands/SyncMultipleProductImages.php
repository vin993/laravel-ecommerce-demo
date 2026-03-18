<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Exception;

class SyncMultipleProductImages extends Command
{
    protected $signature = 'ari:sync-multiple-images {--batch=1000 : Number of products to process} {--skip=0 : Skip records} {--start-id= : Start from product ID} {--dry-run : Show what would be processed}';
    protected $description = 'SAFE: Sync ALL images per product from DataStream (multiple images per product)';

    protected $imageSourcePath = '/var/www/html/test14/storage/app/datastream/images';
    protected $imageBrands = ['HelmetHouse', 'Honda', 'Kawasaki', 'PartsUnlimited', 'Polaris', 'SeaDoo', 'Sullivans', 'Yamaha'];

    public function handle()
    {
        $this->info('Starting Multiple Images Sync...');
        $this->warn('SAFETY MODE: Only processing products with NO existing images');
        $this->newLine();

        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $startId = $this->option('start-id');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Load ALL images from Images.txt grouped by PartmasterID
        $imagesByPart = $this->loadAllImagesGrouped();

        if (empty($imagesByPart)) {
            $this->error('No image data found');
            return Command::FAILURE;
        }

        $this->info('Found images for ' . number_format(count($imagesByPart)) . ' parts');

        // Get products without images
        $productsQuery = DB::table('products as p')
            ->leftJoin('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->whereNull('pi.id')
            ->select('p.id', 'p.sku')
            ->orderBy('p.id');

        if ($startId) {
            $productsQuery->where('p.id', '>=', $startId);
            $this->info("Starting from product ID: {$startId}");
        } elseif ($skip > 0) {
            $productsQuery->skip($skip);
        }

        $products = $productsQuery->take($batch)->get();

        $displayInfo = $startId ? "start-id: {$startId}" : "skip: {$skip}";
        $this->info("Processing {$products->count()} products (batch: {$batch}, {$displayInfo})");
        $this->newLine();

        if ($products->isEmpty()) {
            $this->info('No products to process');
            return Command::SUCCESS;
        }

        $processed = 0;
        $skipped = 0;
        $totalImagesAdded = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($products->count());

        foreach ($products as $product) {
            try {
                // Find partmaster_id via SKU mapping
                $partmasterId = $this->findPartmasterIdBySku($product->sku);

                if (!$partmasterId) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                // Get ALL images for this part
                $images = $imagesByPart[$partmasterId] ?? null;

                if (!$images) {
                    $skipped++;
                    $progressBar->advance();
                    continue;
                }

                $imagesAdded = $this->processProductImages($product, $images, $dryRun);
                if ($imagesAdded > 0) {
                    $processed++;
                    $totalImagesAdded += $imagesAdded;
                } else {
                    $skipped++;
                }
            } catch (Exception $e) {
                $errors++;
                $this->error("Error processing product {$product->id}: " . $e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();
        $this->newLine();

        $this->info("Multiple Images sync completed!");
        $this->table(['Status', 'Count'], [
            ['Products Processed', $processed],
            ['Total Images Added', $totalImagesAdded],
            ['Products Skipped', $skipped], 
            ['Errors', $errors],
        ]);

        if ($totalImagesAdded > 0) {
            $avgImages = round($totalImagesAdded / $processed, 1);
            $this->info("Average images per product: {$avgImages}");
        }

        if (!$dryRun && $processed > 0 && !$products->isEmpty()) {
            $lastProductId = $products->last()->id;
            $this->newLine();
            $this->info('Next batch command:');
            $this->line("php artisan ari:sync-multiple-images --batch={$batch} --start-id=" . ($lastProductId + 1));
        }

        return Command::SUCCESS;
    }

    protected function loadAllImagesGrouped()
    {
        $imagesFile = '/var/www/html/test14/storage/app/datastream/extracted/JonesboroCycleFull/Images.txt';
        
        if (!File::exists($imagesFile)) {
            $this->error("Images.txt not found at {$imagesFile}");
            return [];
        }

        $this->info('Loading ALL Images.txt data...');
        
        $handle = fopen($imagesFile, 'r');
        $header = fgetcsv($handle);
        $imagesByPart = [];

        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);
            
            // Skip placeholder images
            if (in_array($row['HiResImageName'], ['No_Image.jpg', 'Avail_soon.jpg'])) {
                continue;
            }
            
            $partId = $row['PartmasterID'];
            if (!isset($imagesByPart[$partId])) {
                $imagesByPart[$partId] = [];
            }
            
            $imagesByPart[$partId][] = [
                'filename' => $row['HiResImageName'],
                'special' => $row['SpecialImage'] == '1'
            ];
        }

        fclose($handle);
        return $imagesByPart;
    }

    protected function processProductImages($product, $images, $dryRun = false)
    {
        // SAFETY CHECK: Skip if product already has images
        $existingImages = DB::table('product_images')
            ->where('product_id', $product->id)
            ->count();

        if ($existingImages > 0) {
            return 0; // Product already has images - don't overwrite
        }

        $imagesAdded = 0;
        $position = 1;

        foreach ($images as $imageInfo) {
            $imagePath = $this->findImageFile($imageInfo['filename']);

            if (!$imagePath) {
                continue; // Image file not found, try next one
            }

            if ($dryRun) {
                $this->line("Would add image {$position}: {$imageInfo['filename']} -> Product {$product->id} ({$product->sku})");
                $imagesAdded++;
                $position++;
                continue;
            }

            // Copy image to Bagisto storage
            $destinationPath = "product/{$product->id}/" . basename($imagePath);
            $publicPath = storage_path("app/public/{$destinationPath}");

            // SAFETY CHECK: Don't overwrite existing files
            if (File::exists($publicPath)) {
                continue; // File already exists - skip this image
            }

            // Create directory if it doesn't exist
            $dir = dirname($publicPath);
            if (!File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            // Copy the image
            File::copy($imagePath, $publicPath);

            // Insert into product_images table
            DB::table('product_images')->insert([
                'type' => 'image',
                'path' => $destinationPath,
                'product_id' => $product->id,
                'position' => $position
            ]);

            $imagesAdded++;
            $position++;
        }

        return $imagesAdded;
    }

    protected function findImageFile($imageName)
    {
        foreach ($this->imageBrands as $brand) {
            $brandPath = "{$this->imageSourcePath}/{$brand}";

            // First try flat structure (existing images in root)
            $path = "{$brandPath}/{$imageName}";
            if (File::exists($path)) {
                return $path;
            }

            // Then check first-level subdirectories (most common case)
            if (File::exists($brandPath)) {
                $subDirs = File::directories($brandPath);
                foreach ($subDirs as $subDir) {
                    $path = "{$subDir}/{$imageName}";
                    if (File::exists($path)) {
                        return $path;
                    }

                    // Check nested subdirectories (for extracted archives with nested structure)
                    $nestedDirs = File::directories($subDir);
                    foreach ($nestedDirs as $nestedDir) {
                        $path = "{$nestedDir}/{$imageName}";
                        if (File::exists($path)) {
                            return $path;
                        }
                    }
                }
            }
        }
        return null;
    }

    protected function findPartmasterIdBySku($sku)
    {
        // Try exact match first
        $mapping = DB::table('ds_sku_partmaster_index')
            ->where('sku', $sku)
            ->first();
            
        if ($mapping) {
            return $mapping->partmaster_id;
        }
        
        // Try without -parent suffix for configurable products
        if (str_ends_with($sku, '-parent')) {
            $baseSku = str_replace('-parent', '', $sku);
            $mapping = DB::table('ds_sku_partmaster_index')
                ->where('sku', $baseSku)
                ->first();
                
            if ($mapping) {
                return $mapping->partmaster_id;
            }
        }
        
        return null;
    }
}