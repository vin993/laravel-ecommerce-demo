<?php

namespace App\Console\Commands\Search;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class TestSearchKeywords extends Command
{
    protected $signature = 'search:test-keywords {--keyword= : Test single keyword}';

    protected $description = 'Test search functionality with competitor keyword list';

    protected $testKeywords = [
        'helmet' => 3000,
        'ATV parts' => 30000,
        'UTV parts' => 15000,
        'Dirt bike parts' => 20000,
        'Motorcycle parts' => 50000,
        'kawasaki windshield' => 50,
        'Honda brake pads' => 500,
        'ATV tires' => 4500,
        'Polaris UTV parts' => 5000,
        'Yamaha dirt bike' => 10000,
        'cheap motorcycle parts' => 5000,
        'OEM ATV parts' => 3000,
        'aftermarket UTV parts' => 2000,
        'performance parts' => 10000,
        'Honda TRX' => 5000,
        'Polaris RZR' => 3000
    ];

    public function handle()
    {
        if ($this->option('keyword')) {
            return $this->testSingleKeyword($this->option('keyword'));
        }

        $this->info('Testing search functionality with competitor keywords...');
        $this->newLine();

        $results = [];

        foreach ($this->testKeywords as $keyword => $expectedMin) {
            $count = $this->searchProducts($keyword);

            $status = $count >= $expectedMin ? 'PASS' : 'NEEDS IMPROVEMENT';
            $color = $count >= $expectedMin ? 'green' : 'yellow';

            $results[] = [
                'keyword' => $keyword,
                'count' => $count,
                'expected' => $expectedMin,
                'status' => $status
            ];

            $this->line(sprintf(
                "%-30s | Results: %-8s | Expected: %-8s | %s",
                $keyword,
                number_format($count),
                number_format($expectedMin) . '+',
                $status
            ));
        }

        $this->newLine();

        $passed = count(array_filter($results, fn($r) => $r['count'] >= $r['expected']));
        $total = count($results);

        $this->info("Tests passed: {$passed}/{$total}");

        return 0;
    }

    protected function testSingleKeyword($keyword)
    {
        $this->info("Testing keyword: {$keyword}");
        $this->newLine();

        $count = $this->searchProducts($keyword);

        $this->info("Total results: " . number_format($count));
        $this->newLine();

        $products = $this->getTopProducts($keyword, 10);

        $this->info("Top 10 results:");
        $this->newLine();

        $this->table(
            ['SKU', 'Product Name', 'Tag Matches'],
            $products->map(function($product) {
                return [
                    $product->sku,
                    substr($product->name, 0, 60),
                    $product->tag_matches ?? 0
                ];
            })
        );

        $tagBreakdown = $this->getTagBreakdown($keyword);

        if ($tagBreakdown->isNotEmpty()) {
            $this->newLine();
            $this->info("Tag matches breakdown:");
            $this->newLine();

            $this->table(
                ['Tag Type', 'Tag Value', 'Products'],
                $tagBreakdown->map(function($tag) {
                    return [
                        $tag->tag_type,
                        $tag->tag_value,
                        number_format($tag->product_count)
                    ];
                })
            );
        }

        return 0;
    }

    protected function searchProducts($keyword)
    {
        $request = new Request(['q' => $keyword]);

        $query = \App\Http\Controllers\Shop\SearchControllerEnhanced::buildTagBasedProductQuery($request);

        return $query->count();
    }

    protected function getTopProducts($keyword, $limit = 10)
    {
        $searchTerms = array_map('trim', explode(' ', strtolower($keyword)));

        $query = DB::table('product_flat as pf')
            ->join('products as p', 'pf.product_id', '=', 'p.id')
            ->leftJoin('product_search_tags as pst', 'p.id', '=', 'pst.product_id')
            ->select('p.id', 'p.sku', 'pf.name', DB::raw('COUNT(DISTINCT pst.id) as tag_matches'))
            ->where('pf.channel', 'maddparts')
            ->where('pf.locale', 'en')
            ->where('pf.status', 1)
            ->groupBy('p.id', 'p.sku', 'pf.name');

        $query->where(function($q) use ($searchTerms) {
            foreach ($searchTerms as $term) {
                $q->where(function($subQuery) use ($term) {
                    $subQuery->where('pf.name', 'like', "%{$term}%")
                            ->orWhere('pf.sku', 'like', "%{$term}%")
                            ->orWhere('pf.description', 'like', "%{$term}%")
                            ->orWhere('pst.tag_value', 'like', "%{$term}%");
                });
            }
        });

        return $query->orderBy('tag_matches', 'desc')
                    ->limit($limit)
                    ->get();
    }

    protected function getTagBreakdown($keyword)
    {
        $searchTerms = array_map('trim', explode(' ', strtolower($keyword)));

        $query = DB::table('product_search_tags as pst')
            ->select('pst.tag_type', 'pst.tag_value', DB::raw('COUNT(DISTINCT pst.product_id) as product_count'))
            ->groupBy('pst.tag_type', 'pst.tag_value');

        $query->where(function($q) use ($searchTerms) {
            foreach ($searchTerms as $term) {
                $q->orWhere('pst.tag_value', 'like', "%{$term}%");
            }
        });

        return $query->orderBy('product_count', 'desc')
                    ->limit(10)
                    ->get();
    }
}
