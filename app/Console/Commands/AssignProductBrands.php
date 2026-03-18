<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignProductBrands extends Command
{
    protected $signature = 'ari:assign-product-brands 
                            {--batch=5000 : Number of products to process per batch}
                            {--skip=0 : Number of products to skip}
                            {--dry-run : Run without making changes}';
    
    protected $description = 'Fast brand assignment using pre-loaded manufacturer data';

    private $partmasterCache = [];
    private $manufacturerCache = [];

    public function handle()
    {
        $batchSize = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $this->info('Starting fast product brand assignment...');

        $brandAttribute = DB::table('attributes')->where('code', 'brand')->first();
        if (!$brandAttribute) {
            $this->error('Brand attribute not found');
            return;
        }

        $brandOptions = DB::table('attribute_options')
            ->where('attribute_id', $brandAttribute->id)
            ->get()
            ->keyBy(function($item) {
                return strtolower($item->admin_name);
            });

        $this->info("Found {$brandOptions->count()} brand options");

        $basePath = $this->detectLatestExtractedPath();
        if (!$basePath) {
            $this->error('DataStream path not found');
            return;
        }

        $this->info("Using DataStream path: {$basePath}");
        
        // Pre-load all data for fast lookups
        $this->info('Pre-loading manufacturer data...');
        $this->manufacturerCache = $this->loadManufacturers($basePath);
        $this->info("Loaded " . count($this->manufacturerCache) . " manufacturers");

        $this->info('Pre-loading partmaster data...');
        $this->loadPartmasterCache($basePath);
        $this->info("Loaded " . count($this->partmasterCache) . " part records");

        $query = DB::table('products as p')
            ->leftJoin('product_attribute_values as pav', function($join) use ($brandAttribute) {
                $join->on('p.id', '=', 'pav.product_id')
                     ->where('pav.attribute_id', '=', $brandAttribute->id);
            })
            ->whereNull('pav.id')
            ->select('p.id', 'p.sku');

        $totalProducts = $query->count();
        $this->info("Found {$totalProducts} products without brands");

        if ($totalProducts === 0) {
            $this->info('All products already have brands assigned');
            return;
        }

        $products = $query->skip($skip)->take($batchSize)->get();
        $processed = 0;
        $assigned = 0;
        $notFound = 0;
        $bulkInserts = [];

        foreach ($products as $product) {
            $processed++;
            
            $manufacturerName = $this->findProductManufacturerFast($product->sku);
            
            if (!$manufacturerName) {
                $notFound++;
                continue;
            }

            $brandOption = $brandOptions->get(strtolower($manufacturerName));
            
            if (!$brandOption) {
                $notFound++;
                continue;
            }

            if ($dryRun) {
                $this->line("Would assign '{$manufacturerName}' to SKU: {$product->sku}");
            } else {
                $uniqueId = 'default|en|' . $product->id . '|' . $brandAttribute->id;
                
                $bulkInserts[] = [
                    'product_id' => $product->id,
                    'attribute_id' => $brandAttribute->id,
                    'locale' => 'en',
                    'channel' => 'default',
                    'text_value' => (string) $brandOption->id,
                    'unique_id' => $uniqueId
                ];
            }

            $assigned++;
            
            if ($processed % 1000 === 0) {
                $this->line("Processed: {$processed}/{$batchSize}");
                
                if (!$dryRun && !empty($bulkInserts)) {
                    DB::table('product_attribute_values')->insert($bulkInserts);
                    $this->line("  Inserted " . count($bulkInserts) . " brand assignments");
                    $bulkInserts = [];
                }
            }
        }

        // Insert remaining records
        if (!$dryRun && !empty($bulkInserts)) {
            DB::table('product_attribute_values')->insert($bulkInserts);
            $this->line("Inserted final " . count($bulkInserts) . " brand assignments");
        }

        $this->info("Batch complete:");
        $this->info("- Processed: {$processed}");
        $this->info("- Assigned: {$assigned}");
        $this->info("- Not found: {$notFound}");
        
        $remaining = $totalProducts - ($skip + $batchSize);
        if ($remaining > 0) {
            $nextSkip = $skip + $batchSize;
            $this->info("Continue with: php artisan ari:assign-product-brands --skip={$nextSkip} --batch={$batchSize}");
        }
    }

    private function findProductManufacturerFast($sku)
    {
        if (isset($this->partmasterCache[$sku])) {
            $manufacturerId = $this->partmasterCache[$sku];
            return $this->manufacturerCache[$manufacturerId] ?? null;
        }
        return null;
    }

    private function loadPartmasterCache($basePath)
    {
        $file = $basePath . '/Partmaster.txt';
        if (!file_exists($file)) {
            return;
        }

        $handle = fopen($file, 'r');
        $header = null;
        $count = 0;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map('trim', array_map(fn($h) => trim($h, '"'), $data));
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if (!$row || !isset($row['ID'])) continue;

            foreach ($row as $key => $value) {
                $row[$key] = trim($value, '"');
            }

            $sku = $this->getProductSkuFromRow($row);
            $manufacturerId = $row['ManufacturerID'] ?? null;
            
            if ($sku && $manufacturerId) {
                $this->partmasterCache[$sku] = $manufacturerId;
            }

            $count++;
            if ($count % 50000 === 0) {
                $this->line("  Loaded {$count} part records...");
            }
        }

        fclose($handle);
    }

    private function detectLatestExtractedPath(): ?string
    {
        $baseExtractedPath = '/var/www/html/test14/storage/app/datastream/extracted';

        $fullPath = $baseExtractedPath . '/JonesboroCycleFull';
        if (file_exists($fullPath . '/Partmaster.txt')) {
            return $fullPath;
        }

        return null;
    }

    private function loadManufacturers($basePath): array
    {
        $manufacturers = [];
        $file = $basePath . '/Manufacturer.txt';
        if (!file_exists($file)) {
            return $manufacturers;
        }

        $handle = fopen($file, 'r');
        $header = null;

        while (($line = fgets($handle)) !== false) {
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
                $manufacturers[trim($row['id'], '"')] = trim($row['ManufacturerName'], '"');
            }
        }

        fclose($handle);
        return $manufacturers;
    }

    private function getProductSkuFromRow(array $product): string
    {
        $long = $product['ManufacturerNumberLong'] ?? '';
        $short = $product['ManufacturerNumberShort'] ?? '';
        $id = $product['ID'] ?? '';

        return $long ?: ($short ?: "ARI-{$id}");
    }
}