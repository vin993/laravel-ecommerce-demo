<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BuildDataStreamIndexes extends Command
{
    protected $signature = 'datastream:build-indexes {--force}';
    protected $description = 'Build indexed lookup tables from DataStream files';

    private $basePath;

    public function handle()
    {
        $this->basePath = $this->detectLatestExtractedPath();
        if (!$this->basePath) {
            $this->error('No extracted data folders found');
            return Command::FAILURE;
        }

        $this->info("Building indexes from: {$this->basePath}");

        if (!$this->option('force')) {
            $existingCount = DB::table('ds_price_index')->count();
            if ($existingCount > 0) {
                if (!$this->confirm("Index tables already have {$existingCount} records. Rebuild?")) {
                    return Command::SUCCESS;
                }
                $this->info('Truncating existing indexes...');
                DB::table('ds_price_index')->truncate();
                DB::table('ds_image_index')->truncate();
                DB::table('ds_attribute_index')->truncate();
                DB::table('ds_manufacturer_index')->truncate();
                DB::table('ds_kit_index')->truncate();
            }
        }

        $this->info('Building indexes...');

        $this->buildKitIndex();
        $this->buildManufacturerIndex();
        $this->buildPriceIndex();
        $this->buildImageIndex();
        $this->buildAttributeIndex();

        $this->info('Index build complete!');
        $this->displayStats();

        return Command::SUCCESS;
    }

    private function buildKitIndex(): void
    {
        $file = $this->basePath . '/Kits.txt';
        if (!File::exists($file)) {
            $this->warn('Kits.txt not found');
            return;
        }

        $this->info('Building kit index...');
        $batch = [];
        $lines = File::lines($file);
        $header = null;
        $count = 0;
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['Primary_PartmasterID'])) {
                $primaryId = trim($row['Primary_PartmasterID'], '"');
                if ($primaryId && !isset($seen[$primaryId])) {
                    $batch[] = ['primary_partmaster_id' => $primaryId];
                    $seen[$primaryId] = true;
                    $count++;

                    if (count($batch) >= 1000) {
                        DB::table('ds_kit_index')->insert($batch);
                        $batch = [];
                    }
                }
            }
        }

        if (!empty($batch)) {
            DB::table('ds_kit_index')->insert($batch);
        }

        $this->line("  Kit index: {$count} unique records");
    }

    private function buildManufacturerIndex(): void
    {
        $file = $this->basePath . '/Manufacturer.txt';
        if (!File::exists($file)) {
            $this->warn('Manufacturer.txt not found');
            return;
        }

        $this->info('Building manufacturer index...');
        $batch = [];
        $lines = File::lines($file);
        $header = null;
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['id'], $row['ManufacturerName'])) {
                $batch[] = [
                    'manufacturer_id' => trim($row['id'], '"'),
                    'manufacturer_name' => trim($row['ManufacturerName'], '"'),
                ];
                $count++;

                if (count($batch) >= 1000) {
                    DB::table('ds_manufacturer_index')->insert($batch);
                    $batch = [];
                }
            }
        }

        if (!empty($batch)) {
            DB::table('ds_manufacturer_index')->insert($batch);
        }

        $this->line("  Manufacturer index: {$count} records");
    }

    private function buildPriceIndex(): void
    {
        $file = $this->basePath . '/PartPriceInv.txt';
        if (!File::exists($file)) {
            $this->warn('PartPriceInv.txt not found');
            return;
        }

        $this->info('Building price index (may take 5-10 minutes)...');
        $lines = File::lines($file);
        $header = null;
        $priceMap = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID'])) {
                $partId = trim($row['PartmasterID'], '"');
                $msrp = !empty($row['MSRP']) ? (float) trim($row['MSRP'], '"') : 0;
                $stdPrice = !empty($row['StandardPrice']) ? (float) trim($row['StandardPrice'], '"') : 0;
                $bestPrice = !empty($row['BestPrice']) ? (float) trim($row['BestPrice'], '"') : 0;
                $qty = !empty($row['DistributorQty']) ? (int) trim($row['DistributorQty'], '"') : 0;

                if (!isset($priceMap[$partId])) {
                    $priceMap[$partId] = [
                        'msrp' => $msrp,
                        'standard_price' => $stdPrice,
                        'best_price' => $bestPrice,
                        'quantity' => $qty,
                    ];
                } else {
                    if ($msrp > $priceMap[$partId]['msrp']) {
                        $priceMap[$partId]['msrp'] = $msrp;
                    }
                    if ($stdPrice > $priceMap[$partId]['standard_price']) {
                        $priceMap[$partId]['standard_price'] = $stdPrice;
                    }
                    if ($bestPrice > $priceMap[$partId]['best_price']) {
                        $priceMap[$partId]['best_price'] = $bestPrice;
                    }
                    $priceMap[$partId]['quantity'] += $qty;
                }
            }
        }

        $this->info('Inserting aggregated price data...');
        $batch = [];
        $count = 0;

        foreach ($priceMap as $partId => $prices) {
            $batch[] = array_merge(['partmaster_id' => $partId], $prices);
            $count++;

            if (count($batch) >= 5000) {
                DB::table('ds_price_index')->insert($batch);
                $this->line("  Inserted {$count} unique price records...");
                $batch = [];
            }
        }

        if (!empty($batch)) {
            DB::table('ds_price_index')->insert($batch);
        }

        $this->line("  Price index: {$count} unique records");
    }

    private function buildImageIndex(): void
    {
        $file = $this->basePath . '/Images.txt';
        if (!File::exists($file)) {
            $this->warn('Images.txt not found');
            return;
        }

        $this->info('Building image index...');
        $batch = [];
        $lines = File::lines($file);
        $header = null;
        $count = 0;
        $imageMap = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['PartmasterID'])) {
                $partId = trim($row['PartmasterID'], '"');
                $imagePath = trim($row['ImagePath'] ?? $row['Path'] ?? '', '"');
                $imageUrl = trim($row['ImageURL'] ?? $row['URL'] ?? '', '"');

                if ($imageUrl || $imagePath) {
                    if (!isset($imageMap[$partId])) {
                        $imageMap[$partId] = [];
                    }

                    if (count($imageMap[$partId]) < 5) {
                        $imageMap[$partId][] = $imageUrl ?: $imagePath;
                    }
                }
            }

            if (count($imageMap) >= 10000) {
                foreach ($imageMap as $partId => $images) {
                    foreach ($images as $position => $url) {
                        $batch[] = [
                            'partmaster_id' => $partId,
                            'image_url' => $url,
                            'position' => $position + 1,
                        ];
                    }
                }
                DB::table('ds_image_index')->insert($batch);
                $count += count($batch);
                $this->line("  Processed {$count} image records...");
                $batch = [];
                $imageMap = [];
            }
        }

        if (!empty($imageMap)) {
            foreach ($imageMap as $partId => $images) {
                foreach ($images as $position => $url) {
                    $batch[] = [
                        'partmaster_id' => $partId,
                        'image_url' => $url,
                        'position' => $position + 1,
                    ];
                }
            }
            DB::table('ds_image_index')->insert($batch);
            $count += count($batch);
        }

        $this->line("  Image index: {$count} records");
    }

    private function buildAttributeIndex(): void
    {
        $attributesFile = $this->basePath . '/Attributes.txt';
        $attributesToPartsFile = $this->basePath . '/AttributesToParts.txt';

        if (!File::exists($attributesFile) || !File::exists($attributesToPartsFile)) {
            $this->warn('Attribute files not found');
            return;
        }

        $this->info('Building attribute index...');

        $attributesLookup = [];
        $lines = File::lines($attributesFile);
        $header = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['id'], $row['Description'])) {
                $attributesLookup[trim($row['id'], '"')] = trim($row['Description'], '"');
            }
        }

        $batch = [];
        $lines = File::lines($attributesToPartsFile);
        $header = null;
        $count = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['Partmasterid'], $row['attributesmasterid'])) {
                $partId = trim($row['Partmasterid'], '"');
                $attrId = trim($row['attributesmasterid'], '"');
                $attrDescription = $attributesLookup[$attrId] ?? "Attribute {$attrId}";

                $batch[] = [
                    'partmaster_id' => $partId,
                    'attribute_id' => $attrId,
                    'attribute_description' => $attrDescription,
                ];
                $count++;

                if (count($batch) >= 5000) {
                    DB::table('ds_attribute_index')->insert($batch);
                    $this->line("  Processed {$count} attribute records...");
                    $batch = [];
                }
            }
        }

        if (!empty($batch)) {
            DB::table('ds_attribute_index')->insert($batch);
        }

        $this->line("  Attribute index: {$count} records");
    }

    private function displayStats(): void
    {
        $this->info('Index Statistics:');
        $this->table(['Table', 'Records'], [
            ['ds_kit_index', DB::table('ds_kit_index')->count()],
            ['ds_manufacturer_index', DB::table('ds_manufacturer_index')->count()],
            ['ds_price_index', DB::table('ds_price_index')->count()],
            ['ds_image_index', DB::table('ds_image_index')->count()],
            ['ds_attribute_index', DB::table('ds_attribute_index')->count()],
        ]);
    }

    private function detectLatestExtractedPath(): ?string
    {
        $baseExtractedPath = '/var/www/html/test14/storage/app/datastream/extracted';

        $fullPath = $baseExtractedPath . '/JonesboroCycleFull';
        if (File::exists($fullPath . '/Partmaster.txt')) {
            return $fullPath;
        }

        if (File::exists($baseExtractedPath)) {
            $directories = File::directories($baseExtractedPath);
            $updateFolders = [];

            foreach ($directories as $dir) {
                $folderName = basename($dir);
                if (strpos($folderName, 'JonesboroCycleUpdate') === 0) {
                    $updateFolders[] = $dir;
                }
            }

            if (!empty($updateFolders)) {
                rsort($updateFolders);
                foreach ($updateFolders as $folder) {
                    if (File::exists($folder . '/Partmaster.txt')) {
                        return $folder;
                    }
                }
            }
        }

        return null;
    }
}
