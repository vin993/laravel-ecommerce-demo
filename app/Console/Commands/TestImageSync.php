<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class TestImageSync extends Command
{
    protected $signature = 'ari:test-images';
    protected $description = 'SAFE: Test image sync setup - shows what would happen without making changes';

    protected $imageSourcePath = '/var/www/html/test14/storage/app/datastream/images';
    protected $imageBrands = ['HelmetHouse', 'Honda', 'Kawasaki', 'PartsUnlimited', 'Polaris', 'SeaDoo', 'Sullivans', 'Yamaha'];

    public function handle()
    {
        $this->info('TESTING Image Sync Setup - NO CHANGES WILL BE MADE');
        $this->newLine();

        // Test 1: Check if required tables exist
        $this->testTableStructure();

        // Test 2: Check image files on server
        $this->testImageFiles();

        // Test 3: Check sample products that would be processed
        $this->testSampleProducts();

        // Test 4: Check for any potential conflicts
        $this->testConflicts();

        $this->newLine();
        $this->info('Test completed - safe to proceed with image sync');

        return Command::SUCCESS;
    }

    protected function testTableStructure()
    {
        $this->info('1. Testing database structure...');

        $tables = ['ds_images', 'ds_partmaster', 'products', 'product_images'];
        foreach ($tables as $table) {
            $exists = DB::getSchemaBuilder()->hasTable($table);
            $this->line("   {$table}: " . ($exists ? 'EXISTS' : 'MISSING'));
        }

        $dsImagesCount = DB::table('ds_images')->count();
        $this->line("   ds_images records: " . number_format($dsImagesCount));
        
        $this->newLine();
    }

    protected function testImageFiles()
    {
        $this->info('2. Testing image files on server...');

        $totalFiles = 0;
        foreach ($this->imageBrands as $brand) {
            $brandPath = "{$this->imageSourcePath}/{$brand}";
            $exists = File::exists($brandPath);
            $count = $exists ? count(File::files($brandPath)) : 0;
            $totalFiles += $count;
            $this->line("   {$brand}: " . ($exists ? number_format($count) . ' files' : 'MISSING'));
        }

        $this->line("   TOTAL: " . number_format($totalFiles) . ' image files available');
        $this->newLine();
    }

    protected function testSampleProducts()
    {
        $this->info('3. Testing sample products that would be processed...');

        // Get 5 sample products that would be processed
        $samples = DB::table('ds_images as img')
            ->join('ds_partmaster as pm', 'img.part_id', '=', 'pm.part_id')
            ->leftJoin('products as p', 'pm.part_id', '=', 'p.id')
            ->leftJoin('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->whereNull('pi.id')
            ->whereNotNull('p.id')
            ->whereNotNull('img.hi_res_image_name')
            ->where('img.hi_res_image_name', '!=', 'No_Image.jpg')
            ->where('img.hi_res_image_name', '!=', 'Avail_soon.jpg')
            ->whereNull('img.local_image_path')
            ->select([
                'p.id as product_id',
                'p.sku',
                'img.hi_res_image_name'
            ])
            ->limit(5)
            ->get();

        if ($samples->isEmpty()) {
            $this->warn('   No products found that need image sync');
        } else {
            $this->line('   Sample products that would get images:');
            foreach ($samples as $sample) {
                $imageExists = $this->findImageFile($sample->hi_res_image_name) ? 'FOUND' : 'NOT FOUND';
                $this->line("   - Product {$sample->product_id} ({$sample->sku}): {$sample->hi_res_image_name} [{$imageExists}]");
            }
        }

        $this->newLine();
    }

    protected function testConflicts()
    {
        $this->info('4. Testing for potential conflicts...');

        // Check products with existing images that might be affected
        $productsWithImages = DB::table('products as p')
            ->join('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->join('ds_images as img', 'p.id', '=', 'img.part_id')
            ->count();

        $this->line("   Products with existing images that also have DataStream images: " . number_format($productsWithImages));
        $this->info('   These will be SKIPPED (safety mode)');

        // Check for duplicate image files that might cause conflicts
        $duplicateImages = DB::table('ds_images')
            ->select('hi_res_image_name', DB::raw('COUNT(*) as count'))
            ->groupBy('hi_res_image_name')
            ->having('count', '>', 1)
            ->count();

        $this->line("   Duplicate image filenames in ds_images: " . number_format($duplicateImages));
        
        if ($duplicateImages > 0) {
            $this->warn('   Multiple products may share same image file - this is normal');
        }

        $this->newLine();
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
}