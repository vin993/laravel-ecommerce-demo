<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BuildVariantGroups extends Command
{
    protected $signature = 'datastream:build-variant-groups {--force}';
    protected $description = 'Analyze Partmaster and build variant groups index';

    private $basePath;

    public function handle()
    {
        $this->basePath = $this->detectLatestExtractedPath();
        if (!$this->basePath) {
            $this->error('No extracted data folders found');
            return Command::FAILURE;
        }

        $this->info("Analyzing variants from: {$this->basePath}");

        if (!$this->option('force')) {
            $existingCount = DB::table('ds_variant_groups')->count();
            if ($existingCount > 0) {
                if (!$this->confirm("Variant groups table has {$existingCount} records. Rebuild?")) {
                    return Command::SUCCESS;
                }
                DB::table('ds_variant_groups')->truncate();
            }
        }

        $this->info('Analyzing products for variants...');

        $products = $this->loadPartmaster();
        $this->info('Loaded ' . count($products) . ' products from Partmaster');

        $variantGroups = $this->analyzeVariants($products);
        $this->info('Found ' . count($variantGroups) . ' variant groups');

        $this->saveVariantGroups($variantGroups);

        $this->displayStats();

        return Command::SUCCESS;
    }

    private function loadPartmaster(): array
    {
        $file = $this->basePath . '/Partmaster.txt';
        if (!File::exists($file)) {
            return [];
        }

        $products = [];
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
                $products[] = $row;
            }
        }

        return $products;
    }

    private function analyzeVariants(array $products): array
    {
        $this->info('Grouping products by SKU patterns...');

        $groups = [];
        $processed = 0;

        foreach ($products as $product) {
            $processed++;
            if ($processed % 10000 === 0) {
                $this->line("  Analyzed {$processed}/" . count($products) . " products...");
            }

            $sku = $this->getProductSku($product);
            $name = $product['ItemName'] ?? '';
            $partId = $product['ID'] ?? '';

            $baseSku = $this->extractBaseSku($sku);
            $variantInfo = $this->detectVariant($sku, $name);

            if (!$variantInfo) {
                continue;
            }

            if (!isset($groups[$baseSku])) {
                $groups[$baseSku] = [
                    'base_sku' => $baseSku,
                    'base_name' => $this->extractBaseName($name),
                    'variants' => [],
                ];
            }

            $groups[$baseSku]['variants'][] = [
                'partmaster_id' => $partId,
                'sku' => $sku,
                'name' => $name,
                'variant_type' => $variantInfo['type'],
                'variant_value' => $variantInfo['value'],
            ];
        }

        $validGroups = array_filter($groups, function($group) {
            return count($group['variants']) > 1;
        });

        $this->info('Found ' . count($validGroups) . ' valid variant groups (2+ variants each)');

        return $validGroups;
    }

    private function extractBaseSku(string $sku): string
    {
        $cleaned = $sku;

        $patterns = [
            '/-\d+T$/i',
            '/-\d+(?:mm|cm|in|")$/i',
            '/-(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear)$/i',
            '/-[A-Z]$/i',
        ];

        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        return $cleaned;
    }

    private function extractBaseName(string $name): string
    {
        $cleaned = $name;

        $patterns = [
            '/\s*-\s*\d+T\b/i',
            '/\s*-\s*\d+(?:mm|cm|in|")\b/i',
            '/\s*-\s*(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear)\b/i',
        ];

        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned);
        }

        return trim($cleaned);
    }

    private function detectVariant(string $sku, string $name): ?array
    {
        $text = $sku . ' ' . $name;

        if (preg_match('/(\d+)T\b/i', $text, $matches)) {
            return ['type' => 'teeth', 'value' => $matches[1] . 'T'];
        }

        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(?:mm|cm|in|")\b/i', $text, $matches)) {
            return ['type' => 'size', 'value' => $matches[0]];
        }

        if (preg_match('/\b(black|white|red|blue|green|yellow|orange|purple|pink|gray|grey|silver|gold|chrome|clear)\b/i', $text, $matches)) {
            return ['type' => 'color', 'value' => ucfirst(strtolower($matches[1]))];
        }

        if (preg_match('/-([A-Z])$/i', $sku, $matches)) {
            return ['type' => 'option', 'value' => strtoupper($matches[1])];
        }

        return null;
    }

    private function saveVariantGroups(array $variantGroups): void
    {
        $this->info('Saving variant groups to database...');

        $batch = [];
        $count = 0;

        foreach ($variantGroups as $groupId => $group) {
            $groupSize = count($group['variants']);

            foreach ($group['variants'] as $variant) {
                $batch[] = [
                    'variant_group_id' => md5($group['base_sku']),
                    'partmaster_id' => $variant['sku'],
                    'base_name' => $group['base_name'],
                    'base_sku' => $group['base_sku'],
                    'variant_type' => $variant['variant_type'],
                    'variant_value' => $variant['variant_value'],
                    'group_size' => $groupSize,
                ];
                $count++;

                if (count($batch) >= 1000) {
                    DB::table('ds_variant_groups')->insert($batch);
                    $this->line("  Saved {$count} variant records...");
                    $batch = [];
                }
            }
        }

        if (!empty($batch)) {
            DB::table('ds_variant_groups')->insert($batch);
        }

        $this->info("Saved {$count} variant records");
    }

    private function displayStats(): void
    {
        $totalVariants = DB::table('ds_variant_groups')->count();
        $totalGroups = DB::table('ds_variant_groups')
            ->distinct('variant_group_id')
            ->count('variant_group_id');

        $avgGroupSize = $totalGroups > 0 ? round($totalVariants / $totalGroups, 1) : 0;

        $typeStats = DB::table('ds_variant_groups')
            ->select('variant_type', DB::raw('count(*) as count'))
            ->groupBy('variant_type')
            ->get();

        $this->info('Variant Groups Statistics:');
        $this->table(['Metric', 'Value'], [
            ['Total variant products', $totalVariants],
            ['Total variant groups', $totalGroups],
            ['Average variants per group', $avgGroupSize],
        ]);

        $this->info('Variant Types:');
        $typeData = [];
        foreach ($typeStats as $stat) {
            $typeData[] = [$stat->variant_type, $stat->count];
        }
        $this->table(['Type', 'Count'], $typeData);
    }

    private function getProductSku(array $product): string
    {
        $long = $product['ManufacturerNumberLong'] ?? '';
        $short = $product['ManufacturerNumberShort'] ?? '';
        $id = $product['ID'] ?? '';

        return $long ?: ($short ?: "ARI-{$id}");
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
