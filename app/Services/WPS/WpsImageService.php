<?php

namespace App\Services\WPS;

use App\Models\WPS\WpsProduct;
use App\Models\WPS\WpsProductItem;
use Webkul\Product\Models\ProductImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class WpsImageService
{
    protected $apiService;

    public function __construct(WpsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Sync images for products that have Bagisto products
     */
    public function syncImagesForProducts($limit = 50)
    {
        Log::channel('wps')->info('Starting image URL sync for products', ['limit' => $limit]);
        
        $wpsProducts = WpsProduct::with('items')
            ->whereNotNull('bagisto_product_id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $errors = 0;
        $totalImages = 0;

        foreach ($wpsProducts as $wpsProduct) {
            try {
                $imageCount = $this->syncImagesForProduct($wpsProduct);
                $totalImages += $imageCount;
                $processed++;
                
                Log::channel('wps')->info('Synced image URLs for product', [
                    'wps_product_id' => $wpsProduct->wps_product_id,
                    'bagisto_product_id' => $wpsProduct->bagisto_product_id,
                    'images' => $imageCount
                ]);
                
            } catch (\Exception $e) {
                $errors++;
                Log::channel('wps')->error('Failed to sync images for product', [
                    'wps_product_id' => $wpsProduct->wps_product_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::channel('wps')->info('Image URL sync completed', [
            'processed' => $processed,
            'errors' => $errors,
            'total_images' => $totalImages
        ]);

        return [
            'processed' => $processed, 
            'errors' => $errors, 
            'total_images' => $totalImages
        ];
    }

    /**
     * Sync images for a single product
     */
    public function syncImagesForProduct($wpsProduct)
    {
        $totalImages = 0;
        $eligibleItems = $wpsProduct->items()->dropShipEligible()->get();

        foreach ($eligibleItems as $item) {
            $imageCount = $this->syncImagesForItem($wpsProduct, $item);
            $totalImages += $imageCount;
        }

        return $totalImages;
    }

    /**
     * Sync images for a single item
     */
    protected function syncImagesForItem($wpsProduct, $wpsItem)
    {
        // Fetch images from WPS API
        $imagesResponse = $this->apiService->getItemImages($wpsItem->wps_item_id);
        
        if (!$imagesResponse || !isset($imagesResponse['data'])) {
            return 0;
        }

        $imageCount = 0;

        foreach ($imagesResponse['data'] as $imageData) {
            try {
                $this->saveImageUrl($wpsProduct, $imageData);
                $imageCount++;
            } catch (\Exception $e) {
                Log::channel('wps')->error('Failed to save image URL', [
                    'wps_product_id' => $wpsProduct->wps_product_id,
                    'image_id' => $imageData['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $imageCount;
    }

    /**
     * Save image URL with proper path mapping
     */
    protected function saveImageUrl($wpsProduct, $imageData)
    {
        // Build full image URL
        $sourceUrl = 'https://' . $imageData['domain'] . $imageData['path'] . $imageData['filename'];
        
        // Create relative path for Bagisto
        $relativePath = 'wps/' . $imageData['filename'];
        
        // Check if image already exists
        $existingImage = ProductImage::where('product_id', $wpsProduct->bagisto_product_id)
            ->where('path', $relativePath)
            ->first();
            
        if ($existingImage) {
            Log::channel('wps')->info('Image already exists, skipping', [
                'filename' => $imageData['filename']
            ]);
            return;
        }

        // Store URL mapping
        $this->storeImageUrlMapping($relativePath, $sourceUrl, $imageData['filename']);

        // Create ProductImage record with relative path
        ProductImage::create([
            'product_id' => $wpsProduct->bagisto_product_id,
            'type' => 'images',
            'path' => $relativePath,
            'position' => 0,
        ]);

        Log::channel('wps')->info('Image URL mapped and saved', [
            'wps_product_id' => $wpsProduct->wps_product_id,
            'filename' => $imageData['filename'],
            'relative_path' => $relativePath,
            'source_url' => $sourceUrl
        ]);
    }

    /**
     * Store image URL mapping in database
     */
    protected function storeImageUrlMapping($path, $sourceUrl, $filename)
    {
        DB::table('wps_image_urls')->insertOrIgnore([
            'path' => $path,
            'source_url' => $sourceUrl,
            'filename' => $filename,
            'created_at' => now()
        ]);
    }

    /**
     * Fix existing broken image URLs
     */
    public function fixExistingImages()
    {
        Log::channel('wps')->info('Starting to fix existing broken images');
        
        $brokenImages = ProductImage::whereIn('product_id', 
            WpsProduct::whereNotNull('bagisto_product_id')->pluck('bagisto_product_id')
        )->where('path', 'like', 'https://%')->get();

        $fixed = 0;
        $errors = 0;

        foreach ($brokenImages as $image) {
            try {
                $filename = basename($image->path);
                $newPath = 'wps/' . $filename;
                
                // Store URL mapping
                $this->storeImageUrlMapping($newPath, $image->path, $filename);
                
                // Update image path
                $image->update(['path' => $newPath]);
                
                $fixed++;
                
                Log::channel('wps')->info('Fixed broken image', [
                    'image_id' => $image->id,
                    'old_path' => $image->path,
                    'new_path' => $newPath
                ]);
                
            } catch (\Exception $e) {
                $errors++;
                Log::channel('wps')->error('Failed to fix image', [
                    'image_id' => $image->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::channel('wps')->info('Finished fixing broken images', [
            'fixed' => $fixed,
            'errors' => $errors
        ]);

        return ['fixed' => $fixed, 'errors' => $errors];
    }
}