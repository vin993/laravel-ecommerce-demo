<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Category\Models\Category;
use Illuminate\Support\Facades\DB;

class UnlinkMenuCategoryProducts extends Command
{
    protected $signature = 'ari:unlink-menu-products';
    protected $description = 'Remove product links from menu categories to prevent memory issues';

    public function handle()
    {
        $this->info('Removing product links from menu categories...');

        $menuSlugs = [
            'accessories',
            'gear',
            'maintenance',
            'tires',
            'dirt-bike',
            'street',
            'atv',
            'utv',
            'watercraft'
        ];

        $locale = 'en';
        
        foreach ($menuSlugs as $slug) {
            $category = Category::whereHas('translations', function ($query) use ($slug, $locale) {
                $query->where('slug', $slug)->where('locale', $locale);
            })->first();

            if ($category) {
                $count = DB::table('product_categories')
                    ->where('category_id', $category->id)
                    ->count();

                if ($count > 0) {
                    DB::table('product_categories')
                        ->where('category_id', $category->id)
                        ->delete();

                    $this->info("  Removed {$count} product links from: " . strtoupper(str_replace('-', ' ', $slug)));
                } else {
                    $this->info("  No products linked to: " . strtoupper(str_replace('-', ' ', $slug)));
                }
            }
        }

        $this->info('Done! Menu categories are now virtual (no direct product links).');
        $this->info('Products will still appear through search and vehicle/category filters.');
        
        return 0;
    }
}
