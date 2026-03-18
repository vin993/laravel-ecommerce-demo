<?php

namespace App\Console\Commands\VehicleFitment;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class SyncVehicleLookupTables extends Command
{
    protected $signature = 'vehicle:sync-lookup-tables 
                            {--path=JonesboroCycleFull : Folder name in datastream/extracted}';

    protected $description = 'Import VehicleTypes, Makes, Models, Years from DataStream';

    private $basePath;

    public function handle()
    {
        $folder = $this->option('path');
        $this->basePath = storage_path("app/datastream/extracted/{$folder}");

        if (!File::exists($this->basePath)) {
            $this->error("Folder not found: {$this->basePath}");
            return 1;
        }

        $this->info("Starting vehicle lookup tables sync from: {$folder}");

        $this->syncVehicleTypes();
        $this->syncMakes();
        $this->syncModels();
        $this->syncYears();

        $this->info("Vehicle lookup tables sync completed!");
        return 0;
    }

    private function syncVehicleTypes()
    {
        $file = "{$this->basePath}/VehicleTypes.txt";
        if (!File::exists($file)) {
            $this->warn("VehicleTypes.txt not found, skipping");
            return;
        }

        $this->info("Syncing VehicleTypes...");
        DB::table('ds_vehicle_types')->delete();

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $batch = [];
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            DB::table('ds_vehicle_types')->updateOrInsert(
                ['vehicle_type_id' => $row[0]],
                [
                    'description' => $row[1] ?? '',
                    'update_flag' => $row[2] ?? 'I',
                    'updated_at' => now()
                ]
            );
            $count++;
        }

        fclose($handle);
        $this->info("  Imported {$count} vehicle types");
    }

    private function syncMakes()
    {
        $file = "{$this->basePath}/Makes.txt";
        if (!File::exists($file)) {
            $this->warn("Makes.txt not found, skipping");
            return;
        }

        $this->info("Syncing Makes...");
        DB::table('ds_makes')->delete();

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $batch = [];
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            DB::table('ds_makes')->updateOrInsert(
                ['make_id' => $row[0]],
                [
                    'description' => $row[1] ?? '',
                    'update_flag' => $row[2] ?? 'I',
                    'updated_at' => now()
                ]
            );
            $count++;
        }

        fclose($handle);
        $this->info("  Imported {$count} makes");
    }

    private function syncModels()
    {
        $file = "{$this->basePath}/Models.txt";
        if (!File::exists($file)) {
            $this->warn("Models.txt not found, skipping");
            return;
        }

        $this->info("Syncing Models...");
        DB::table('ds_models')->delete();

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $batch = [];
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            DB::table('ds_models')->updateOrInsert(
                ['model_id' => $row[0]],
                [
                    'description' => $row[1] ?? '',
                    'update_flag' => $row[2] ?? 'I',
                    'updated_at' => now()
                ]
            );
            $count++;
        }

        fclose($handle);
        $this->info("  Imported {$count} models");
    }

    private function syncYears()
    {
        $file = "{$this->basePath}/Years.txt";
        if (!File::exists($file)) {
            $this->warn("Years.txt not found, skipping");
            return;
        }

        $this->info("Syncing Years...");
        DB::table('ds_years')->delete();

        $handle = fopen($file, 'r');
        $header = fgetcsv($handle);
        
        $batch = [];
        $count = 0;

        while (($row = fgetcsv($handle)) !== false) {
            DB::table('ds_years')->updateOrInsert(
                ['year_id' => $row[0]],
                [
                    'description' => $row[1] ?? '',
                    'update_flag' => $row[2] ?? 'I',
                    'updated_at' => now()
                ]
            );
            $count++;
        }

        fclose($handle);
        $this->info("  Imported {$count} years");
    }
}
