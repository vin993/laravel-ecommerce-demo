<?php

namespace App\Console\Commands\WPS;

use App\Services\WPS\WpsBagistoService;
use App\Models\WPS\WpsProduct;
use App\Models\WPS\WpsProductItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CreateBagistoProducts extends Command
{
    protected $signature = 'wps:create-bagisto-products 
                            {--limit=100 : Number of products to process at once}
                            {--dry-run : Show what would be created without actually creating}
                            {--reset : Reset all Bagisto product associations}
                            {--stats : Show current sync statistics}';

    protected $description = 'Create Bagisto products from synced WPS data';

    protected $bagistoService;

    public function __construct(WpsBagistoService $bagistoService)
    {
        parent::__construct();
        $this->bagistoService = $bagistoService;
    }

    public function handle()
    {
        try {
            if ($this->option('stats')) {
                $this->showStats();
                return 0;
            }

            if ($this->option('reset')) {
                $this->resetBagistoAssociations();
                return 0;
            }

            if ($this->option('dry-run')) {
                $this->dryRun();
                return 0;
            }

            $this->createBagistoProducts();
            
        } catch (\Exception $e) {
            $this->error('Command failed: ' . $e->getMessage());
            Log::channel('wps')->error('Create Bagisto products command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }

    protected function createBagistoProducts()
    {
        $startTime = microtime(true);
        $limit = $this->option('limit');
        
        $this->info("🏭 Creating Bagisto products (limit: {$limit})...");
        $this->line('<comment>⏱️  Start Time: ' . now()->format('Y-m-d H:i:s') . '</comment>');
        $this->newLine();
        
        // Get count of pending products
        $pendingCount = WpsProduct::whereNull('bagisto_product_id')
            ->where('status', 'synced')
            ->count();
            
        $this->info("📊 Found {$pendingCount} WPS products ready for Bagisto creation");
        $this->newLine();

        $result = $this->bagistoService->createBagistoProducts($limit, $this->output);
        
        $totalTime = round(microtime(true) - $startTime, 2);
        $this->newLine();
        $this->info('🎉 Bagisto Product Creation Completed!');
        $this->line('<info>   • Products created: ' . $result['created'] . '</info>');
        if ($result['errors'] > 0) {
            $this->line('<error>   • Errors: ' . $result['errors'] . '</error>');
            $this->warn('⚠️  Check WPS logs for detailed error information');
        }
        $this->line('<comment>   • Total time: ' . $totalTime . 's</comment>');
        $this->line('<comment>⏱️  End Time: ' . now()->format('Y-m-d H:i:s') . '</comment>');
    }

    protected function dryRun()
    {
        $this->info('DRY RUN - No products will be created');
        
        $pendingProducts = WpsProduct::with('items')
            ->whereNull('bagisto_product_id')
            ->where('status', 'synced')
            ->limit($this->option('limit'))
            ->get();

        $this->table(
            ['WPS Product ID', 'Name', 'Items Count', 'Drop-ship Eligible Items'],
            $pendingProducts->map(function ($product) {
                return [
                    $product->wps_product_id,
                    substr($product->name, 0, 50) . '...',
                    $product->items->count(),
                    $product->items()->dropShipEligible()->count()
                ];
            })
        );

        $this->info("Total products to be created: {$pendingProducts->count()}");
    }

    protected function showStats()
    {
        $this->info('WPS Sync Statistics');
        $this->info('==================');
        
        // WPS Products Stats
        $totalWpsProducts = WpsProduct::count();
        $syncedWpsProducts = WpsProduct::where('status', 'synced')->count();
        $errorWpsProducts = WpsProduct::where('status', 'error')->count();
        $withBagistoProducts = WpsProduct::whereNotNull('bagisto_product_id')->count();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total WPS Products', $totalWpsProducts],
                ['Synced WPS Products', $syncedWpsProducts],
                ['Error WPS Products', $errorWpsProducts],
                ['With Bagisto Products', $withBagistoProducts],
                ['Pending Creation', $syncedWpsProducts - $withBagistoProducts],
            ]
        );

        // WPS Items Stats
        $totalWpsItems = WpsProductItem::count();
        $dropShipEligible = WpsProductItem::where('drop_ship_eligible', true)->count();
        $inStock = WpsProductItem::where('inventory_total', '>', 0)->count();
        $discontinued = WpsProductItem::where('status', 'NA')
            ->where('inventory_total', '>', 1)->count();
        
        $this->newLine();
        $this->table(
            ['Item Metric', 'Count'],
            [
                ['Total WPS Items', $totalWpsItems],
                ['Drop-ship Eligible', $dropShipEligible],
                ['In Stock', $inStock],
                ['Discontinued/Clearance', $discontinued],
            ]
        );

        // Recent sync info
        $lastSync = WpsProduct::orderBy('last_synced_at', 'desc')->first();
        if ($lastSync) {
            $this->newLine();
            $this->info("Last sync: {$lastSync->last_synced_at->diffForHumans()}");
        }
    }

    protected function resetBagistoAssociations()
    {
        if (!$this->confirm('This will reset all Bagisto product associations. Are you sure?')) {
            $this->info('Operation cancelled');
            return;
        }

        $count = WpsProduct::whereNotNull('bagisto_product_id')->count();
        
        WpsProduct::whereNotNull('bagisto_product_id')
            ->update(['bagisto_product_id' => null]);

        WpsProductItem::whereNotNull('bagisto_product_id')
            ->update(['bagisto_product_id' => null]);

        $this->info("Reset {$count} Bagisto product associations");
        $this->warn('Note: This does not delete the actual Bagisto products, only the associations');
    }
}