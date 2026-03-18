<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignProductBrands extends Command
{
    protected $signature = 'ari:assign-product-brands 
                            {--batch=1000 : Number of products to process per batch}
                            {--skip=0 : Number of products to skip}
                            {--dry-run : Run without making changes}';
    
    protected $description = 'Assign brands to products based on manufacturer data';

    public function handle()
    {
        $batchSize = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting product brand assignment...');

        // Get brand attribute
        $brandAttribute = DB::table('attributes')->where('code', 'brand')->first();
        if (!$brandAttribute) {
            $this->error('Brand attribute not found');
            return;
        }

        // Get all brand options as lookup
        $brandOptions = DB::table('attribute_options')
            ->where('attribute_id', $brandAttribute->id)
            ->get()
            ->keyBy(function($item) {
                return strtolower($item->admin_name);
            });

        $this->info("Found {$brandOptions->count()} brand options");

        // Get products that need brand assignment
        $query = DB::table('products as p')
            ->leftJoin('product_attribute_values as pav', function($join) use ($brandAttribute) {
                $join->on('p.id', '=', 'pav.product_id')
                     ->where('pav.attribute_id', '=', $brandAttribute->id);
            })
            ->whereNull('pav.id') // Products without brand assigned
            ->select('p.id', 'p.sku');

        $totalProducts = $query->count();
        $this->info("Found {$totalProducts} products without brands");

        if ($totalProducts === 0) {
            $this->info('All products already have brands assigned');
            return;
        }

        $products = $query->skip($skip)->take($batchSize)->get();
        $processed = 0;
        $assigned = 0;
        $notFound = 0;

        foreach ($products as $product) {
            $processed++;
            
            // Find manufacturer from product attributes or mapping
            $manufacturerName = $this->findProductManufacturer($product->sku);
            
            if (!$manufacturerName) {
                $notFound++;
                continue;
            }

            // Find matching brand option
            $brandOption = $brandOptions->get(strtolower($manufacturerName));
            
            if (!$brandOption) {
                $this->line("Brand option not found for: {$manufacturerName}");
                $notFound++;
                continue;
            }

            if (!$dryRun) {
                // Assign brand to product
                DB::table('product_attribute_values')->insert([
                    'product_id' => $product->id,
                    'attribute_id' => $brandAttribute->id,
                    'locale' => 'en',
                    'channel' => 'default',
                    'text_value' => (string) $brandOption->id,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            $assigned++;
            if ($processed % 100 === 0) {
                $this->line("Processed: {$processed}/{$batchSize}");
            }
        }

        $this->info("Batch complete:");
        $this->info("- Processed: {$processed}");
        $this->info("- Assigned: {$assigned}");
        $this->info("- Not found: {$notFound}");
        
        $remaining = $totalProducts - ($skip + $batchSize);
        if ($remaining > 0) {
            $nextSkip = $skip + $batchSize;
            $this->info("Continue with: php artisan ari:assign-product-brands --skip={$nextSkip} --batch={$batchSize}");
        }
    }

    private function findProductManufacturer($sku)
    {
        // Try to find manufacturer from ds_bagisto_product_mapping and ds_manufacturer_index
        $manufacturer = DB::table('ds_bagisto_product_mapping as m')
            ->join('ds_manufacturer_index as mi', 'm.ds_manufacturer_id', '=', 'mi.manufacturer_id')
            ->where('m.bagisto_sku', $sku)
            ->value('mi.manufacturer_name');

        if ($manufacturer) {
            return trim($manufacturer);
        }

        // Alternative: try to find from product attributes
        $manufacturerAttr = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'pav.attribute_id', '=', 'a.id')
            ->join('products as p', 'pav.product_id', '=', 'p.id')
            ->where('p.sku', $sku)
            ->where('a.code', 'LIKE', '%manufacturer%')
            ->value('pav.text_value');

        return $manufacturerAttr ? trim($manufacturerAttr) : null;
    }
}