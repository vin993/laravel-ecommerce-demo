<?php

namespace App\Console\Commands\UpdateFiles;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncUpdateAttributes extends Command
{
    protected $signature = 'ari:sync-update-attributes 
                            {--path= : Update folder path}
                            {--batch=5000}';
    
    protected $description = 'Sync attributes for products from specific update folder';

    private $basePath;
    private $partmasterData = [];
    private $priceCache = [];
    private $manufacturerCache = [];

    public function handle()
    {
        $updateFolder = $this->option('path');
        $batch = (int) $this->option('batch');

        if (!$updateFolder) {
            $this->error('Provide --path option');
            return Command::FAILURE;
        }

        $this->basePath = '/var/www/html/test14/storage/app/datastream/extracted/' . $updateFolder;

        if (!File::exists($this->basePath)) {
            $this->error("Update folder not found: {$this->basePath}");
            return Command::FAILURE;
        }

        $this->info("Syncing attributes from: {$updateFolder}");

        try {
            $this->loadPartmasterData();
            $this->loadPriceCache();
            $this->loadManufacturers();

            $this->info('Finding products from this update without attributes...');
            
            $skusInUpdate = array_map(
                fn($data) => $this->getProductSku($data),
                $this->partmasterData
            );

            $productsWithoutAttrs = DB::table('products as p')
                ->leftJoin('product_attribute_values as pav', 'p.id', '=', 'pav.product_id')
                ->whereIn('p.sku', $skusInUpdate)
                ->whereNull('pav.id')
                ->select('p.id', 'p.sku', 'p.type')
                ->limit($batch)
                ->get();

            $total = count($productsWithoutAttrs);
            if ($total === 0) {
                $this->info('No products from this update need attributes');
                return Command::SUCCESS;
            }

            $this->info("Processing {$total} products");

            $processed = 0;
            $synced = 0;

            foreach ($productsWithoutAttrs as $product) {
                try {
                    if ($product->type === 'simple') {
                        $this->syncSimpleProductAttributes($product);
                        $synced++;
                    }

                    $processed++;
                    if ($processed % 100 === 0) {
                        $this->line("Progress: {$processed}/{$total}");
                    }

                } catch (Exception $e) {
                    $this->error("Failed product {$product->sku}: " . $e->getMessage());
                }
            }

            $this->info("Complete: Synced {$synced} products");
            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function loadPartmasterData(): void
    {
        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            return;
        }

        $this->info('Loading Partmaster data...');
        $lines = File::lines($file);
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
            if ($row && isset($row['ID'])) {
                foreach ($row as $key => $value) {
                    $row[$key] = trim($value, '"');
                }
                $this->partmasterData[$row['ID']] = $row;
            }
        }

        $this->info('Loaded ' . count($this->partmasterData) . ' parts');
    }

    private function loadPriceCache(): void
    {
        $file = $this->basePath . '/PartPriceInv.txt';
        if (!File::exists($file)) {
            return;
        }

        $this->info('Loading price data...');
        $lines = File::lines($file);
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
            if (!$row) continue;

            $partId = trim($row['PartmasterID'] ?? '', '"');
            if (!$partId) continue;

            $price = (float) ($row['MSRP'] ?? $row['StandardPrice'] ?? $row['BestPrice'] ?? 0);

            if (!isset($this->priceCache[$partId]) || $price > $this->priceCache[$partId]) {
                $this->priceCache[$partId] = $price;
            }
        }

        $this->info('Loaded ' . count($this->priceCache) . ' prices');
    }

    private function loadManufacturers(): void
    {
        $file = $this->basePath . '/Manufacturer.txt';
        if (!File::exists($file)) {
            return;
        }

        $lines = File::lines($file);
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
            if ($row && isset($row['ID'], $row['ManufacturerName'])) {
                $this->manufacturerCache[trim($row['ID'], '"')] = trim($row['ManufacturerName'], '"');
            }
        }

        $this->info('Loaded ' . count($this->manufacturerCache) . ' manufacturers');
    }

    private function syncSimpleProductAttributes($product): void
    {
        $sku = $product->sku;
        $productId = $product->id;

        $partId = $this->findPartIdBySku($sku);
        if (!$partId || !isset($this->partmasterData[$partId])) {
            return;
        }

        $partData = $this->partmasterData[$partId];
        $name = $partData['ItemName'] ?? ('Product ' . $sku);
        $description = $partData['ItemDescription'] ?? '';
        $price = $this->priceCache[$partId] ?? null;
        $manufacturerId = $partData['ManufacturerID'] ?? null;

        $this->addAttributeValues($productId, $name, $description, $price, $sku, $partData);
        $this->updateProductFlat($productId, $sku, $name, $description, $price, $partData, $manufacturerId);
    }

    private function findPartIdBySku(string $sku): ?string
    {
        foreach ($this->partmasterData as $partId => $data) {
            $genSku = $this->getProductSku($data);
            if ($genSku === $sku) {
                return $partId;
            }
        }
        return null;
    }

    private function getProductSku(array $product): string
    {
        $long = $product['ManufacturerNumberLong'] ?? '';
        $short = $product['ManufacturerNumberShort'] ?? '';
        $id = $product['ID'] ?? '';

        return $long ?: ($short ?: "ARI-{$id}");
    }

    private function addAttributeValues(int $productId, string $name, string $description, ?float $price, string $sku, array $product): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $urlKey = $this->slugify($name . '-' . $sku);
        $manufacturerId = $product['ManufacturerID'] ?? null;
        $fullDescription = $this->buildDescription($name, $description, $manufacturerId, $sku);

        $attributes = [
            ['id' => 1, 'value' => $sku, 'type' => 'text'],
            ['id' => 2, 'value' => $name, 'type' => 'text'],
            ['id' => 3, 'value' => $urlKey, 'type' => 'text'],
            ['id' => 9, 'value' => mb_substr($description, 0, 200), 'type' => 'text'],
            ['id' => 10, 'value' => $fullDescription, 'type' => 'text'],
            ['id' => 8, 'value' => 1, 'type' => 'boolean'],
            ['id' => 7, 'value' => 1, 'type' => 'boolean'],
        ];

        if ($price) {
            $attributes[] = ['id' => 11, 'value' => $price, 'type' => 'float'];
        }

        $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
        $attributes[] = ['id' => 22, 'value' => $weight, 'type' => 'text'];

        foreach ($attributes as $attr) {
            $uniqueId = $channel . '|' . $locale . '|' . $productId . '|' . $attr['id'];

            $data = [
                'product_id' => $productId,
                'attribute_id' => $attr['id'],
                'locale' => $locale,
                'channel' => $channel,
                'unique_id' => $uniqueId,
            ];

            $type = $attr['type'] ?? 'text';
            if ($type === 'boolean') {
                $data['boolean_value'] = $attr['value'] ? 1 : 0;
                $data['text_value'] = null;
                $data['float_value'] = null;
            } elseif ($type === 'float') {
                $data['float_value'] = (float) $attr['value'];
                $data['text_value'] = null;
                $data['boolean_value'] = null;
            } else {
                $data['text_value'] = (string) $attr['value'];
                $data['float_value'] = null;
                $data['boolean_value'] = null;
            }

            DB::table('product_attribute_values')->insert($data);
        }
    }

    private function updateProductFlat(int $productId, string $sku, string $name, string $description, ?float $price, array $product, ?string $manufacturerId): void
    {
        $urlKey = $this->slugify($name . '-' . $sku);
        $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
        $fullDescription = $this->buildDescription($name, $description, $manufacturerId, $sku);

        DB::table('product_flat')
            ->where('product_id', $productId)
            ->update([
                'name' => $name,
                'short_description' => mb_substr($description, 0, 200),
                'description' => $fullDescription,
                'url_key' => $urlKey,
                'price' => $price,
                'weight' => $weight,
                'updated_at' => now(),
            ]);
    }

    private function buildDescription(string $name, string $description, ?string $manufacturerId, string $sku): string
    {
        $html = '<h3>' . e($name) . '</h3>';

        if ($manufacturerId && isset($this->manufacturerCache[$manufacturerId])) {
            $html .= '<p><strong>Manufacturer:</strong> ' . e($this->manufacturerCache[$manufacturerId]) . '</p>';
        }

        $html .= '<p><strong>SKU:</strong> ' . e($sku) . '</p>';

        if ($description) {
            $html .= '<p>' . e($description) . '</p>';
        }

        $html .= '<p><em>Imported from ARI DataStream</em></p>';

        return $html;
    }

    private function slugify(string $text): string
    {
        $url = strtolower(trim($text));
        $url = preg_replace('/[^a-z0-9]+/i', '-', $url);
        return trim($url, '-');
    }
}
