<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillKawasakiProductAttributesCommand extends Command
{
    protected $signature = 'kawasaki:backfill-attributes 
                            {--limit=100 : Number of products to process per batch}
                            {--dry-run : Run without updating database}';

    protected $description = 'Backfill product_attribute_values for existing Kawasaki products';

    public function handle()
    {
        $batchSize = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info("Backfilling product attributes" . ($dryRun ? " [DRY RUN]" : ""));

        $attributeMapping = [
            'name' => ['id' => 2, 'type' => 'text'],
            'description' => ['id' => 10, 'type' => 'text'],
            'short_description' => ['id' => 9, 'type' => 'text'],
            'price' => ['id' => 11, 'type' => 'float'],
            'weight' => ['id' => 22, 'type' => 'float'],
            'status' => ['id' => 8, 'type' => 'boolean'],
        ];

        $totalProducts = DB::table('product_flat')
            ->whereNotNull('product_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('product_attribute_values')
                    ->whereColumn('product_attribute_values.product_id', 'product_flat.product_id')
                    ->where('product_attribute_values.attribute_id', 2);
            })
            ->count();

        if ($totalProducts === 0) {
            $this->info("No products need backfilling.");
            return 0;
        }

        $this->info("Found {$totalProducts} products without attributes.");
        $processed = 0;
        $offset = 0;

        while ($offset < $totalProducts) {
            $products = DB::table('product_flat')
                ->select('product_id', 'name', 'description', 'short_description', 'price', 'weight', 'status')
                ->whereNotNull('product_id')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('product_attribute_values')
                        ->whereColumn('product_attribute_values.product_id', 'product_flat.product_id')
                        ->where('product_attribute_values.attribute_id', 2);
                })
                ->offset($offset)
                ->limit($batchSize)
                ->get();

            if ($products->isEmpty()) {
                break;
            }

            $this->info("Processing batch: " . ($offset + 1) . " to " . ($offset + $products->count()));

            foreach ($products as $product) {
                if ($dryRun) {
                    $this->line("Would process product ID: {$product->product_id}");
                    continue;
                }

                foreach ($attributeMapping as $key => $config) {
                    $value = $product->$key ?? null;

                    if ($value === null) {
                        continue;
                    }

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

                $processed++;
            }

            $offset += $batchSize;
            $this->info("Processed: {$processed} / {$totalProducts}");
        }

        $this->info("Completed! Processed {$processed} products.");

        return 0;
    }
}
