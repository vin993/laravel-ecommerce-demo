<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExportBrandsList extends Command
{
    protected $signature = 'brands:export-list
                            {--output= : Output file path (default: storage/app/brands_to_import.csv)}
                            {--missing-only : Export only brands without logos}';

    protected $description = 'Export list of brands to CSV for logo import preparation';

    public function handle()
    {
        $outputPath = $this->option('output') ?: storage_path('app/brands_to_import.csv');
        $missingOnly = $this->option('missing-only');

        $query = DB::table('ds_manufacturer_index')
            ->select('manufacturer_id', 'manufacturer_name', 'logo_path', 'logo_source')
            ->orderBy('manufacturer_name', 'asc');

        if ($missingOnly) {
            $query->whereNull('logo_path');
        }

        $brands = $query->get();

        if ($brands->isEmpty()) {
            $this->info('No brands found to export.');
            return 0;
        }

        $fp = fopen($outputPath, 'w');

        fputcsv($fp, ['brand_name', 'logo_url', 'current_logo_path', 'current_logo_source']);

        foreach ($brands as $brand) {
            fputcsv($fp, [
                $brand->manufacturer_name,
                '',
                $brand->logo_path ?? '',
                $brand->logo_source ?? ''
            ]);
        }

        fclose($fp);

        $this->info("Exported {$brands->count()} brands to: {$outputPath}");
        $this->line("Edit the 'logo_url' column and import using:");
        $this->line("  sudo -u www-data php artisan brands:import-logos {$outputPath} --download");

        return 0;
    }
}
