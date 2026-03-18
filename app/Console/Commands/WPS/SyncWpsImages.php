<?php

namespace App\Console\Commands\WPS;

use App\Services\WPS\WpsImageService;
use App\Models\WPS\WpsProduct;
use Webkul\Product\Models\ProductImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SyncWpsImages extends Command
{
    protected $signature = 'wps:sync-images 
                        {--limit=20 : Number of products to process at once}
                        {--product-id= : Sync images for specific product ID}
                        {--dry-run : Show what images would be downloaded}
                        {--stats : Show current image statistics}
                        {--cleanup : Remove orphaned image files}
                        {--fix-broken : Fix existing broken image URLs}';

    protected $description = 'Sync product image URLs from WPS API to Bagisto';

    protected $imageService;

    public function __construct(WpsImageService $imageService)
    {
        parent::__construct();
        $this->imageService = $imageService;
    }

    public function handle()
    {
        try {
            if ($this->option('stats')) {
                $this->showStats();
                return 0;
            }

            if ($this->option('cleanup')) {
                $this->cleanupImages();
                return 0;
            }

            if ($this->option('dry-run')) {
                $this->dryRun();
                return 0;
            }

            if ($this->option('product-id')) {
                $this->syncSingleProduct();
                return 0;
            }

            if ($this->option('fix-broken')) {
                $this->fixBrokenImages();
                return 0;
            }

            $this->syncImages();

        } catch (\Exception $e) {
            $this->error('Command failed: ' . $e->getMessage());
            Log::channel('wps')->error('Sync images command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }



    protected function syncImages()
    {
        $limit = $this->option('limit');
        $this->info("Syncing image URLs for WPS products (limit: {$limit})...");
        $this->info('Note: This saves image URLs directly, no files downloaded');

        $bar = $this->output->createProgressBar($limit);
        $bar->setFormat('verbose');
        $bar->start();

        $result = $this->imageService->syncImagesForProducts($limit);

        $bar->finish();
        $this->newLine();

        $this->info("Products processed: {$result['processed']}");
        $this->info("Total image URLs saved: {$result['total_images']}");
        $this->error("Errors: {$result['errors']}");

        if ($result['errors'] > 0) {
            $this->warn('Check WPS logs for detailed error information');
        }
    }
    protected function syncSingleProduct()
    {
        $productId = $this->option('product-id');
        $this->info("Syncing images for single product: {$productId}");

        $wpsProduct = WpsProduct::with('items')
            ->where('wps_product_id', $productId)
            ->whereNotNull('bagisto_product_id')
            ->first();

        if (!$wpsProduct) {
            $this->error('Product not found or not synced to Bagisto yet');
            return;
        }

        $imageCount = $this->imageService->syncImagesForProduct($wpsProduct);
        $this->info("Downloaded {$imageCount} images for product");
    }

    protected function dryRun()
    {
        $this->info('DRY RUN - Showing products and their potential images');

        $limit = min($this->option('limit'), 5); // Limit dry run to 5 products

        $wpsProducts = WpsProduct::with('items')
            ->whereNotNull('bagisto_product_id')
            ->limit($limit)
            ->get();

        $this->table(
            ['WPS Product ID', 'Bagisto Product ID', 'Product Name', 'Current Images', 'Eligible Items'],
            $wpsProducts->map(function ($product) {
                $currentImages = ProductImage::where('product_id', $product->bagisto_product_id)->count();
                $eligibleItems = $product->items()->dropShipEligible()->count();

                return [
                    $product->wps_product_id,
                    $product->bagisto_product_id,
                    substr($product->name, 0, 30) . '...',
                    $currentImages,
                    $eligibleItems
                ];
            })
        );

        $this->newLine();
        $this->warn('Note: This will fetch image data from WPS API for each item');
        $this->info("Products to process: {$wpsProducts->count()}");
    }

    protected function showStats()
    {
        $this->info('WPS Image Statistics');
        $this->info('===================');

        // Product image stats
        $totalProducts = WpsProduct::whereNotNull('bagisto_product_id')->count();

        // Get Bagisto product IDs
        $bagistoProductIds = WpsProduct::whereNotNull('bagisto_product_id')->pluck('bagisto_product_id');

        // Get image counts
        $totalImages = ProductImage::whereIn('product_id', $bagistoProductIds)->count();

        $productsWithImages = ProductImage::whereIn('product_id', $bagistoProductIds)
            ->distinct('product_id')
            ->count('product_id');

        $productsWithoutImages = $totalProducts - $productsWithImages;

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total WPS Products in Bagisto', $totalProducts],
                ['Products with Images', $productsWithImages],
                ['Products without Images', $productsWithoutImages],
                ['Total Product Images', $totalImages],
            ]
        );

        // Storage information
        $this->newLine();
        $this->info('Storage Information:');

        $publicPath = storage_path('app/public/product');
        if (is_dir($publicPath)) {
            $files = glob($publicPath . '/*');
            $fileCount = count($files);
            $totalSize = 0;

            foreach ($files as $file) {
                if (is_file($file)) {
                    $totalSize += filesize($file);
                }
            }

            $this->table(
                ['Storage Metric', 'Value'],
                [
                    ['Image Directory', $publicPath],
                    ['Total Files', $fileCount],
                    ['Total Size', $this->formatBytes($totalSize)],
                ]
            );
        } else {
            $this->warn('Product image directory does not exist yet');
        }

        // Show current images (without created_at since it doesn't exist)
        $this->newLine();
        if ($totalImages > 0) {
            $this->info('Recent Images:');
            $recentImages = ProductImage::whereIn('product_id', $bagistoProductIds)
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get();

            $this->table(
                ['ID', 'Product ID', 'Type', 'Image Path'],
                $recentImages->map(function ($image) {
                    return [
                        $image->id,
                        $image->product_id,
                        $image->type,
                        substr($image->path, 0, 40) . '...'
                    ];
                })
            );
        } else {
            $this->warn('No images found yet');
        }

        // Show recent products
        $this->newLine();
        $this->info('WPS Products Ready for Image Sync:');
        $readyProducts = WpsProduct::whereNotNull('bagisto_product_id')
            ->orderBy('updated_at', 'desc')
            ->limit(3)
            ->get();

        $this->table(
            ['WPS ID', 'Bagisto ID', 'Name'],
            $readyProducts->map(function ($product) {
                return [
                    $product->wps_product_id,
                    $product->bagisto_product_id,
                    substr($product->name, 0, 40) . '...'
                ];
            })
        );
    }

    protected function cleanupImages()
    {
        $this->info('Cleaning up orphaned image files...');

        // This is a placeholder for cleanup functionality
        $this->warn('Cleanup functionality not implemented yet');
        $this->info('Manual cleanup: Check storage/app/public/product/ directory');
    }

    protected function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
    protected function fixBrokenImages()
    {
        $this->info('Fixing existing broken image URLs...');

        $result = $this->imageService->fixExistingImages();

        $this->info("Images fixed: {$result['fixed']}");
        $this->error("Errors: {$result['errors']}");
    }
}