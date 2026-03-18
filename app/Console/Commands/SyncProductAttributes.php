<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncProductAttributes extends Command
{
    protected $signature = 'ari:sync-attributes {--batch=1000} {--skip=0}';
    protected $description = 'Sync attributes for products missing them';

    private $basePath;
    private $useIndexes = false;
    private $partmasterData = [];

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');

        $this->info('Syncing product attributes');

        $this->basePath = $this->detectLatestExtractedPath();
        if (!$this->basePath) {
            $this->error('No extracted data folders found');
            return Command::FAILURE;
        }

        $this->useIndexes = $this->checkIndexTables();
        if (!$this->useIndexes) {
            $this->warn('Index tables not found. Run: php artisan datastream:build-indexes');
            return Command::FAILURE;
        }

        $this->info('Loading Partmaster data into memory...');
        $this->loadPartmasterData();
        $this->info('Loaded ' . count($this->partmasterData) . ' products from Partmaster');

        $this->info('Finding products without attributes...');
        $productsWithoutAttrs = DB::select("
            SELECT p.id, p.sku, p.type
            FROM products p
            LEFT JOIN product_attribute_values pav ON p.id = pav.product_id
            WHERE pav.id IS NULL
            LIMIT {$batch} OFFSET {$skip}
        ");

        $total = count($productsWithoutAttrs);
        if ($total === 0) {
            $this->info('No products without attributes found');
            return Command::SUCCESS;
        }

        $this->info("Found {$total} products without attributes");

        $processed = 0;
        $synced = 0;
        $failed = 0;

        foreach ($productsWithoutAttrs as $product) {
            $processed++;

            try {
                if ($product->type === 'simple') {
                    $this->syncSimpleProductAttributes($product);
                    $synced++;
                }

                if ($processed % 100 === 0) {
                    $this->line("Progress: {$processed}/{$total} (synced: {$synced}, failed: {$failed})");
                }

            } catch (Exception $e) {
                $failed++;
                $this->error("Failed product ID {$product->id}: " . $e->getMessage());
                Log::error('Attribute sync failed', [
                    'product_id' => $product->id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->info('Attribute sync complete!');
        $this->table(['Metric', 'Count'], [
            ['Processed', $processed],
            ['Synced', $synced],
            ['Failed', $failed],
        ]);

        if ($total === $batch) {
            $nextSkip = $skip + $batch;
            $this->info("More products may exist. Run: php artisan ari:sync-attributes --skip={$nextSkip}");
        }

        return Command::SUCCESS;
    }

    private function checkIndexTables(): bool
    {
        try {
            return DB::table('ds_price_index')->count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    private function loadPartmasterData(): void
    {
        $file = $this->basePath . '/Partmaster.txt';
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
            if ($row && isset($row['ID'])) {
                foreach ($row as $key => $value) {
                    $row[$key] = trim($value, '"');
                }
                $partId = $row['ID'];
                $this->partmasterData[$partId] = $row;
            }
        }
    }

    private function syncSimpleProductAttributes($product): void
    {
        $sku = $product->sku;
        $productId = $product->id;

        $partId = $this->findPartIdBySku($sku);
        if (!$partId) {
            return;
        }

        $partData = $this->partmasterData[$partId] ?? null;
        if (!$partData) {
            return;
        }

        $name = $partData['ItemName'] ?? ('Product ' . $sku);
        $description = $partData['ItemDescription'] ?? '';

        $inventoryData = $this->getProductInventoryData($partId);
        $price = $inventoryData['price'] ?? null;

        $this->addAttributeValues($productId, $name, $description, $price, $sku, $partData);

        $this->updateProductFlat($productId, $sku, $name, $description, $price, $partData);
    }

    private function findPartIdBySku(string $sku): ?string
    {
        foreach ($this->partmasterData as $partId => $data) {
            $long = $data['ManufacturerNumberLong'] ?? '';
            $short = $data['ManufacturerNumberShort'] ?? '';
            $generatedSku = $long ?: ($short ?: "ARI-{$partId}");

            if ($generatedSku === $sku) {
                return $partId;
            }
        }
        return null;
    }

    private function getProductInventoryData(?string $partId): array
    {
        if (!$partId) {
            return ['price' => null, 'quantity' => 0];
        }

        $result = DB::table('ds_price_index')
            ->where('partmaster_id', $partId)
            ->first();

        if ($result) {
            $price = $result->msrp ?? $result->standard_price ?? $result->best_price;
            return [
                'price' => $price > 0 ? $price : null,
                'quantity' => $result->quantity ?? 0,
            ];
        }

        return ['price' => null, 'quantity' => 0];
    }

    private function addAttributeValues(int $productId, string $name, string $description, ?float $price, string $sku, array $product): void
    {
        $channel = 'maddparts';
        $locale = 'en';
        $urlKey = $this->slugify($name . '-' . $sku);

        $manufacturerId = $product['ManufacturerID'] ?? null;
        $fullDescription = $this->buildSimpleDescription($name, $description, $manufacturerId, $sku);

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

    private function updateProductFlat(int $productId, string $sku, string $name, string $description, ?float $price, array $product): void
    {
        $urlKey = $this->slugify($name . '-' . $sku);
        $weight = !empty($product['Weight']) ? (float) $product['Weight'] : 1.0;
        $manufacturerId = $product['ManufacturerID'] ?? null;
        $fullDescription = $this->buildSimpleDescription($name, $description, $manufacturerId, $sku);

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

    private function buildSimpleDescription(string $name, string $description, ?string $manufacturerId, string $sku): string
    {
        $html = '<h3>' . e($name) . '</h3>';

        if ($manufacturerId) {
            $manufacturer = DB::table('ds_manufacturer_index')
                ->where('manufacturer_id', $manufacturerId)
                ->first();
            if ($manufacturer) {
                $html .= '<p><strong>Manufacturer:</strong> ' . e($manufacturer->manufacturer_name) . '</p>';
            }
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

    private function detectLatestExtractedPath(): ?string
    {
        $baseExtractedPath = '/var/www/html/test14/storage/app/datastream/extracted';

        $fullPath = $baseExtractedPath . '/JonesboroCycleFull';
        if (File::exists($fullPath . '/Partmaster.txt')) {
            return $fullPath;
        }

        return null;
    }
}
