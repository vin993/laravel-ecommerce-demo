<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixInvalidBrandData extends Command
{
    protected $signature = 'fix:invalid-brands {--dry-run : Show what would be deleted without making changes}';
    protected $description = 'Remove invalid brand attribute values (like 342) that do not exist in manufacturer tables';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');

        $this->info('Finding products with invalid brand data...');

        // Get all products with brand attributes
        $allBrandProducts = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'pav.attribute_id', '=', 'a.id')
            ->join('products as p', 'pav.product_id', '=', 'p.id')
            ->where('a.admin_name', 'Brand')
            ->whereNotNull('pav.text_value')
            ->select('pav.id as attr_value_id', 'p.id as product_id', 'p.sku', 'pav.text_value as brand_value')
            ->get();

        $this->info("Checking {$allBrandProducts->count()} products with brand data...");

        // Filter to find invalid brands (those that don't exist in any manufacturer table)
        $invalidBrandProducts = $allBrandProducts->filter(function ($product) {
            $brandValue = $product->brand_value;

            // Check if brand exists in any of the manufacturer tables
            $existsInIndex = DB::table('ds_manufacturer_index')
                ->where('manufacturer_id', $brandValue)
                ->exists();

            if ($existsInIndex) {
                return false;
            }

            $existsInManufacturers = DB::table('ds_manufacturers')
                ->where('manufacturer_id', $brandValue)
                ->exists();

            if ($existsInManufacturers) {
                return false;
            }

            $existsInBrands = DB::table('ds_brands')
                ->where('brand_id', $brandValue)
                ->exists();

            // Return true if brand doesn't exist in any table (invalid)
            return !$existsInBrands;
        });

        $total = $invalidBrandProducts->count();

        if ($total === 0) {
            $this->info('No products with invalid brand data found!');
            return Command::SUCCESS;
        }

        // Group by brand value to show summary
        $brandGroups = $invalidBrandProducts->groupBy('brand_value');

        $this->warn("Found {$total} products with invalid brand IDs:");
        foreach ($brandGroups as $brandValue => $products) {
            $this->line("  - Brand ID '{$brandValue}': {$products->count()} products");
        }

        $this->newLine();
        $this->info("These brand attributes will be removed (hidden on product pages)");

        $deleted = 0;
        $errors = 0;

        foreach ($invalidBrandProducts as $product) {
            try {
                if ($isDryRun) {
                    $this->line("Would delete: Brand '{$product->brand_value}' for SKU {$product->sku} (ID: {$product->attr_value_id})");
                } else {
                    DB::table('product_attribute_values')
                        ->where('id', $product->attr_value_id)
                        ->delete();

                    $this->line("Deleted: Brand '{$product->brand_value}' for SKU {$product->sku}");
                }

                $deleted++;

            } catch (\Exception $e) {
                $this->error("Error processing SKU {$product->sku}: " . $e->getMessage());
                $errors++;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->table(['Metric', 'Count'], [
            ['Total Found', $total],
            ['Deleted', $deleted],
            ['Errors', $errors],
        ]);

        if ($isDryRun) {
            $this->warn('DRY RUN - No changes were made. Run without --dry-run to delete invalid brand attributes.');
        } else {
            $this->info('Invalid brand attributes have been removed successfully!');
            $this->info('Products will no longer show the Brand field in specifications.');
        }

        return Command::SUCCESS;
    }
}
