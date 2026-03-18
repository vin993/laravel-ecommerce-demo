<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ScrapeBrandLogos extends Command
{
    protected $signature = 'brands:scrape-logos
                            {--limit= : Number of brands to process (leave empty for all)}
                            {--brand= : Process specific brand by name}
                            {--all : Process all brands without logos}
                            {--retry-failed : Retry brands that previously failed}';

    protected $description = 'Attempt to scrape brand logos from various sources';

    private $sources = [
        'clearbit',
        'favicon',
        'logo-dev',
    ];

    public function handle()
    {
        $limit = $this->option('limit');
        $specificBrand = $this->option('brand');
        $processAll = $this->option('all');
        $retryFailed = $this->option('retry-failed');

        $query = DB::table('ds_manufacturer_index');

        if (!$retryFailed) {
            $query->whereNull('logo_path');
        }

        if ($specificBrand) {
            $query->where('manufacturer_name', $specificBrand);
        }

        if ($limit && !$processAll && !$specificBrand) {
            $brands = $query->limit($limit)->get();
        } else {
            $brands = $query->get();
        }

        if ($brands->isEmpty()) {
            $this->info('No brands found to process.');
            return 0;
        }

        $totalBrands = $brands->count();
        $this->info("Processing {$totalBrands} brands...");
        $this->info("This may take a while. Press Ctrl+C to stop.");

        if ($processAll || $totalBrands > 50) {
            if (!$this->confirm("You are about to process {$totalBrands} brands. Continue?", true)) {
                $this->info('Cancelled.');
                return 0;
            }
        }

        $progressBar = $this->output->createProgressBar($totalBrands);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - Success: %message%');

        $successCount = 0;
        $failCount = 0;

        foreach ($brands as $brand) {
            $result = $this->attemptLogoFetch($brand);

            if ($result) {
                $successCount++;
            } else {
                $failCount++;
            }

            $progressBar->setMessage($successCount);
            $progressBar->advance();

            usleep(500000);
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✓ Completed!");
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Processed', $totalBrands],
                ['Success', $successCount],
                ['Failed', $failCount],
                ['Success Rate', round(($successCount / $totalBrands) * 100, 2) . '%']
            ]
        );

        return 0;
    }

    private function attemptLogoFetch($brand)
    {
        $brandName = $brand->manufacturer_name;
        $possibleDomains = $this->generatePossibleDomains($brandName);

        foreach ($possibleDomains as $domain) {
            $logoUrl = $this->tryMultipleSources($domain);

            if ($logoUrl) {
                $savedPath = $this->downloadAndSaveLogo($logoUrl, $brandName);

                if ($savedPath) {
                    DB::table('ds_manufacturer_index')
                        ->where('manufacturer_id', $brand->manufacturer_id)
                        ->update([
                            'logo_path' => $savedPath,
                            'logo_source' => 'auto_scrape'
                        ]);

                    $this->line("  Found logo for: {$brandName} (domain: {$domain})");
                    return true;
                }
            }
        }

        return false;
    }

    private function tryMultipleSources($domain)
    {
        $sources = [
            "https://logo.clearbit.com/{$domain}",
            "https://www.google.com/s2/favicons?domain={$domain}&sz=128",
            "https://{$domain}/favicon.ico",
        ];

        foreach ($sources as $url) {
            try {
                $response = Http::timeout(5)->get($url);

                if ($response->successful()) {
                    $contentType = $response->header('Content-Type');
                    if ($contentType && strpos($contentType, 'image') !== false) {
                        $size = strlen($response->body());
                        if ($size > 500) {
                            return $url;
                        }
                    }
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    private function generatePossibleDomains($brandName)
    {
        $domains = [];
        $cleanName = strtolower(str_replace([' ', '-', '_', '.', ',', '&'], '', $brandName));

        $skipBrands = [
            'python',
            'oracle',
            'java',
            'swift',
            'delta',
            'titan',
            'apex',
            'fusion',
            'matrix',
            'vector',
        ];

        if (in_array($cleanName, $skipBrands)) {
            $domains[] = $cleanName . 'racing.com';
            $domains[] = $cleanName . 'performance.com';
            $domains[] = $cleanName . 'motorsports.com';
            $domains[] = $cleanName . 'automotive.com';
            return array_unique($domains);
        }

        $knownDomains = [
            'aem' => 'aemelectronics.com',
            'mishimoto' => 'mishimoto.com',
            'kn' => 'knfilters.com',
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
            'afepower' => 'afepower.com',
            'skyjacker' => 'skyjacker.com',
            'roughcountry' => 'roughcountry.com',
            'fabtech' => 'fabtechmotorsports.com',
            'procharger' => 'procharger.com',
            'vortech' => 'vortechsuperchargers.com',
            'paxton' => 'paxtonauto.com',
            'turbonetics' => 'turbonetics.com',
            'garrett' => 'garrettmotion.com',
            'borgwarner' => 'borgwarner.com',
            'precision' => 'precisionturbo.net',
            'turbosmart' => 'turbosmart.com',
            'tial' => 'tialsport.com',
            'hks' => 'hks-power.co.jp',
            'greddy' => 'greddy.com',
            'apexi' => 'apexi-usa.com',
            'blitz' => 'blitz-usa.com',
            'tomei' => 'tomei-p.co.jp',
            'skunk2' => 'skunk2.com',
            'protek' => 'protekproducts.com',
            'rsd' => 'rolandsands.com',
            'hinsonracing' => 'hinsonracing.com',
            'ratiorite' => 'goldeagle.com',
        ];

        if (isset($knownDomains[$cleanName])) {
            $domains[] = $knownDomains[$cleanName];
        }

        $domains[] = $cleanName . '.com';
        $domains[] = $cleanName . 'performance.com';
        $domains[] = $cleanName . 'racing.com';
        $domains[] = $cleanName . 'motorsports.com';

        return array_unique($domains);
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
            } elseif (strpos($contentType, 'x-icon') !== false) {
                $extension = 'ico';
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
