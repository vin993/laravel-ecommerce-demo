<?php

namespace App\Console\Commands\WPS;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClearWpsData extends Command
{
    protected $signature = 'wps:clear-data 
                            {--confirm : Confirm data deletion}
                            {--keep-structure : Only clear data but keep Bagisto products}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Clear all WPS data from database';

    public function handle()
    {
        if ($this->option('dry-run')) {
            $this->performDryRun();
            return 0;
        }

        if (!$this->option('confirm')) {
            $this->error('This will delete ALL WPS data. Use --confirm flag to proceed.');
            $this->info('Options:');
            $this->info('  --confirm         : Confirm data deletion');
            $this->info('  --keep-structure  : Only clear data but keep Bagisto products');
            $this->info('  --dry-run         : Show what would be deleted');
            return 1;
        }

        try {
            $this->clearWpsData();
            return 0;
        } catch (\Exception $e) {
            $this->error('Command failed: ' . $e->getMessage());
            Log::channel('wps')->error('Clear WPS data command failed', [
                'error' => $e->getMessage()
            ]);
            return 1;
        }
    }

    protected function performDryRun()
    {
        $this->info('DRY RUN - No data will be deleted');
        $this->info('================================');

        // Count WPS data
        $wpsProducts = DB::table('wps_products')->count();
        $wpsItems = DB::table('wps_product_items')->count();
        $wpsImages = DB::getSchemaBuilder()->hasTable('wps_image_urls') 
            ? DB::table('wps_image_urls')->count() 
            : 0;

        // Count Bagisto products from WPS
        $bagistoProducts = DB::table('wps_products')
            ->whereNotNull('bagisto_product_id')
            ->count();

        $this->table(['Data Type', 'Count', 'Action'], [
            ['WPS Products', $wpsProducts, 'Will be deleted'],
            ['WPS Product Items', $wpsItems, 'Will be deleted'],
            ['WPS Image URLs', $wpsImages, 'Will be deleted'],
            ['Bagisto Products (from WPS)', $bagistoProducts, 'Will be deleted (unless --keep-structure)'],
        ]);

        if ($bagistoProducts > 0) {
            $this->warn("This will delete {$bagistoProducts} Bagisto products!");
            $this->info("Use --keep-structure to preserve Bagisto products");
        }
    }

    protected function clearWpsData()
    {
        $this->info('Starting WPS data cleanup...');

        DB::beginTransaction();

        try {
            $deletedCounts = [];

            if (!$this->option('keep-structure')) {
                $deletedCounts = $this->deleteBagistoProducts();
            } else {
                $this->clearBagistoReferences();
                $deletedCounts['bagisto_action'] = 'References cleared';
            }

            // Clear WPS tracking tables
            $this->info('Clearing WPS tracking tables...');
            
            $wpsItemsCount = DB::table('wps_product_items')->count();
            DB::table('wps_product_items')->delete();
            $deletedCounts['wps_items'] = $wpsItemsCount;
            
            $wpsProductsCount = DB::table('wps_products')->count();
            DB::table('wps_products')->delete();
            $deletedCounts['wps_products'] = $wpsProductsCount;
            
            // Clear WPS image URLs if table exists
            $wpsImagesCount = 0;
            if (DB::getSchemaBuilder()->hasTable('wps_image_urls')) {
                $wpsImagesCount = DB::table('wps_image_urls')->count();
                DB::table('wps_image_urls')->delete();
            }
            $deletedCounts['wps_images'] = $wpsImagesCount;

            // Reset auto-increment counters
            if (DB::getSchemaBuilder()->hasTable('wps_products')) {
                DB::statement('ALTER TABLE wps_products AUTO_INCREMENT = 1');
            }
            if (DB::getSchemaBuilder()->hasTable('wps_product_items')) {
                DB::statement('ALTER TABLE wps_product_items AUTO_INCREMENT = 1');
            }

            DB::commit();
            
            $this->info('WPS data cleanup completed successfully!');
            $this->showSummary($deletedCounts);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function deleteBagistoProducts()
    {
        $wpsProductIds = DB::table('wps_products')
            ->whereNotNull('bagisto_product_id')
            ->pluck('bagisto_product_id');

        $deletedCounts = ['bagisto_products' => 0];

        if ($wpsProductIds->isNotEmpty()) {
            $this->info('Deleting ' . $wpsProductIds->count() . ' Bagisto products...');
            
            // Delete in correct order to avoid foreign key issues
            $this->info('  - Deleting product attribute values...');
            DB::table('product_attribute_values')->whereIn('product_id', $wpsProductIds)->delete();
            
            $this->info('  - Deleting product images...');
            DB::table('product_images')->whereIn('product_id', $wpsProductIds)->delete();
            
            $this->info('  - Deleting product categories...');
            DB::table('product_categories')->whereIn('product_id', $wpsProductIds)->delete();
            
            $this->info('  - Deleting product inventories...');
            DB::table('product_inventories')->whereIn('product_id', $wpsProductIds)->delete();
            
            $this->info('  - Deleting product flat data...');
            DB::table('product_flat')->whereIn('product_id', $wpsProductIds)->delete();
            
            $this->info('  - Deleting products...');
            DB::table('products')->whereIn('id', $wpsProductIds)->delete();
            
            $deletedCounts['bagisto_products'] = $wpsProductIds->count();
            $this->info('Deleted Bagisto products and related data');
        }

        return $deletedCounts;
    }

    protected function clearBagistoReferences()
    {
        $this->info('Clearing Bagisto product references from WPS tables...');
        DB::table('wps_products')->update(['bagisto_product_id' => null]);
        DB::table('wps_product_items')->update(['bagisto_product_id' => null]);
    }

    protected function showSummary($counts)
    {
        $this->info('');
        $this->info('Cleanup Summary:');
        $this->info('================');
        
        $rows = [];
        
        if (isset($counts['wps_products'])) {
            $rows[] = ['WPS Products', $counts['wps_products'], 'Deleted'];
        }
        
        if (isset($counts['wps_items'])) {
            $rows[] = ['WPS Product Items', $counts['wps_items'], 'Deleted'];
        }
        
        if (isset($counts['wps_images'])) {
            $rows[] = ['WPS Image URLs', $counts['wps_images'], 'Deleted'];
        }
        
        if (isset($counts['bagisto_products'])) {
            $rows[] = ['Bagisto Products', $counts['bagisto_products'], 'Deleted'];
        } elseif (isset($counts['bagisto_action'])) {
            $rows[] = ['Bagisto Products', 'N/A', $counts['bagisto_action']];
        }

        $this->table(['Data Type', 'Count', 'Action'], $rows);
    }
}