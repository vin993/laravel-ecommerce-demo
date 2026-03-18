<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckVirtualInventory extends Command
{
    protected $signature = 'inventory:check-virtual
                            {--category= : Filter by category name}
                            {--limit=20 : Number of products to show}';

    protected $description = 'Check virtual inventory status';

    public function handle()
    {
        $categoryFilter = $this->option('category');
        $limit = (int) $this->option('limit');

        $this->info('Virtual Inventory Status Report');
        $this->newLine();

        $stats = DB::table('product_inventories')
            ->selectRaw('
                COUNT(*) as total_virtual,
                SUM(qty) as total_qty,
                AVG(qty) as avg_qty,
                MIN(last_replenished_at) as oldest_replenish,
                MAX(last_replenished_at) as newest_replenish
            ')
            ->where('virtual_inventory', true)
            ->first();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Virtual Products', number_format($stats->total_virtual)],
                ['Total Virtual Quantity', number_format($stats->total_qty)],
                ['Average Quantity', number_format($stats->avg_qty, 2)],
                ['Oldest Replenishment', $stats->oldest_replenish ?? 'N/A'],
                ['Newest Replenishment', $stats->newest_replenish ?? 'N/A'],
            ]
        );

        $this->newLine();

        $query = DB::table('product_inventories as pi')
            ->join('products as p', 'pi.product_id', '=', 'p.id')
            ->leftJoin('product_categories as pc', 'p.id', '=', 'pc.product_id')
            ->leftJoin('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
            ->where('pi.virtual_inventory', true);

        if ($categoryFilter) {
            $query->where('ct.name', 'LIKE', "%{$categoryFilter}%");
        }

        $products = $query->select(
            'p.sku',
            'pi.qty',
            'pi.virtual_qty_base',
            'pi.last_replenished_at',
            'ct.name as category'
        )
        ->orderBy('pi.qty', 'asc')
        ->limit($limit)
        ->get();

        if ($products->isEmpty()) {
            $this->info('No virtual inventory products found.');
            return 0;
        }

        $this->info("Showing {$products->count()} products with virtual inventory:");
        $this->newLine();

        $this->table(
            ['SKU', 'Current Qty', 'Base Qty', 'Last Replenished', 'Category'],
            $products->map(function($p) {
                return [
                    $p->sku,
                    $p->qty,
                    $p->virtual_qty_base,
                    $p->last_replenished_at ? \Carbon\Carbon::parse($p->last_replenished_at)->diffForHumans() : 'N/A',
                    $p->category ?? 'N/A'
                ];
            })
        );

        return 0;
    }
}
