<?php

namespace App\Console\Commands\VehicleFitment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncApplicationCombination extends Command
{
    protected $signature = 'vehicle:sync-app-combo 
                            {--path=JonesboroCycleFull : Folder name in datastream/extracted}';

    protected $description = 'Import ApplicationCombination from DataStream';

    public function handle()
    {
        $folder = $this->option('path');
        $basePath = storage_path("app/datastream/extracted/{$folder}");
        $file = "{$basePath}/ApplicationCombination.txt";

        if (!File::exists($file)) {
            $this->error("ApplicationCombination.txt not found at: {$file}");
            return 1;
        }

        $this->info("Syncing ApplicationCombination...");
        $this->info("Clearing existing data...");
        DB::table('ds_application_combinations')->delete();

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $count = 0;
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $batch[] = [
                'application_combination_id' => $row[0],
                'application1' => ($row[1] ?? 0) == 0 ? null : $row[1],
                'application2' => ($row[2] ?? 0) == 0 ? null : $row[2],
                'application3' => ($row[3] ?? 0) == 0 ? null : $row[3],
                'application4' => ($row[4] ?? 0) == 0 ? null : $row[4],
                'application5' => ($row[5] ?? 0) == 0 ? null : $row[5],
                'fitment_notes_id' => ($row[6] ?? 0) == 0 ? null : $row[6],
                'update_flag' => $row[7] ?? 'I',
                'created_at' => now(),
                'updated_at' => now()
            ];

            if (count($batch) >= 5000) {
                DB::table('ds_application_combinations')->insert($batch);
                $count += count($batch);
                $this->info("  Processed {$count} combinations...");
                $batch = [];
                gc_collect_cycles();
            }
        }

        if (!empty($batch)) {
            DB::table('ds_application_combinations')->insert($batch);
            $count += count($batch);
        }

        fclose($handle);
        $this->info("✅ Imported {$count} ApplicationCombination records");
        
        return 0;
    }
}
