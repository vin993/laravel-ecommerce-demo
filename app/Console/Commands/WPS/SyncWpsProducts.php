<?php

namespace App\Console\Commands\WPS;

use App\Services\WPS\WpsProductSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncWpsProducts extends Command
{
    protected $signature = 'wps:sync-products 
                            {--inventory-only : Only sync inventory data}
                            {--product-id= : Sync specific product ID}
                            {--limit= : Limit number of products to sync}';

    protected $description = 'Sync products from WPS API to Bagisto';

    protected $syncService;

    public function __construct(WpsProductSyncService $syncService)
    {
        parent::__construct();
        $this->syncService = $syncService;
    }

    public function handle()
    {
        $this->info('Starting WPS Product Sync...');
        
        try {
            if ($this->option('inventory-only')) {
                $this->syncInventoryOnly();
            } elseif ($this->option('product-id')) {
                $this->syncSingleProduct();
            } else {
                $this->syncAllProducts();
            }
            
            $this->info('WPS sync completed successfully!');
            
        } catch (\Exception $e) {
            $this->error('WPS sync failed: ' . $e->getMessage());
            Log::channel('wps')->error('WPS sync command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }

    protected function syncAllProducts()
    {
        $this->info('🚀 Starting comprehensive WPS product sync...');
        $this->newLine();
        
        // Pass the command output to the service for detailed logging
        $result = $this->syncService->syncAllProducts($this->output);
        
        $this->newLine();
        $this->info('📊 Product Sync Summary:');
        $this->line("   • Products synced: {$result['products']}");
        $this->line("   • Items synced: {$result['items']}");
        $this->line("   • Items with dimensions: {$result['items_with_dimensions']}");
        $this->line("   • Pages processed: {$result['pages_processed']}");
        $this->line("   • Total time: {$result['total_time_seconds']}s");
        if ($result['errors'] > 0) {
            $this->error("   • Errors: {$result['errors']}");
        }
        
        $this->newLine();
        
        // Also sync inventory after products
        $this->info('🔄 Starting inventory sync...');
        $inventoryCount = $this->syncService->syncInventory();
        $this->info("✅ Inventory records updated: {$inventoryCount}");
    }

    protected function syncSingleProduct()
    {
        $productId = $this->option('product-id');
        $this->info("Syncing single product: {$productId}");
        
        // This would need to be implemented in the service
        $this->warn('Single product sync not yet implemented');
    }

    protected function syncInventoryOnly()
    {
        $this->info('Syncing inventory only...');
        
        $bar = $this->output->createProgressBar();
        $bar->setFormat('verbose');
        $bar->start();
        
        $count = $this->syncService->syncInventory();
        
        $bar->finish();
        $this->newLine();
        
        $this->info("Inventory records updated: {$count}");
    }
}