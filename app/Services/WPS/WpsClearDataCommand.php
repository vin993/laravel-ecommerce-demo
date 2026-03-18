<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WpsClearDataCommand extends Command
{
    protected $signature = 'wps:clear-data {--confirm} {--keep-structure}';
    protected $description = 'Clear all WPS data from database';

    public function handle()
    {
        if (!$this->option('confirm')) {
            $this->error('This will delete ALL WPS data. Use --confirm flag to proceed.');
            $this->info('Add --keep-structure to only clear data but keep Bagisto products');
            return 1;
        }

        $this->info('Starting WPS data cleanup...');

        try {
            DB::beginTransaction();

            if (!$this->option('keep-structure')) {
                $wpsProductIds = DB::table('wps_products')
                    ->whereNotNull('bagisto_product_id')
                    ->pluck('bagisto_product_id');

                if ($wpsProductIds->isNotEmpty()) {
                    $this->info('Deleting ' . $wpsProductIds->count() . ' Bagisto products...');
                    
                    DB::table('product_attribute_values')->whereIn('product_id', $wpsProductIds)->delete();
                    DB::table('product_images')->whereIn('product_id', $wpsProductIds)->delete();
                    DB::table('product_categories')->whereIn('product_id', $wpsProductIds)->delete();
                    DB::table('product_inventories')->whereIn('product_id', $wpsProductIds)->delete();
                    DB::table('product_flat')->whereIn('product_id', $wpsProductIds)->delete();
                    DB::table('products')->whereIn('id', $wpsProductIds)->delete();
                    
                    $this->info('Deleted Bagisto products and related data');
                }
            } else {
                DB::table('wps_products')->update(['bagisto_product_id' => null]);
                DB::table('wps_product_items')->update(['bagisto_product_id' => null]);
                $this->info('Cleared Bagisto product references from WPS tables');
            }
            $this->info('Clearing WPS tracking tables...');
            DB::table('wps_product_items')->delete();
            DB::table('wps_products')->delete();
            
            if (DB::getSchemaBuilder()->hasTable('wps_image_urls')) {
                DB::table('wps_image_urls')->delete();
            }

            DB::commit();
            
            $this->info('WPS data cleanup completed successfully!');
            return 0;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error during cleanup: ' . $e->getMessage());
            Log::error('WPS cleanup error', ['error' => $e->getMessage()]);
            return 1;
        }
    }
}