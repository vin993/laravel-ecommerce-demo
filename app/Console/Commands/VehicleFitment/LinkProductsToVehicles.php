<?php

namespace App\Console\Commands\VehicleFitment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkProductsToVehicles extends Command
{
    protected $signature = 'vehicle:link-products 
                            {--batch=5000 : Batch size for processing}
                            {--skip=0 : Skip N products}
                            {--limit=0 : Process only N products (0 = all)}
                            {--dry-run : Show stats without inserting}';

    protected $description = 'Link Bagisto products to vehicle fitment data';

    public function handle()
    {
        $batchSize = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info("Starting product-to-vehicle linking process...");
        
        if ($dryRun) {
            $this->warn("DRY RUN MODE - No data will be inserted");
        }

        $this->info("Analyzing data...");
        $stats = $this->analyzeData();
        
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products', number_format($stats['products'])],
                ['SKU Index', number_format($stats['sku_index'])],
                ['Part-to-App Combos', number_format($stats['part_combos'])],
                ['Fitment Records', number_format($stats['fitments'])],
                ['Vehicle Combinations', number_format($stats['vehicles'])],
            ]
        );

        if ($dryRun) {
            $this->info("Dry run completed. Use without --dry-run to process.");
            return 0;
        }

        $this->info("Clearing existing product_vehicle_fitment data...");
        DB::table('product_vehicle_fitment')->truncate();

        $startTime = microtime(true);
        $totalLinked = 0;

        $totalProducts = DB::table('products')->count();
        $productCount = $limit > 0 ? $limit : ($totalProducts - $skip);
        $this->info("Processing {$productCount} products (skipping {$skip})...");

        $query = DB::table('products as p')->select('p.id', 'p.sku');
        
        if ($skip > 0) {
            $query->offset($skip);
        }
        
        if ($limit > 0) {
            $query->limit($limit);
        } elseif ($skip > 0) {
            $query->limit($totalProducts - $skip);
        }

        $bar = $this->output->createProgressBar($productCount);
        $bar->start();

        $products = $query->get();
        $processed = 0;
        $batch = [];

        foreach ($products as $product) {
            $tmmy_ids = $this->getVehicleFitments($product->sku);
            
            foreach ($tmmy_ids as $tmmy_id) {
                $batch[] = [
                    'product_id' => $product->id,
                    'tmmy_id' => $tmmy_id,
                    'created_at' => now(),
                    'updated_at' => now()
                ];
            }

            if (count($batch) >= $batchSize) {
                DB::table('product_vehicle_fitment')->insert($batch);
                $totalLinked += count($batch);
                $batch = [];
                gc_collect_cycles();
            }

            $processed++;
            $bar->advance();

            if ($processed % 1000 == 0) {
                $elapsed = microtime(true) - $startTime;
                $rate = $processed / $elapsed;
                $remaining = ($productCount - $processed) / $rate;
                
                $this->newLine();
                $this->info(sprintf(
                    "  Processed %s products, created %s links (%.0f prod/sec, ~%.0f min remaining)",
                    number_format($processed),
                    number_format($totalLinked),
                    $rate,
                    $remaining / 60
                ));
                $bar->display();
            }
        }

        if (!empty($batch)) {
            DB::table('product_vehicle_fitment')->insert($batch);
            $totalLinked += count($batch);
        }

        $bar->finish();
        $this->newLine(2);

        $totalTime = (microtime(true) - $startTime) / 60;
        
        $this->info("✅ Linking completed!");
        $this->info("  Products processed: " . number_format($processed));
        $this->info("  Vehicle links created: " . number_format($totalLinked));
        $this->info("  Average links per product: " . number_format($totalLinked / max($processed, 1), 1));
        $this->info("  Total time: " . number_format($totalTime, 1) . " minutes");

        return 0;
    }

    private function analyzeData()
    {
        return [
            'products' => DB::table('products')->count(),
            'sku_index' => DB::table('ds_sku_partmaster_index')->count(),
            'part_combos' => DB::table('ds_part_to_app_combo')->count(),
            'fitments' => DB::table('ds_fitment')->count(),
            'vehicles' => DB::table('ds_type_make_model_year')->count(),
        ];
    }

    private function getVehicleFitments($sku)
    {
        return DB::table('ds_sku_partmaster_index as ski')
            ->join('ds_part_to_app_combo as pac', 'ski.partmaster_id', '=', 'pac.part_id')
            ->join('ds_fitment as f', 'pac.part_to_app_combo_id', '=', 'f.part_to_app_combo_id')
            ->where('ski.sku', $sku)
            ->pluck('f.tmmy_id')
            ->unique()
            ->values()
            ->toArray();
    }
}
