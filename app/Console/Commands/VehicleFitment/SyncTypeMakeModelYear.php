<?php

namespace App\Console\Commands\VehicleFitment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncTypeMakeModelYear extends Command
{
    protected $signature = 'vehicle:sync-tmmy 
                            {--path=JonesboroCycleFull : Folder name in datastream/extracted}';

    protected $description = 'Import TypeMakeModelYear combinations from DataStream';

    public function handle()
    {
        $folder = $this->option('path');
        $basePath = storage_path("app/datastream/extracted/{$folder}");
        $file = "{$basePath}/TypeMakeModelYear.txt";

        if (!File::exists($file)) {
            $this->error("TypeMakeModelYear.txt not found at: {$file}");
            return 1;
        }

        $this->info("Syncing TypeMakeModelYear combinations...");
        $this->info("Clearing existing data...");
        DB::table('ds_type_make_model_year')->delete();

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $count = 0;
        $batch = [];

        while (($row = fgetcsv($handle)) !== false) {
            $batch[] = [
                'tmmy_id' => $row[0],
                'vehicle_type_id' => $row[1] ?? 0,
                'make_id' => $row[2] ?? 0,
                'model_id' => $row[3] ?? 0,
                'year_id' => $row[4] ?? 0,
                'update_flag' => $row[5] ?? 'I',
                'created_at' => now(),
                'updated_at' => now()
            ];

            if (count($batch) >= 5000) {
                DB::table('ds_type_make_model_year')->insert($batch);
                $count += count($batch);
                $this->info("  Processed {$count} combinations...");
                $batch = [];
                gc_collect_cycles();
            }
        }

        if (!empty($batch)) {
            DB::table('ds_type_make_model_year')->insert($batch);
            $count += count($batch);
        }

        fclose($handle);
        $this->info("✅ Imported {$count} TypeMakeModelYear combinations");
        
        return 0;
    }
}
