<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ImportBrandLogos extends Command
{
    protected $signature = 'brands:import-logos
                            {file : Path to CSV file with brand logos (brand_name,logo_url)}
                            {--download : Download logos from URLs in CSV}';

    protected $description = 'Import brand logos from CSV file';

    public function handle()
    {
        $filePath = $this->argument('file');
        $shouldDownload = $this->option('download');

        if (!file_exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return 1;
        }

        $this->info("Reading CSV file: {$filePath}");
        $csvData = array_map('str_getcsv', file($filePath));
        $headers = array_shift($csvData);

        if (count($headers) < 2) {
            $this->error('CSV must have at least 2 columns: brand_name, logo_url');
            return 1;
        }

        $progressBar = $this->output->createProgressBar(count($csvData));
        $successCount = 0;
        $failCount = 0;

        foreach ($csvData as $row) {
            if (count($row) < 2) {
                $failCount++;
                $progressBar->advance();
                continue;
            }

            $brandName = trim($row[0]);
            $logoUrl = trim($row[1]);

            if (empty($brandName) || empty($logoUrl)) {
                $failCount++;
                $progressBar->advance();
                continue;
            }

            $brand = DB::table('ds_manufacturer_index')
                ->where('manufacturer_name', $brandName)
                ->first();

            if (!$brand) {
                $this->warn("  Brand not found: {$brandName}");
                $failCount++;
                $progressBar->advance();
                continue;
            }

            $logoPath = $logoUrl;

            if ($shouldDownload) {
                $downloadedPath = $this->downloadLogo($logoUrl, $brandName);
                if ($downloadedPath) {
                    $logoPath = $downloadedPath;
                } else {
                    $this->warn("  Failed to download logo for: {$brandName}");
                    $failCount++;
                    $progressBar->advance();
                    continue;
                }
            }

            DB::table('ds_manufacturer_index')
                ->where('manufacturer_id', $brand->manufacturer_id)
                ->update([
                    'logo_path' => $logoPath,
                    'logo_source' => 'manual_import'
                ]);

            $successCount++;
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Import completed! Success: {$successCount}, Failed: {$failCount}");
        return 0;
    }

    private function downloadLogo($url, $brandName)
    {
        try {
            $response = Http::timeout(10)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $contentType = $response->header('Content-Type');
            if (!$contentType || strpos($contentType, 'image') === false) {
                return null;
            }

            $extension = 'png';
            if (strpos($contentType, 'jpeg') !== false || strpos($contentType, 'jpg') !== false) {
                $extension = 'jpg';
            } elseif (strpos($contentType, 'svg') !== false) {
                $extension = 'svg';
            }

            $filename = Str::slug($brandName) . '-logo.' . $extension;
            $path = 'brands/logos/' . $filename;

            Storage::disk('public')->put($path, $response->body());

            return $path;

        } catch (\Exception $e) {
            return null;
        }
    }
}
