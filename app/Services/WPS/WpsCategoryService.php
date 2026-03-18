<?php

namespace App\Services\WPS;

use App\Models\WPS\WpsProduct;
use App\Models\WPS\WpsProductItem;
use Webkul\Category\Models\Category;
use Webkul\Category\Models\CategoryTranslation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WpsCategoryService
{
    protected $apiService;
    protected $defaultLocale = 'en';
    protected $categoryCache = [];

    public function __construct(WpsApiService $apiService)
    {
        $this->apiService = $apiService;
        $this->ensureStorageDirectories();
        $this->loadCategoryCache();
    }

    /**
     * Load existing categories into cache
     */
    protected function loadCategoryCache()
    {
        if (!\Schema::hasTable('categories')) {
            return;
        }

        $categories = Category::with('translations')->get();
        foreach ($categories as $category) {
            $translation = $category->translations->where('locale', $this->defaultLocale)->first();
            if ($translation) {
                $this->categoryCache[strtolower($translation->name)] = $category->id;
            }
        }
    }

    /**
     * Sync categories for all products that have Bagisto products
     */
    public function syncCategoriesForProducts($limit = 100)
    {
        Log::channel('wps')->info('Starting category sync for products', ['limit' => $limit]);

        $wpsProducts = WpsProduct::with('items')
            ->whereNotNull('bagisto_product_id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $errors = 0;

        foreach ($wpsProducts as $wpsProduct) {
            try {
                // Get any eligible item from this product to fetch categories
                $item = $wpsProduct->items()->dropShipEligible()->first();

                if ($item) {
                    $this->syncCategoriesForProduct($wpsProduct, $item);
                    $processed++;
                }

            } catch (\Exception $e) {
                $errors++;
                Log::channel('wps')->error('Failed to sync categories for product', [
                    'wps_product_id' => $wpsProduct->wps_product_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::channel('wps')->info('Category sync completed', [
            'processed' => $processed,
            'errors' => $errors
        ]);

        return ['processed' => $processed, 'errors' => $errors];
    }

    /**
     * Sync categories for a single product
     */
    public function syncCategoriesForProduct($wpsProduct, $wpsItem)
    {
        // Fetch attribute values (categories) from WPS API
        $attributeResponse = $this->apiService->getItemAttributeValues($wpsItem->wps_item_id);

        if (!$attributeResponse || !isset($attributeResponse['data'])) {
            throw new \Exception('Failed to fetch attribute values');
        }

        $categoryIds = [];

        foreach ($attributeResponse['data'] as $attributeValue) {
            Log::channel('wps')->debug('Processing attribute value for category', [
                'wps_product_id' => $wpsProduct->wps_product_id,
                'attribute_name' => $attributeValue['name'],
                'attribute_value' => $attributeValue
            ]);
            
            $categoryId = $this->getOrCreateCategory($attributeValue['name']);
            if ($categoryId) {
                $categoryIds[] = $categoryId;
                Log::channel('wps')->debug('Category ID obtained', [
                    'category_name' => $attributeValue['name'],
                    'category_id' => $categoryId
                ]);
            } else {
                Log::channel('wps')->warning('Failed to get/create category', [
                    'category_name' => $attributeValue['name'],
                    'wps_product_id' => $wpsProduct->wps_product_id
                ]);
            }
        }

        // Assign product to categories
        if (!empty($categoryIds)) {
            $this->assignProductToCategories($wpsProduct->bagisto_product_id, $categoryIds);
        }

        Log::channel('wps')->info('Synced categories for product', [
            'wps_product_id' => $wpsProduct->wps_product_id,
            'bagisto_product_id' => $wpsProduct->bagisto_product_id,
            'categories' => count($categoryIds)
        ]);
    }

    /**
     * Get existing category or create new one
     */
    protected function getOrCreateCategory($categoryName)
    {
        $categoryKey = strtolower(trim($categoryName));

        // Check cache first, but verify the category still exists
        if (isset($this->categoryCache[$categoryKey])) {
            $cachedId = $this->categoryCache[$categoryKey];
            $exists = Category::find($cachedId);
            if ($exists) {
                return $cachedId;
            } else {
                // Remove invalid cache entry
                unset($this->categoryCache[$categoryKey]);
                Log::channel('wps')->warning('Removed invalid category from cache', [
                    'category_name' => $categoryName,
                    'invalid_id' => $cachedId
                ]);
            }
        }

        // Try to find existing category
        $existing = CategoryTranslation::where('locale', $this->defaultLocale)
            ->where('name', $categoryName)
            ->first();

        if ($existing) {
            // Verify the category actually exists
            $category = Category::find($existing->category_id);
            if ($category) {
                $this->categoryCache[$categoryKey] = $existing->category_id;
                return $existing->category_id;
            }
        }

        // Create new category
        $newCategoryId = $this->createNewCategory($categoryName);
        
        // Verify creation was successful before caching
        if ($newCategoryId && Category::find($newCategoryId)) {
            Log::channel('wps')->info('Successfully created and verified new category', [
                'category_name' => $categoryName,
                'category_id' => $newCategoryId
            ]);
            return $newCategoryId;
        } else {
            Log::channel('wps')->error('Category creation failed or category not found after creation', [
                'category_name' => $categoryName,
                'returned_id' => $newCategoryId
            ]);
            return null;
        }
    }

    /**
     * Create a new category
     */
    protected function createNewCategory($categoryName)
    {
        try {
            DB::beginTransaction();

            // Ensure root category exists first
            $this->ensureRootCategoryExists();

            // Create category
            $category = Category::create([
                'position' => 1,
                'status' => 1,
                'parent_id' => 1, // Root category
            ]);

            $slug = $this->generateSlug($categoryName, $category->id);

            // Use direct DB insertion instead of model to avoid fillable restrictions
            DB::table('category_translations')->insert([
                'category_id' => $category->id,  // This is crucial!
                'locale' => $this->defaultLocale,
                'name' => $categoryName,
                'slug' => $slug,
                'url_path' => $slug,
                'description' => "WPS category: {$categoryName}",
                'meta_title' => $categoryName,
                'meta_description' => "Products in {$categoryName} category",
                'meta_keywords' => strtolower($categoryName),
                'locale_id' => 1, // Direct value instead of method call
            ]);

            DB::commit();

            // Update cache
            $this->categoryCache[strtolower($categoryName)] = $category->id;

            Log::channel('wps')->info('Created new category', [
                'category_id' => $category->id,
                'name' => $categoryName
            ]);

            return $category->id;

        } catch (\Exception $e) {
            DB::rollBack();
            Log::channel('wps')->error('Failed to create category', [
                'name' => $categoryName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Get locale ID for the current locale
     */
    protected function getLocaleId()
    {
        try {
            $locale = DB::table('locales')->where('code', $this->defaultLocale)->first();
            return $locale ? $locale->id : 1; // Default to 1 for 'en'
        } catch (\Exception $e) {
            return 1; // Fallback to ID 1
        }
    }

    /**
     * Assign product to categories
     */
    protected function assignProductToCategories($productId, $categoryIds)
    {
        // Filter out null category IDs
        $validCategoryIds = array_filter($categoryIds, function($id) {
            return $id !== null && $id > 0;
        });
        
        if (empty($validCategoryIds)) {
            Log::channel('wps')->warning('No valid category IDs to assign', [
                'product_id' => $productId,
                'attempted_categories' => $categoryIds
            ]);
            return;
        }

        // Verify all category IDs actually exist
        $existingCategories = Category::whereIn('id', $validCategoryIds)->pluck('id')->toArray();
        $missingCategories = array_diff($validCategoryIds, $existingCategories);
        
        if (!empty($missingCategories)) {
            Log::channel('wps')->error('Attempting to assign non-existent categories', [
                'product_id' => $productId,
                'missing_category_ids' => $missingCategories,
                'existing_category_ids' => $existingCategories
            ]);
            // Use only existing categories
            $validCategoryIds = $existingCategories;
        }
        
        if (empty($validCategoryIds)) {
            Log::channel('wps')->warning('No existing categories to assign after validation', [
                'product_id' => $productId
            ]);
            return;
        }

        // Remove existing category assignments
        DB::table('product_categories')
            ->where('product_id', $productId)
            ->delete();

        // Add new category assignments
        foreach ($validCategoryIds as $categoryId) {
            try {
                DB::table('product_categories')->insert([
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                ]);
            } catch (\Exception $e) {
                Log::channel('wps')->error('Failed to assign product to category', [
                    'product_id' => $productId,
                    'category_id' => $categoryId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Ensure storage directories exist with proper permissions
     */
    protected function ensureStorageDirectories()
    {
        $storagePaths = [
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
        ];

        foreach ($storagePaths as $path) {
            if (!is_dir($path)) {
                try {
                    mkdir($path, 0775, true);
                    Log::channel('wps')->info('Created storage directory', ['path' => $path]);
                } catch (\Exception $e) {
                    Log::channel('wps')->error('Failed to create storage directory', [
                        'path' => $path,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Check if directory is writable
            if (!is_writable($path)) {
                try {
                    chmod($path, 0775);
                    Log::channel('wps')->info('Fixed permissions for storage directory', ['path' => $path]);
                } catch (\Exception $e) {
                    Log::channel('wps')->warning('Could not fix permissions for storage directory', [
                        'path' => $path,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    /**
     * Ensure root category exists
     */
    protected function ensureRootCategoryExists()
    {
        $rootCategory = Category::find(1);
        
        if (!$rootCategory) {
            Log::channel('wps')->info('Creating missing root category');
            
            // Create root category
            $category = Category::create([
                'id' => 1,
                'position' => 1,
                'status' => 1,
                'parent_id' => null,
            ]);
            
            // Create root category translation
            DB::table('category_translations')->insert([
                'category_id' => 1,
                'locale' => $this->defaultLocale,
                'name' => 'Root',
                'slug' => 'root',
                'url_path' => 'root',
                'description' => 'Root category for all products',
                'meta_title' => 'Root',
                'meta_description' => 'Root category',
                'meta_keywords' => 'root',
                'locale_id' => 1,
            ]);
            
            Log::channel('wps')->info('Root category created', ['category_id' => 1]);
        }
    }

    /**
     * Generate category slug
     */
    protected function generateSlug($name, $categoryId)
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        return $slug . '-' . $categoryId;
    }
}
