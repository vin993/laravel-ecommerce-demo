<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillKawasakiMissingAttributesCommand extends Command
{
    protected $signature = 'kawasaki:backfill-missing-attributes 
                            {--limit=5000 : Number of products to process per batch}';

    protected $description = 'Backfill missing attributes (length, width, height, url_key, brand, status) for Kawasaki products';

    public function handle()
    {
        $batchSize = (int) $this->option('limit');

        $this->info("Backfilling missing Kawasaki product attributes");

        $attributeMapping = [
            'url_key' => ['id' => 3, 'type' => 'text'],
            'status' => ['id' => 8, 'type' => 'boolean'],
            'length' => ['id' => 19, 'type' => 'float'],
            'width' => ['id' => 20, 'type' => 'float'],
            'height' => ['id' => 21, 'type' => 'float'],
            'brand' => ['id' => 25, 'type' => 'text'],
        ];

        $totalProducts = DB::table('product_flat')
            ->whereNotNull('product_id')
            ->count();

        if ($totalProducts === 0) {
            $this->info("No products found.");
            return 0;
        }

        $this->info("Found {$totalProducts} products to update.");
        $processed = 0;
        $offset = 0;

        while ($offset < $totalProducts) {
            $products = DB::table('product_flat')
                ->select('product_id', 'sku', 'name')
                ->whereNotNull('product_id')
                ->offset($offset)
                ->limit($batchSize)
                ->get();

            if ($products->isEmpty()) {
                break;
            }

            $this->info("Processing batch: " . ($offset + 1) . " to " . ($offset + $products->count()));

            foreach ($products as $product) {
                $updates = [
                    'url_key' => Str::slug($product->name . '-' . $product->sku),
                    'status' => 1,
                    'length' => 0,
                    'width' => 0,
                    'height' => 0,
                    'brand' => 'Kawasaki',
                ];

                foreach ($attributeMapping as $key => $config) {
                    $value = $updates[$key];

                    $data = [
                        'product_id' => $product->product_id,
                        'attribute_id' => $config['id'],
                        'channel' => 'maddparts',
                        'locale' => 'en',
                    ];

                    switch ($config['type']) {
                        case 'text':
                            $data['text_value'] = $value;
                            break;
                        case 'float':
                            $data['float_value'] = (float) $value;
                            break;
                        case 'boolean':
                            $data['boolean_value'] = (bool) $value;
                            break;
                    }

                    DB::table('product_attribute_values')->updateOrInsert(
                        [
                            'product_id' => $product->product_id,
                            'attribute_id' => $config['id'],
                            'channel' => 'maddparts',
                            'locale' => 'en',
                        ],
                        $data
                    );
                }

                DB::table('product_flat')
                    ->where('product_id', $product->product_id)
                    ->update([
                        'status' => 1,
                        'url_key' => $updates['url_key'],
                    ]);

                $processed++;
            }

            $offset += $batchSize;
            $this->info("Processed: {$processed} / {$totalProducts}");
        }

        $this->info("Completed! Processed {$processed} products.");

        return 0;
    }
}
