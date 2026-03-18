<?php

namespace App\Console\Commands\Search;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AnalyzeTagCoverage extends Command
{
    protected $signature = 'search:analyze-coverage';

    protected $description = 'Analyze search tag coverage and distribution across products';

    public function handle()
    {
        $this->info('Analyzing search tag coverage...');
        $this->newLine();

        $totalProducts = DB::table('products')->count();
        $totalTags = DB::table('product_search_tags')->count();
        $productsWithTags = DB::table('product_search_tags')
            ->distinct('product_id')
            ->count('product_id');

        $this->info("Total products: " . number_format($totalProducts));
        $this->info("Products with tags: " . number_format($productsWithTags) . " (" . number_format($productsWithTags / $totalProducts * 100, 1) . "%)");
        $this->info("Total tags: " . number_format($totalTags));
        $this->info("Average tags per product: " . number_format($totalTags / max($productsWithTags, 1), 1));
        $this->newLine();

        $this->info('Tag Type Distribution:');
        $this->newLine();

        $tagTypes = DB::table('product_search_tags')
            ->select('tag_type', DB::raw('COUNT(*) as count'), DB::raw('COUNT(DISTINCT product_id) as product_count'))
            ->groupBy('tag_type')
            ->orderBy('count', 'desc')
            ->get();

        $this->table(
            ['Tag Type', 'Total Tags', 'Products', 'Avg per Product'],
            $tagTypes->map(function($type) {
                return [
                    $type->tag_type,
                    number_format($type->count),
                    number_format($type->product_count),
                    number_format($type->count / max($type->product_count, 1), 1)
                ];
            })
        );

        $this->newLine();
        $this->info('Top Vehicle Types:');
        $this->newLine();

        $vehicleTypes = DB::table('product_search_tags')
            ->select('tag_value', DB::raw('COUNT(DISTINCT product_id) as product_count'))
            ->where('tag_type', 'vehicle_type')
            ->groupBy('tag_value')
            ->orderBy('product_count', 'desc')
            ->limit(15)
            ->get();

        $this->table(
            ['Vehicle Type', 'Product Count'],
            $vehicleTypes->map(function($tag) {
                return [$tag->tag_value, number_format($tag->product_count)];
            })
        );

        $this->newLine();
        $this->info('Top Vehicle Brands:');
        $this->newLine();

        $vehicleBrands = DB::table('product_search_tags')
            ->select('tag_value', DB::raw('COUNT(DISTINCT product_id) as product_count'))
            ->where('tag_type', 'vehicle_brand')
            ->groupBy('tag_value')
            ->orderBy('product_count', 'desc')
            ->limit(15)
            ->get();

        $this->table(
            ['Brand', 'Product Count'],
            $vehicleBrands->map(function($tag) {
                return [$tag->tag_value, number_format($tag->product_count)];
            })
        );

        $this->newLine();
        $this->info('Top Part Categories:');
        $this->newLine();

        $partCategories = DB::table('product_search_tags')
            ->select('tag_value', DB::raw('COUNT(DISTINCT product_id) as product_count'))
            ->where('tag_type', 'part_category')
            ->groupBy('tag_value')
            ->orderBy('product_count', 'desc')
            ->limit(15)
            ->get();

        $this->table(
            ['Category', 'Product Count'],
            $partCategories->map(function($tag) {
                return [$tag->tag_value, number_format($tag->product_count)];
            })
        );

        $this->newLine();
        $this->info('Tag Source Distribution:');
        $this->newLine();

        $sources = DB::table('product_search_tags')
            ->select('source', DB::raw('COUNT(*) as count'))
            ->groupBy('source')
            ->orderBy('count', 'desc')
            ->get();

        $this->table(
            ['Source', 'Tag Count'],
            $sources->map(function($source) {
                return [$source->source ?? 'NULL', number_format($source->count)];
            })
        );

        return 0;
    }
}
