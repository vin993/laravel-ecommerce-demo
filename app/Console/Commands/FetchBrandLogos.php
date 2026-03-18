<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class FetchBrandLogos extends Command
{
    protected $signature = 'brands:fetch-logos
                            {--limit=50 : Number of brands to process per run}
                            {--skip-existing : Skip brands that already have logos}
                            {--brand= : Process specific brand by name}';

    protected $description = 'Fetch and save brand logos automatically';

    private $logoApis = [
        'clearbit' => 'https://logo.clearbit.com/',
        'brandfetch' => 'https://api.brandfetch.io/v2/brands/',
    ];

    public function handle()
    {
        $limit = $this->option('limit');
        $skipExisting = $this->option('skip-existing');
        $specificBrand = $this->option('brand');

        $query = DB::table('ds_manufacturer_index');

        if ($skipExisting) {
            $query->whereNull('logo_path');
        }

        if ($specificBrand) {
            $query->where('manufacturer_name', $specificBrand);
        }

        $brands = $query->limit($limit)->get();

        if ($brands->isEmpty()) {
            $this->info('No brands found to process.');
            return 0;
        }

        $this->info("Processing {$brands->count()} brands...");
        $progressBar = $this->output->createProgressBar($brands->count());

        $successCount = 0;
        $failCount = 0;

        foreach ($brands as $brand) {
            $result = $this->fetchAndSaveLogo($brand);

            if ($result) {
                $successCount++;
            } else {
                $failCount++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Completed! Success: {$successCount}, Failed: {$failCount}");
        return 0;
    }

    private function fetchAndSaveLogo($brand)
    {
        $brandName = $brand->manufacturer_name;
        $domain = $this->guessBrandDomain($brandName);

        $logoUrl = null;
        $source = null;

        $logoUrl = $this->tryFetchFromClearbit($domain);
        if ($logoUrl) {
            $source = 'clearbit';
        }

        if (!$logoUrl) {
            $logoUrl = $this->tryFetchFromGoogle($brandName);
            if ($logoUrl) {
                $source = 'google';
            }
        }

        if (!$logoUrl) {
            $this->warn("  Failed to fetch logo for: {$brandName}");
            return false;
        }

        $savedPath = $this->downloadAndSaveLogo($logoUrl, $brandName);

        if ($savedPath) {
            DB::table('ds_manufacturer_index')
                ->where('manufacturer_id', $brand->manufacturer_id)
                ->update([
                    'logo_path' => $savedPath,
                    'logo_source' => $source
                ]);

            $this->line("  Saved logo for: {$brandName}");
            return true;
        }

        return false;
    }

    private function tryFetchFromClearbit($domain)
    {
        try {
            $url = $this->logoApis['clearbit'] . $domain;
            $response = Http::timeout(5)->get($url);

            if ($response->successful() && $response->header('Content-Type') && strpos($response->header('Content-Type'), 'image') !== false) {
                return $url;
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        return null;
    }

    private function tryFetchFromGoogle($brandName)
    {
        return null;
    }

    private function guessBrandDomain($brandName)
    {
        $cleanName = strtolower(str_replace([' ', '-', '_', '.', ','], '', $brandName));

        $commonDomains = [
            'aem' => 'aemelectronics.com',
            'mishimoto' => 'mishimoto.com',
            'kn' => 'knfilters.com',
            'k&n' => 'knfilters.com',
            'magnaflow' => 'magnaflow.com',
            'borla' => 'borla.com',
            'flowmaster' => 'flowmaster.com',
            'edelbrock' => 'edelbrock.com',
            'holley' => 'holley.com',
            'msd' => 'msdperformance.com',
            'acdelco' => 'acdelco.com',
            'bosch' => 'bosch.com',
            'denso' => 'denso.com',
            'ngk' => 'ngk.com',
            'champion' => 'championautoparts.com',
            'moog' => 'moogparts.com',
            'bilstein' => 'bilstein.com',
            'kyb' => 'kyb.com',
            'monroe' => 'monroe.com',
            'eibach' => 'eibach.com',
            'brembo' => 'brembo.com',
            'stoptech' => 'stoptech.com',
            'ebc' => 'ebcbrakes.com',
            'hawk' => 'hawkperformance.com',
            'wilwood' => 'wilwood.com',
            'spectre' => 'spectreperformance.com',
            'airaid' => 'airaid.com',
            'afe' => 'afepower.com',
            'skyjacker' => 'skyjacker.com',
            'rough country' => 'roughcountry.com',
            'roughcountry' => 'roughcountry.com',
            'fabtech' => 'fabtechmotorsports.com',
        ];

        if (isset($commonDomains[$cleanName])) {
            return $commonDomains[$cleanName];
        }

        return $cleanName . '.com';
    }

    private function downloadAndSaveLogo($url, $brandName)
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
