<?php

namespace App\Console\Commands\WPS;

use App\Services\WPS\WpsCategoryService;
use App\Models\WPS\WpsProduct;
use Webkul\Category\Models\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWpsCategories extends Command
{
    protected $signature = 'wps:sync-categories 
                            {--limit=50 : Number of products to process at once}
                            {--product-id= : Sync categories for specific product ID}
                            {--dry-run : Show what categories would be created}
                            {--stats : Show current category statistics}
                            {--reset : Reset all product category assignments}';

    protected $description = 'Sync categories from WPS attribute values to Bagisto';

    protected $categoryService;

    public function __construct(WpsCategoryService $categoryService)
    {
        parent::__construct();
        $this->categoryService = $categoryService;
    }

    public function handle()
    {
        try {
            if ($this->option('stats')) {
                $this->showStats();
                return 0;
            }

            if ($this->option('reset')) {
                $this->resetCategoryAssignments();
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

            $this->syncCategories();
            
        } catch (\Exception $e) {
            $this->error('Command failed: ' . $e->getMessage());
            Log::channel('wps')->error('Sync categories command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }

    protected function syncCategories()
    {
        $limit = $this->option('limit');
        $this->info("Syncing categories for WPS products (limit: {$limit})...");
        
        $bar = $this->output->createProgressBar($limit);
        $bar->setFormat('verbose');
        $bar->start();

        $result = $this->categoryService->syncCategoriesForProducts($limit);
        
        $bar->finish();
        $this->newLine();
        
        $this->info("Products processed: {$result['processed']}");
        $this->error("Errors: {$result['errors']}");
        
        if ($result['errors'] > 0) {
            $this->warn('Check WPS logs for detailed error information');
        }
    }

    protected function syncSingleProduct()
    {
        $productId = $this->option('product-id');
        $this->info("Syncing categories for single product: {$productId}");
        
        $wpsProduct = WpsProduct::with('items')
            ->where('wps_product_id', $productId)
            ->whereNotNull('bagisto_product_id')
            ->first();

        if (!$wpsProduct) {
            $this->error('Product not found or not synced to Bagisto yet');
            return;
        }

        $item = $wpsProduct->items()->dropShipEligible()->first();
        if (!$item) {
            $this->error('No drop-ship eligible items found for this product');
            return;
        }

        $this->categoryService->syncCategoriesForProduct($wpsProduct, $item);
        $this->info('Category sync completed for product');
    }

    protected function dryRun()
    {
        $this->info('DRY RUN - Showing potential categories to be created');
        
        $limit = min($this->option('limit'), 10); // Limit dry run to 10 products
        
        $wpsProducts = WpsProduct::with('items')
            ->whereNotNull('bagisto_product_id')
            ->limit($limit)
            ->get();

        $this->table(
            ['WPS Product ID', 'Bagisto Product ID', 'Product Name', 'Sample Item ID'],
            $wpsProducts->map(function ($product) {
                $item = $product->items()->dropShipEligible()->first();
                return [
                    $product->wps_product_id,
                    $product->bagisto_product_id,
                    substr($product->name, 0, 30) . '...',
                    $item ? $item->wps_item_id : 'N/A'
                ];
            })
        );

        $this->newLine();
        $this->warn('Note: This will fetch attribute values from WPS API for each product');
        $this->info("Products to process: {$wpsProducts->count()}");
    }

    protected function showStats()
    {
        $this->info('WPS Category Statistics');
        $this->info('=======================');
        
        // Category stats
        $totalCategories = Category::count();
        $wpsCategories = Category::whereHas('translations', function($q) {
            $q->where('description', 'like', 'WPS category:%');
        })->count();
        
        // Product category assignments
        $totalAssignments = \DB::table('product_categories')->count();
        $wpsProductAssignments = \DB::table('product_categories')
            ->join('products', 'product_categories.product_id', '=', 'products.id')
            ->join('wps_products', 'products.id', '=', 'wps_products.bagisto_product_id')
            ->count();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Categories', $totalCategories],
                ['WPS Categories', $wpsCategories],
                ['Total Category Assignments', $totalAssignments],
                ['WPS Product Assignments', $wpsProductAssignments],
            ]
        );

        // Products ready for category sync
        $readyForSync = WpsProduct::whereNotNull('bagisto_product_id')->count();
        $this->newLine();
        $this->info("Products ready for category sync: {$readyForSync}");
        
        // Show some example categories
        $this->newLine();
        $this->info('Recent WPS Categories:');
        $recentCategories = Category::whereHas('translations', function($q) {
            $q->where('description', 'like', 'WPS category:%');
        })->with('translations')->latest()->limit(5)->get();
        
        if ($recentCategories->count() > 0) {
            $this->table(
                ['ID', 'Name', 'Slug'],
                $recentCategories->map(function ($category) {
                    $translation = $category->translations->where('locale', 'en')->first();
                    return [
                        $category->id,
                        $translation ? $translation->name : 'N/A',
                        $translation ? $translation->slug : 'N/A'
                    ];
                })
            );
        } else {
            $this->warn('No WPS categories created yet');
        }
    }

    protected function resetCategoryAssignments()
    {
        if (!$this->confirm('This will reset all WPS product category assignments. Are you sure?')) {
            $this->info('Operation cancelled');
            return;
        }

        $wpsProductIds = WpsProduct::whereNotNull('bagisto_product_id')
            ->pluck('bagisto_product_id');

        $count = \DB::table('product_categories')
            ->whereIn('product_id', $wpsProductIds)
            ->delete();

        $this->info("Reset {$count} WPS product category assignments");
    }
}