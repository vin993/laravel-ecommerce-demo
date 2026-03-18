<?php

namespace App\Console\Commands\VehicleFitment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncApplications extends Command
{
    protected $signature = 'vehicle:sync-applications 
                            {--path=JonesboroCycleFull : Folder name in datastream/extracted}';

    protected $description = 'Import Applications from DataStream';

    public function handle()
    {
        $folder = $this->option('path');
        $basePath = storage_path("app/datastream/extracted/{$folder}");
        $file = "{$basePath}/Applications.txt";

        if (!File::exists($file)) {
            $this->error("Applications.txt not found at: {$file}");
            return 1;
        }

        $this->info("Syncing Applications...");
        
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            DB::table('ds_applications')->updateOrInsert(
                ['application_id' => $row[0]],
                [
                    'description' => $row[1] ?? '',
                    'update_flag' => $row[2] ?? 'I',
                    'updated_at' => now()
                ]
            );
            $count++;
        }

        fclose($handle);
        $this->info("✅ Imported {$count} Applications");
        
        return 0;
    }
}
