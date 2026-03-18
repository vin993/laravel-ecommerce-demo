<?php

namespace App\Console\Commands\VehicleFitment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncFitmentData extends Command
{
    protected $signature = 'vehicle:sync-fitment 
                            {--path=JonesboroCycleFull : Folder name in datastream/extracted}
                            {--skip-part-combo : Skip PartToAppCombo import}
                            {--skip-fitment : Skip Fitment import}
                            {--skip=0 : Skip N records (for resume)}
                            {--no-clear : Do not clear existing data}';

    protected $description = 'Import PartToAppCombo and Fitment data (WARNING: Large files)';

    public function handle()
    {
        $folder = $this->option('path');
        $basePath = storage_path("app/datastream/extracted/{$folder}");

        if (!File::exists($basePath)) {
            $this->error("Folder not found: {$basePath}");
            return 1;
        }

        $this->warn("This will import large datasets:");
        $this->warn("  - PartToAppCombo: ~341K records");
        $this->warn("  - Fitment: ~19M records");
        $this->warn("Estimated time: 2-3 hours");
        $this->line("");

        if (!$this->option('skip-part-combo')) {
            $this->syncPartToAppCombo($basePath);
        }

        if (!$this->option('skip-fitment')) {
            $this->syncFitment($basePath);
        }

        $this->info("✅ Fitment data sync completed!");
        return 0;
    }

    private function syncPartToAppCombo($basePath)
    {
        $file = "{$basePath}/PartToAppCombo.txt";
        if (!File::exists($file)) {
            $this->warn("PartToAppCombo.txt not found, skipping");
            return;
        }

        $this->info("Syncing PartToAppCombo...");
        $this->info("Clearing existing data...");
        DB::table('ds_part_to_app_combo')->delete();

        $this->info("Building part_id validation set from SKU index...");
        $validPartIds = DB::table('ds_sku_partmaster_index')->pluck('partmaster_id')->flip()->all();
        $this->info("Found " . count($validPartIds) . " valid partmaster IDs");

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $count = 0;
        $skipped = 0;
        $batch = [];
        $batchSize = 10000;

        while (($row = fgetcsv($handle)) !== false) {
            if (!isset($validPartIds[$row[1]])) {
                $skipped++;
                continue;
            }

            $batch[] = [
                'part_to_app_combo_id' => $row[0],
                'part_id' => $row[1],
                'application_combination_id' => $row[2],
                'update_flag' => $row[3] ?? 'I',
                'created_at' => now(),
                'updated_at' => now()
            ];

            if (count($batch) >= $batchSize) {
                DB::table('ds_part_to_app_combo')->insert($batch);
                $count += count($batch);
                $this->info("  Processed {$count} records (skipped {$skipped} orphaned)...");
                $batch = [];
                gc_collect_cycles();
            }
        }

        if (!empty($batch)) {
            DB::table('ds_part_to_app_combo')->insert($batch);
            $count += count($batch);
        }

        fclose($handle);
        $this->info("✅ Imported {$count} PartToAppCombo records (skipped {$skipped} orphaned)");
    }

    private function syncFitment($basePath)
    {
        $file = "{$basePath}/Fitment.txt";
        if (!File::exists($file)) {
            $this->warn("Fitment.txt not found, skipping");
            return;
        }

        $skipRecords = (int) $this->option('skip');
        $noClear = $this->option('no-clear');

        $this->info("Syncing Fitment (this will take 2-3 hours)...");
        
        if (!$noClear && $skipRecords == 0) {
            $this->info("Clearing existing data...");
            DB::table('ds_fitment')->delete();
        } elseif ($skipRecords > 0) {
            $this->warn("RESUME MODE: Skipping first {$skipRecords} records");
            $existing = DB::table('ds_fitment')->count();
            $this->info("Current database count: {$existing}");
        }

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $lineNumber = 0;
        while ($lineNumber < $skipRecords && fgetcsv($handle) !== false) {
            $lineNumber++;
        }
        
        if ($skipRecords > 0) {
            $this->info("Skipped to record {$skipRecords}");
        }
        
        $count = $skipRecords;
        $batch = [];
        $batchSize = 10000;
        $startTime = microtime(true);

        while (($row = fgetcsv($handle)) !== false) {
            $batch[] = [
                'fitment_id' => $row[0],
                'tmmy_id' => $row[1],
                'part_to_app_combo_id' => $row[2],
                'update_flag' => $row[3] ?? 'I',
                'created_at' => now(),
                'updated_at' => now()
            ];

            if (count($batch) >= $batchSize) {
                DB::table('ds_fitment')->insert($batch);
                $count += count($batch);
                
                $elapsed = microtime(true) - $startTime;
                $processed = $count - $skipRecords;
                $rate = $processed / $elapsed;
                $remaining = (19000000 - $count) / $rate;
                
                $this->info(sprintf(
                    "  Processed %s records (%.0f/sec, ~%.0f min remaining) [Resume: --skip=%d]...",
                    number_format($count),
                    $rate,
                    $remaining / 60,
                    $count
                ));
                
                $batch = [];
                gc_collect_cycles();
            }
        }

        if (!empty($batch)) {
            DB::table('ds_fitment')->insert($batch);
            $count += count($batch);
        }

        fclose($handle);
        
        $totalTime = (microtime(true) - $startTime) / 60;
        $this->info(sprintf("✅ Imported %s Fitment records in %.1f minutes", number_format($count), $totalTime));
    }
}
