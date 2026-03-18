<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateProductFlat extends Command
{
    protected $signature = 'product:populate-flat {--batch=1000}';
    protected $description = 'Populate product_flat table for missing products';

    public function handle()
    {
        $batch = (int) $this->option('batch');
        
        $this->info('Finding products missing from product_flat...');
        
        $missingProducts = DB::table('products')
            ->leftJoin('product_flat', 'products.id', '=', 'product_flat.product_id')
            ->whereNull('product_flat.product_id')
            ->select('products.id', 'products.type', 'products.parent_id')
            ->orderByRaw('CASE WHEN products.type = "configurable" THEN 1 WHEN products.parent_id IS NULL THEN 2 ELSE 3 END')
            ->limit($batch)
            ->get();

        if ($missingProducts->isEmpty()) {
            $this->info('All products are already in product_flat table');
            return;
        }

        $this->info("Found {$missingProducts->count()} missing products to sync");

        $synced = 0;
        foreach ($missingProducts as $product) {
            $this->syncProductToFlat($product->id);
            $synced++;
            
            if ($synced % 100 === 0) {
                $this->line("Synced {$synced}/{$missingProducts->count()} products");
            }
        }

        $this->info("Successfully synced {$synced} products to product_flat");
        
        $remaining = DB::table('products')
            ->leftJoin('product_flat', 'products.id', '=', 'product_flat.product_id')
            ->whereNull('product_flat.product_id')
            ->count();
            
        if ($remaining > 0) {
            $this->info("Run again to sync remaining {$remaining} products");
        } else {
            $this->info("All products synced to product_flat!");
        }
    }

    private function syncProductToFlat($productId)
    {
        $product = DB::table('products')->where('id', $productId)->first();
        if (!$product) return;

        $attributes = DB::table('product_attribute_values')
            ->where('product_id', $productId)
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->get()
            ->keyBy('attribute_id');

        $name = $attributes[2]->text_value ?? '';
        $sku = $attributes[1]->text_value ?? $product->sku;
        $description = $attributes[9]->text_value ?? '';
        $shortDescription = $attributes[10]->text_value ?? '';
        $price = $attributes[11]->float_value ?? null;
        $status = $attributes[8]->boolean_value ?? 1;
        $visibleIndividually = $attributes[7]->boolean_value ?? 1;
        $urlKey = $attributes[3]->text_value ?? $sku;

        $parentFlatId = null;
        if ($product->parent_id) {
            $parentFlatId = DB::table('product_flat')
                ->where('product_id', $product->parent_id)
                ->value('id');
        }

        DB::table('product_flat')->insert([
            'product_id' => $productId,
            'sku' => $sku,
            'name' => $name,
            'description' => $description,
            'short_description' => $shortDescription,
            'url_key' => $urlKey,
            'price' => $price,
            'status' => $status,
            'visible_individually' => $visibleIndividually,
            'type' => $product->type,
            'parent_id' => $parentFlatId,
            'attribute_family_id' => $product->attribute_family_id,
            'channel' => 'maddparts',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}