<?php

namespace App\Console\Commands\WPS;

use App\Services\WPS\WpsInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWpsInventory extends Command
{
    protected $signature = 'wps:sync-inventory 
                            {--from-api : Fetch fresh inventory from WPS API}
                            {--to-bagisto : Sync existing WPS inventory to Bagisto}
                            {--limit=1000 : Limit for to-bagisto sync}
                            {--stats : Show inventory statistics}';

    protected $description = 'Sync inventory between WPS API, WPS tracking, and Bagisto';

    protected $inventoryService;

    public function __construct(WpsInventoryService $inventoryService)
    {
        parent::__construct();
        $this->inventoryService = $inventoryService;
    }

    public function handle()
    {
        try {
            if ($this->option('stats')) {
                $this->showStats();
                return 0;
            }

            if ($this->option('from-api')) {
                $this->syncFromApi();
                return 0;
            }

            if ($this->option('to-bagisto')) {
                $this->syncToBagisto();
                return 0;
            }

            $this->info('Please specify an option:');
            $this->info('  --from-api     : Fetch fresh inventory from WPS API');
            $this->info('  --to-bagisto   : Sync existing WPS inventory to Bagisto');
            $this->info('  --stats        : Show inventory statistics');
            
        } catch (\Exception $e) {
            $this->error('Command failed: ' . $e->getMessage());
            Log::channel('wps')->error('Sync inventory command failed', [
                'error' => $e->getMessage()
            ]);
            return 1;
        }
        
        return 0;
    }

    protected function syncFromApi()
    {
        $this->info('Syncing inventory from WPS API...');
        $this->warn('This may take several minutes for large inventories');
        
        $result = $this->inventoryService->syncInventoryFromApi();
        
        $this->info("WPS items updated: {$result['wps_updated']}");
        $this->info("Bagisto products updated: {$result['bagisto_updated']}");
    }

    protected function syncToBagisto()
    {
        $limit = $this->option('limit');
        $this->info("Syncing WPS inventory to Bagisto (limit: {$limit})...");
        
        $result = $this->inventoryService->syncInventoryToBagisto($limit);
        
        $this->info("Bagisto inventories updated: {$result['updated']}");
        $this->error("Errors: {$result['errors']}");
    }

    protected function showStats()
    {
        $this->info('WPS Inventory Statistics');
        $this->info('========================');
        
        $stats = $this->inventoryService->getInventoryStats();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['WPS Items In Stock', $stats['wps_items_in_stock']],
                ['WPS Items Out of Stock', $stats['wps_items_out_of_stock']],
                ['Bagisto Inventory Records', $stats['bagisto_inventory_records']],
            ]
        );
    }
}