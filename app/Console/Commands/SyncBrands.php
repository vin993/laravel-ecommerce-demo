<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Product\Models\ProductAttributeValue;

class SyncBrands extends Command
{
    protected $signature = 'ari:sync-brands {--dry-run : Run without making changes}';
    
    protected $description = 'Sync brands from ds_manufacturer_index to Bagisto brand attribute';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting brand sync from ds_manufacturer_index...');

        // Get brand attribute
        $brandAttribute = DB::table('attributes')->where('code', 'brand')->first();
        if (!$brandAttribute) {
            $this->error('Brand attribute not found');
            return;
        }

        // Get all manufacturers from ds_manufacturer_index
        $manufacturers = DB::table('ds_manufacturer_index')
            ->whereNotNull('manufacturer_name')
            ->where('manufacturer_name', '!=', '')
            ->orderBy('manufacturer_name')
            ->get();

        $this->info("Found {$manufacturers->count()} manufacturers");

        // Get existing brand options
        $existingBrands = DB::table('attribute_options')
            ->where('attribute_id', $brandAttribute->id)
            ->pluck('admin_name')
            ->map('strtolower')
            ->toArray();

        $newBrands = 0;
        $skipped = 0;

        foreach ($manufacturers as $manufacturer) {
            $brandName = trim($manufacturer->manufacturer_name);
            
            if (empty($brandName)) {
                continue;
            }

            // Check if brand already exists
            if (in_array(strtolower($brandName), $existingBrands)) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                // Create new brand option
                $attributeOption = new AttributeOption();
                $attributeOption->attribute_id = $brandAttribute->id;
                $attributeOption->admin_name = $brandName;
                $attributeOption->sort_order = 0;
                $attributeOption->save();

                // Create translations
                DB::table('attribute_option_translations')->insert([
                    'attribute_option_id' => $attributeOption->id,
                    'locale' => 'en',
                    'label' => $brandName
                ]);
            }

            $newBrands++;
            $this->line("Added: {$brandName}");
        }

        $this->info("Brand sync complete:");
        $this->info("- New brands: {$newBrands}");
        $this->info("- Skipped (existing): {$skipped}");

        if (!$dryRun && $newBrands > 0) {
            $this->info('Now run: php artisan ari:assign-product-brands');
        }
    }
}