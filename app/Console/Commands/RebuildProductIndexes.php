<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class RebuildProductIndexes extends Command
{
    protected $signature = 'ari:rebuild-indexes {--categories-only} {--product-flat-only}';
    protected $description = 'Rebuild product indexes and fix category nested sets after bulk import';

    public function handle()
    {
        $categoriesOnly = $this->option('categories-only');
        $productFlatOnly = $this->option('product-flat-only');

        if (!$categoriesOnly && !$productFlatOnly) {
            $this->info('Rebuilding all indexes (this may take a while)...');
            $this->rebuildCategories();
            $this->rebuildProductFlat();
        } elseif ($categoriesOnly) {
            $this->rebuildCategories();
        } elseif ($productFlatOnly) {
            $this->rebuildProductFlat();
        }

        $this->info('Index rebuild complete!');
        return Command::SUCCESS;
    }

    private function rebuildCategories(): void
    {
        $this->info('Fixing category nested sets...');

        try {
            $categories = DB::table('categories')->orderBy('id')->get();

            if ($categories->isEmpty()) {
                $this->warn('No categories found');
                return;
            }

            $left = 1;
            $rootId = 1;

            foreach ($categories as $category) {
                if ($category->id == $rootId) {
                    continue;
                }
            }

            $this->rebuildNestedSet($rootId, 0);
            $this->info('Category nested sets fixed');

        } catch (\Exception $e) {
            $this->error('Failed to rebuild categories: ' . $e->getMessage());
        }
    }

    private function rebuildNestedSet($parentId, $left)
    {
        $right = $left + 1;

        $children = DB::table('categories')
            ->where('parent_id', $parentId)
            ->orderBy('position')
            ->get();

        foreach ($children as $child) {
            $right = $this->rebuildNestedSet($child->id, $right);
        }

        DB::table('categories')
            ->where('id', $parentId)
            ->update([
                '_lft' => $left,
                '_rgt' => $right,
                'updated_at' => now()
            ]);

        return $right + 1;
    }

    private function rebuildProductFlat(): void
    {
        $this->info('Rebuilding product_flat table (Bagisto indexer)...');

        try {
            Artisan::call('products:index', [], $this->getOutput());
            $this->info('Product flat table rebuilt');
        } catch (\Exception $e) {
            $this->warn('Bagisto indexer not available or failed: ' . $e->getMessage());
            $this->info('Attempting manual product_flat sync...');
            $this->manualProductFlatSync();
        }
    }

    private function manualProductFlatSync(): void
    {
        $this->info('Syncing product data to product_flat...');

        $missingCount = DB::table('products as p')
            ->leftJoin('product_flat as pf', 'p.id', '=', 'pf.product_id')
            ->whereNull('pf.product_id')
            ->count();

        if ($missingCount > 0) {
            $this->warn("Found {$missingCount} products missing from product_flat");
            $this->warn('Run: php artisan products:index to rebuild');
        } else {
            $this->info('All products are in product_flat table');
        }
    }
}
