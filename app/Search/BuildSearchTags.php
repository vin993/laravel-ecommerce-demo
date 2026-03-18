<?php

namespace App\Console\Commands\Search;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Search\TagExtractorService;
use App\Services\Search\VehicleTypeMapper;

class BuildSearchTags extends Command
{
    protected $signature = 'search:build-tags
                            {--batch=10000 : Number of products to process per batch}
                            {--skip=0 : Skip first N products}
                            {--resume : Resume from last processed product}
                            {--product-id= : Process single product by ID}
                            {--clear : Clear existing tags before building}
                            {--dry-run : Show extraction without saving}
                            {--limit= : Limit total products to process}';

    protected $description = 'Extract and build search tags for products from descriptions, categories, and fitment data';

    protected $tagExtractor;
    protected $startTime;

    public function handle()
    {
        $this->startTime = microtime(true);

        $this->tagExtractor = new TagExtractorService(new VehicleTypeMapper());

        if ($this->option('product-id')) {
            return $this->processSingleProduct();
        }

        if ($this->option('dry-run')) {
            return $this->dryRunSample();
        }

        if ($this->option('clear')) {
            if ($this->confirm('This will DELETE all existing search tags. Continue?', false)) {
                $this->info('Clearing existing tags...');
                DB::table('product_search_tags')->truncate();
                $this->info('Tags cleared.');
            } else {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        return $this->processAllProducts();
    }

    protected function processSingleProduct()
    {
        $productId = $this->option('product-id');

        $product = DB::table('products')
            ->join('product_flat', 'products.id', '=', 'product_flat.product_id')
            ->select('products.id', 'products.sku', 'product_flat.name')
            ->where('products.id', $productId)
            ->where('product_flat.locale', 'en')
            ->first();

        if (!$product) {
            $this->error("Product ID {$productId} not found.");
            return 1;
        }

        $this->info("Processing product: {$product->sku} - {$product->name}");

        $result = $this->tagExtractor->extractTagsForProduct($product, true);

        $this->table(
            ['Tag Type', 'Tag Value', 'Weight', 'Source'],
            collect($result['tags'])->map(function($tag) {
                return [
                    $tag['tag_type'],
                    $tag['tag_value'],
                    $tag['weight'],
                    $tag['source']
                ];
            })
        );

        $this->info("Total tags extracted: " . $result['tag_count']);

        if (!$this->option('dry-run')) {
            $this->tagExtractor->clearTagsForProduct($productId);
            $saved = $this->tagExtractor->saveTags($productId, $result['tags']);
            $this->info("Saved {$saved} tags to database.");
        }

        return 0;
    }

    protected function dryRunSample()
    {
        $this->info('Dry run mode - processing sample of 10 products...');

        $products = DB::table('products')
            ->join('product_flat', 'products.id', '=', 'product_flat.product_id')
            ->select('products.id', 'products.sku', 'product_flat.name')
            ->where('product_flat.locale', 'en')
            ->inRandomOrder()
            ->limit(10)
            ->get();

        foreach ($products as $product) {
            $this->info("\n--- Product: {$product->sku} ---");
            $this->info($product->name);

            $result = $this->tagExtractor->extractTagsForProduct($product, true);

            $this->table(
                ['Tag Type', 'Tag Value', 'Weight'],
                collect($result['tags'])->map(function($tag) {
                    return [$tag['tag_type'], $tag['tag_value'], $tag['weight']];
                })
            );
        }

        return 0;
    }

    protected function processAllProducts()
    {
        $batchSize = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $limit = $this->option('limit') ? (int) $this->option('limit') : null;

        if ($this->option('resume')) {
            $lastProcessedId = DB::table('product_search_tags')
                ->max('product_id');

            if ($lastProcessedId) {
                $skip = DB::table('products')
                    ->where('id', '<=', $lastProcessedId)
                    ->count();
                $this->info("Resume mode: Last processed product ID {$lastProcessedId}");
                $this->info("Skipping first {$skip} products");
            } else {
                $this->info("Resume mode: No existing tags found, starting from beginning");
            }
        }

        $totalProducts = DB::table('products')->count();

        if ($limit) {
            $totalProducts = min($totalProducts, $limit);
        }

        $this->info("Processing {$totalProducts} products in batches of {$batchSize}");
        $this->info("Starting from offset: {$skip}");

        $bar = $this->output->createProgressBar($totalProducts);
        $bar->start();

        $processed = 0;
        $totalTags = 0;
        $offset = $skip;

        while ($processed < $totalProducts) {
            $products = DB::table('products')
                ->join('product_flat', 'products.id', '=', 'product_flat.product_id')
                ->select('products.id', 'products.sku', 'product_flat.name')
                ->where('product_flat.locale', 'en')
                ->orderBy('products.id')
                ->skip($offset)
                ->take($batchSize)
                ->get();

            if ($products->isEmpty()) {
                break;
            }

            foreach ($products as $product) {
                try {
                    $tags = $this->tagExtractor->extractTagsForProduct($product, false);

                    if (!empty($tags)) {
                        $this->tagExtractor->clearTagsForProduct($product->id);
                        $saved = $this->tagExtractor->saveTags($product->id, $tags);
                        $totalTags += $saved;
                    }

                    $bar->advance();
                    $processed++;

                    if ($limit && $processed >= $limit) {
                        break 2;
                    }

                } catch (\Exception $e) {
                    $this->error("\nError processing product {$product->id}: " . $e->getMessage());
                }
            }

            $offset += $batchSize;

            if ($processed % 1000 == 0) {
                $elapsed = microtime(true) - $this->startTime;
                $rate = $processed / $elapsed;
                $remaining = ($totalProducts - $processed) / $rate;

                $this->newLine();
                $this->info("Progress: {$processed}/{$totalProducts} | Tags: {$totalTags} | Rate: " . number_format($rate, 1) . " products/sec | Est. remaining: " . gmdate('H:i:s', $remaining));
            }
        }

        $bar->finish();
        $this->newLine(2);

        $elapsed = microtime(true) - $this->startTime;
        $avgRate = $processed / $elapsed;

        $this->info("Processing complete!");
        $this->info("Products processed: " . number_format($processed));
        $this->info("Total tags created: " . number_format($totalTags));
        $this->info("Average tags per product: " . number_format($totalTags / max($processed, 1), 1));
        $this->info("Total time: " . gmdate('H:i:s', $elapsed));
        $this->info("Average rate: " . number_format($avgRate, 1) . " products/sec");

        return 0;
    }
}
