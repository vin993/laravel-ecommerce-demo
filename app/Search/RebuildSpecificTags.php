<?php

namespace App\Console\Commands\Search;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Search\TagExtractorService;
use App\Services\Search\VehicleTypeMapper;

class RebuildSpecificTags extends Command
{
    protected $signature = 'search:rebuild-specific-tags
                            {--pattern= : Pattern to match in product names}
                            {--tag-type= : Only rebuild specific tag type}
                            {--dry-run : Show what would be updated}';

    protected $description = 'Rebuild tags only for products matching specific criteria';

    protected $tagExtractor;
    protected $startTime;

    public function handle()
    {
        $this->startTime = microtime(true);
        $this->tagExtractor = new TagExtractorService(new VehicleTypeMapper());

        $pattern = $this->option('pattern');
        $tagType = $this->option('tag-type');
        $dryRun = $this->option('dry-run');

        if (!$pattern && !$tagType) {
            $this->error('Please specify --pattern or --tag-type');
            return 1;
        }

        $query = DB::table('products')
            ->join('product_flat', 'products.id', '=', 'product_flat.product_id')
            ->where('product_flat.locale', 'en');

        if ($pattern) {
            $query->where('product_flat.name', 'like', "%{$pattern}%");
        }

        if ($tagType) {
            $query->whereExists(function($q) use ($tagType) {
                $q->select(DB::raw(1))
                    ->from('product_search_tags')
                    ->whereRaw('product_search_tags.product_id = products.id')
                    ->where('product_search_tags.tag_type', $tagType);
            });
        }

        $products = $query->select('products.id', 'products.sku', 'product_flat.name')->get();

        $this->info("Found " . number_format($products->count()) . " products to update");

        if ($dryRun) {
            $this->info("Dry run - showing first 10 products:");
            foreach ($products->take(10) as $product) {
                $this->line("  {$product->sku}: {$product->name}");
            }
            return 0;
        }

        if (!$this->confirm('Proceed with rebuild?', false)) {
            $this->info('Cancelled');
            return 0;
        }

        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        $updated = 0;

        foreach ($products as $product) {
            try {
                $tags = $this->tagExtractor->extractTagsForProduct($product, false);

                if (!empty($tags)) {
                    $this->tagExtractor->clearTagsForProduct($product->id);
                    $this->tagExtractor->saveTags($product->id, $tags);
                    $updated++;
                }

                $bar->advance();
            } catch (\Exception $e) {
                $this->error("\nError processing product {$product->id}: " . $e->getMessage());
            }
        }

        $bar->finish();
        $this->newLine(2);

        $elapsed = microtime(true) - $this->startTime;

        $this->info("Update complete!");
        $this->info("Products updated: " . number_format($updated));
        $this->info("Total time: " . gmdate('H:i:s', $elapsed));

        return 0;
    }
}
