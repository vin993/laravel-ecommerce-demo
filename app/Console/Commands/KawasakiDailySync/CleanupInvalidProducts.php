<?php

namespace App\Console\Commands\KawasakiDailySync;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupInvalidProducts extends Command
{
    protected $signature = 'kawasaki:cleanup-invalid-products 
                            {--dry-run : Preview deletions without actually deleting}
                            {--limit= : Limit number of products to process}';

    protected $description = 'Remove invalid Kawasaki products (OEM parts and excluded status items)';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $limit = $this->option('limit');
        
        $this->info('Starting Kawasaki product cleanup...');
        $this->info($dryRun ? '🔍 DRY RUN MODE - No deletions will occur' : '⚠️  LIVE MODE - Products will be deleted');
        $this->newLine();
        
        // Step 1: Find products to delete from snapshots
        $this->info('Step 1: Identifying products to remove...');
        
        $query = DB::table('kawasaki_product_snapshots');
        
        // Get products with excluded status flags or OEM parts
        $excludedStatuses = ['1', '2', '4', '8'];
        $skusToDelete = $query->where(function($q) use ($excludedStatuses) {
            $q->whereRaw("JSON_EXTRACT(last_xml_data, '$.status_flag') IN ('" . implode("','", $excludedStatuses) . "')")
              ->orWhereRaw("JSON_EXTRACT(last_xml_data, '$.inventory_type') IN ('A', 'C')");
        });
        
        if ($limit) {
            $skusToDelete->limit($limit);
        }
        
        $skusToDelete = $skusToDelete->pluck('sku')->toArray();
        
        $totalCount = count($skusToDelete);
        $this->info("Found {$totalCount} products to remove");
        
        if ($totalCount === 0) {
            $this->info('✅ No products to clean up!');
            return 0;
        }
        
        // Show breakdown by reason
        $this->newLine();
        $this->info('Breakdown by exclusion reason:');
        
        // Process in chunks to avoid SQL placeholder limit
        $statusFlagCount = 0;
        $oemPartsCount = 0;
        
        foreach (array_chunk($skusToDelete, 1000) as $chunk) {
            $statusFlagCount += DB::table('kawasaki_product_snapshots')
                ->whereIn('sku', $chunk)
                ->whereRaw("JSON_EXTRACT(last_xml_data, '$.status_flag') IN ('" . implode("','", $excludedStatuses) . "')")
                ->count();
                
            $oemPartsCount += DB::table('kawasaki_product_snapshots')
                ->whereIn('sku', $chunk)
                ->whereRaw("JSON_EXTRACT(last_xml_data, '$.inventory_type') = 'A'")
                ->count();
        }
        
        $this->table(
            ['Reason', 'Count'],
            [
                ['Invalid Status (1,2,4,8)', $statusFlagCount],
                ['OEM Parts (Type A)', $oemPartsCount],
                ['TOTAL', $totalCount],
            ]
        );
        
        if ($dryRun) {
            $this->newLine();
            $this->info('📋 Sample products to be deleted:');
            $sampleSkus = array_slice($skusToDelete, 0, 10);
            foreach ($sampleSkus as $sku) {
                $snapshot = DB::table('kawasaki_product_snapshots')->where('sku', $sku)->first();
                $data = json_decode($snapshot->last_xml_data, true);
                $inventoryType = $data['inventory_type'] ?? 'N/A';
                $this->line("  - {$sku}: {$data['name']} (Status: {$data['status_flag']}, Type: {$inventoryType})");
            }
            if ($totalCount > 10) {
                $this->line("  ... and " . ($totalCount - 10) . " more");
            }
            
            $this->newLine();
            $this->warn('This is a DRY RUN. No deletions were performed.');
            $this->info('Run without --dry-run to actually delete these products.');
            return 0;
        }
        
        // Confirm deletion
        $this->newLine();
        if (!$this->confirm("Are you sure you want to delete {$totalCount} products?", false)) {
            $this->info('Cleanup cancelled.');
            return 0;
        }
        
        // Step 2: Delete from products table
        $this->newLine();
        $this->info('Step 2: Deleting products from database...');
        
        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();
        
        $deletedCount = 0;
        $chunks = array_chunk($skusToDelete, 100);
        
        foreach ($chunks as $chunk) {
            // Get product IDs
            $productIds = DB::table('products')
                ->whereIn('sku', $chunk)
                ->pluck('id')
                ->toArray();
            
            if (empty($productIds)) {
                $bar->advance(count($chunk));
                continue;
            }
            
            DB::beginTransaction();
            try {
                // Delete related data
                DB::table('product_images')->whereIn('product_id', $productIds)->delete();
                DB::table('product_inventories')->whereIn('product_id', $productIds)->delete();
                DB::table('product_flat')->whereIn('product_id', $productIds)->delete();
                DB::table('product_categories')->whereIn('product_id', $productIds)->delete();
                DB::table('product_attribute_values')->whereIn('product_id', $productIds)->delete();
                
                // Delete products
                DB::table('products')->whereIn('id', $productIds)->delete();
                
                // Delete snapshots
                DB::table('kawasaki_product_snapshots')->whereIn('sku', $chunk)->delete();
                
                DB::commit();
                $deletedCount += count($productIds);
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("\nError deleting products: " . $e->getMessage());
            }
            
            $bar->advance(count($chunk));
        }
        
        $bar->finish();
        $this->newLine(2);
        
        // Summary
        $this->info("✅ Cleanup completed!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products identified', $totalCount],
                ['Products deleted', $deletedCount],
                ['Products remaining', $totalCount - $deletedCount],
            ]
        );
        
        return 0;
    }
}
