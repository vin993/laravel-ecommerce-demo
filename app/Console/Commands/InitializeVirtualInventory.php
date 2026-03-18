<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InitializeVirtualInventory extends Command
{
    protected $signature = 'inventory:initialize-virtual
                            {--dry-run : Show what would be updated without making changes}
                            {--category= : Specific category name to process}';

    protected $description = 'Initialize virtual inventory for products in configured categories';

    public function handle()
    {
        $isDryRun = $this->option('dry-run');
        $categoryFilter = $this->option('category');

        if (!config('virtual_inventory.enabled', true)) {
            $this->error('Virtual inventory is disabled in config. Set VIRTUAL_INVENTORY_ENABLED=true in .env');
            return 1;
        }

        $searchTerms = config('virtual_inventory.category_search_terms', []);
        $virtualQty = config('virtual_inventory.default_quantity', 10);

        if (empty($searchTerms)) {
            $this->error('No category search terms configured in config/virtual_inventory.php');
            return 1;
        }

        $this->info('Virtual Inventory Initialization');
        $this->info('Virtual Quantity: ' . $virtualQty);
        $this->info('Category Search Terms: ' . implode(', ', $searchTerms));
        $this->newLine();

        $query = DB::table('product_categories as pc')
            ->join('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
            ->join('products as p', 'pc.product_id', '=', 'p.id')
            ->join('product_inventories as pi', 'p.id', '=', 'pi.product_id')
            ->where('pi.qty', 0)
            ->where('pi.virtual_inventory', false);

        if ($categoryFilter) {
            $query->where('ct.name', 'LIKE', "%{$categoryFilter}%");
        } else {
            $query->where(function($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->orWhere('ct.name', 'LIKE', "%{$term}%");
                }
            });
        }

        $products = $query->select(
            'p.id as product_id',
            'p.sku',
            'ct.name as category_name',
            'pi.qty as current_qty'
        )->distinct()->get();

        if ($products->isEmpty()) {
            $this->info('No products found matching criteria.');
            return 0;
        }

        $this->info("Found {$products->count()} products to update");
        $this->newLine();

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $bar = $this->output->createProgressBar($products->count());
        $updated = 0;

        foreach ($products as $product) {
            if ($isDryRun) {
                $this->line("Would update: SKU {$product->sku} ({$product->category_name}) - set qty to {$virtualQty}");
            } else {
                DB::table('product_inventories')
                    ->where('product_id', $product->product_id)
                    ->update([
                        'qty' => $virtualQty,
                        'virtual_inventory' => true,
                        'virtual_qty_base' => $virtualQty,
                        'last_replenished_at' => now()
                    ]);
                $updated++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($isDryRun) {
            $this->info("Dry run complete. {$products->count()} products would be updated.");
        } else {
            $this->info("Successfully initialized virtual inventory for {$updated} products.");
        }

        return 0;
    }
}
