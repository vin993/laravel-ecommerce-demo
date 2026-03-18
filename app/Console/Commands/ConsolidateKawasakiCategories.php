<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Category\Models\Category;

class ConsolidateKawasakiCategories extends Command
{
    protected $signature = 'kawasaki:consolidate-categories {--dry-run : Show what would be consolidated without making changes}';
    protected $description = 'Consolidate redundant Kawasaki product categories using pattern matching';

    /**
     * Pattern-based consolidation rules
     * Format: 'primary_name' => ['pattern1', 'pattern2', ...]
     */
    protected $consolidationRules = [
        'Bags' => ['bags and coolers', 'bags and racks', 'bags & coolers', 'bags & racks'],
        'Hats' => ['hats & headwear', 'hats and headwear'],
        'T-Shirts' => ['t shirts', 'short sleeve shirts', 'all shirts'],
        'Long Sleeve T-Shirts' => ['long sleeve shirts'],
        'Seats & Backrests' => ['seats', 'racks and backrests'],
    ];

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        $this->info('Analyzing Kawasaki categories...');
        $this->newLine();

        // Get all Kawasaki categories
        $kawasakiCategories = $this->getKawasakiCategories();
        
        $consolidations = [];
        $processed = [];

        foreach ($this->consolidationRules as $primaryName => $patterns) {
            $primarySlug = Str::slug($primaryName);
            
            // Find the primary category or the one with most products
            $primaryCategory = null;
            $matchingCategories = [];

            foreach ($kawasakiCategories as $category) {
                $categoryNameLower = strtolower($category->name);
                $categorySlug = Str::slug($category->name);

                // Check if this matches the primary name
                if ($categorySlug === $primarySlug || $categoryNameLower === strtolower($primaryName)) {
                    $primaryCategory = $category;
                    continue;
                }

                // Check if this matches any pattern
                foreach ($patterns as $pattern) {
                    if ($categoryNameLower === $pattern || $categorySlug === Str::slug($pattern)) {
                        $matchingCategories[] = $category;
                        $processed[] = $category->id;
                        break;
                    }
                }
            }

            if (empty($matchingCategories)) {
                continue;
            }

            // If no primary category exists, use the one with most products
            if (!$primaryCategory) {
                $allCandidates = array_merge([$matchingCategories[0]], $matchingCategories);
                usort($allCandidates, function($a, $b) {
                    return $b->product_count <=> $a->product_count;
                });
                $primaryCategory = $allCandidates[0];
                $matchingCategories = array_filter($allCandidates, function($cat) use ($primaryCategory) {
                    return $cat->id !== $primaryCategory->id;
                });
            }

            if (!empty($matchingCategories)) {
                $consolidations[] = [
                    'primary' => $primaryCategory,
                    'merge' => $matchingCategories
                ];
            }
        }

        if (empty($consolidations)) {
            $this->info('No categories need consolidation.');
            return 0;
        }

        // Display consolidation plan
        $this->table(
            ['Primary Category', 'Products', 'Will Merge', 'Merge Products', 'Total After'],
            array_map(function($item) {
                $mergeNames = array_map(fn($c) => $c->name, $item['merge']);
                $mergeProducts = array_sum(array_map(fn($c) => $c->product_count, $item['merge']));
                $totalAfter = $item['primary']->product_count + $mergeProducts;
                
                return [
                    $item['primary']->name,
                    $item['primary']->product_count,
                    implode(', ', $mergeNames),
                    $mergeProducts,
                    $totalAfter
                ];
            }, $consolidations)
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('DRY RUN - No changes made. Remove --dry-run to apply consolidation.');
            return 0;
        }

        if (!$this->confirm('Proceed with consolidation?')) {
            $this->info('Consolidation cancelled.');
            return 0;
        }

        // Perform consolidation
        $this->newLine();
        $this->info('Consolidating categories...');

        DB::beginTransaction();
        try {
            foreach ($consolidations as $item) {
                $primaryId = $item['primary']->id;
                $mergeIds = array_map(fn($c) => $c->id, $item['merge']);

                // Get all products from merge categories
                $productsToMove = DB::table('product_categories')
                    ->whereIn('category_id', $mergeIds)
                    ->pluck('product_id')
                    ->unique();

                // Delete all associations from merge categories for these products
                DB::table('product_categories')
                    ->whereIn('category_id', $mergeIds)
                    ->whereIn('product_id', $productsToMove)
                    ->delete();

                // Insert new associations to primary category (ignore duplicates)
                $insertData = $productsToMove->map(function($productId) use ($primaryId) {
                    return [
                        'product_id' => $productId,
                        'category_id' => $primaryId
                    ];
                })->toArray();

                // Use insert ignore to skip products already in primary category
                foreach ($insertData as $data) {
                    DB::table('product_categories')->insertOrIgnore($data);
                }

                $this->info("✓ Moved {$productsToMove->count()} products to '{$item['primary']->name}'");

                // Delete redundant categories
                foreach ($mergeIds as $mergeId) {
                    DB::table('category_translations')->where('category_id', $mergeId)->delete();
                    DB::table('categories')->where('id', $mergeId)->delete();
                }

                $this->info("✓ Deleted " . count($mergeIds) . " redundant categories");
            }

            DB::commit();
            $this->newLine();
            $this->info('✅ Category consolidation completed successfully!');
            return 0;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Consolidation failed: ' . $e->getMessage());
            return 1;
        }
    }

    protected function getKawasakiCategories()
    {
        return DB::table('categories as c')
            ->join('category_translations as ct', 'c.id', '=', 'ct.category_id')
            ->join('product_categories as pc', 'c.id', '=', 'pc.category_id')
            ->join('product_flat as pf', 'pc.product_id', '=', 'pf.product_id')
            ->join('product_attribute_values as pav', function($join) {
                $join->on('pf.product_id', '=', 'pav.product_id')
                    ->where('pav.attribute_id', 25)
                    ->where('pav.text_value', 'Kawasaki');
            })
            ->where('ct.locale', 'en')
            ->select('c.id', 'c.parent_id', 'ct.name', DB::raw('COUNT(DISTINCT pf.product_id) as product_count'))
            ->groupBy('c.id', 'c.parent_id', 'ct.name')
            ->orderBy('ct.name')
            ->get();
    }

    /**
     * Get the consolidation mapping for a category name
     */
    public static function getConsolidatedName(string $categoryName): string
    {
        $instance = new self();
        $categoryNameLower = strtolower($categoryName);
        $categorySlug = Str::slug($categoryName);

        foreach ($instance->consolidationRules as $primaryName => $patterns) {
            $primarySlug = Str::slug($primaryName);
            
            // If it matches the primary name, return it
            if ($categorySlug === $primarySlug || $categoryNameLower === strtolower($primaryName)) {
                return $primaryName;
            }

            // If it matches a pattern, return the primary name
            foreach ($patterns as $pattern) {
                if ($categoryNameLower === $pattern || $categorySlug === Str::slug($pattern)) {
                    return $primaryName;
                }
            }
        }

        // No match found, return original name
        return $categoryName;
    }
}
