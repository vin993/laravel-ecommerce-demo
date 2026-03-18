<?php

namespace App\Console\Commands\VariantConversion;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RebuildVariantGroupsFromPartGroupings extends Command
{
    protected $signature = 'ari:rebuild-variant-groups-from-partgroupings {--force}';
    protected $description = 'Rebuild variant groups using PartGroupings.txt (authoritative source)';

    private $basePath;
    private $partmasterIndex = [];

    public function handle()
    {
        $this->basePath = $this->detectDataPath();
        if (!$this->basePath) {
            $this->error('DataStream path not found');
            return Command::FAILURE;
        }

        $this->info("Reading from: {$this->basePath}");

        $partGroupingsFile = $this->basePath . '/PartGroupings.txt';
        $partmasterFile = $this->basePath . '/Partmaster.txt';

        if (!File::exists($partGroupingsFile)) {
            $this->error('PartGroupings.txt not found');
            return Command::FAILURE;
        }

        if (!File::exists($partmasterFile)) {
            $this->error('Partmaster.txt not found');
            return Command::FAILURE;
        }

        $existingCount = DB::table('ds_variant_groups')->count();
        if ($existingCount > 0 && !$this->option('force')) {
            if (!$this->confirm("ds_variant_groups has {$existingCount} records. Rebuild?")) {
                return Command::SUCCESS;
            }
        }

        try {
            $this->info('Step 1/3: Loading Partmaster index...');
            $this->loadPartmasterIndex($partmasterFile);
            $this->info('Loaded ' . count($this->partmasterIndex) . ' products from Partmaster');

            $this->info('Step 2/3: Truncating ds_variant_groups...');
            DB::table('ds_variant_groups')->truncate();

            $this->info('Step 3/3: Processing PartGroupings.txt...');
            $this->processPartGroupings($partGroupingsFile);

            $this->displayStats();

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function loadPartmasterIndex(string $file): void
    {
        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new Exception('Cannot open Partmaster.txt');
        }

        $header = null;
        $count = 0;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map(function($h) {
                    return trim($h, '"');
                }, $data);
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['ID'])) {
                foreach ($row as $key => $value) {
                    $row[$key] = trim($value, '"');
                }

                $partmasterId = $row['ID'];
                $sku = $row['ManufacturerNumberLong'] ?: ($row['ManufacturerNumberShort'] ?: "ARI-{$partmasterId}");
                $name = $row['ItemName'] ?? '';

                $this->partmasterIndex[$partmasterId] = [
                    'sku' => $sku,
                    'name' => $name,
                ];

                $count++;
                if ($count % 50000 === 0) {
                    $this->line("  Loaded {$count} products...");
                }
            }
        }

        fclose($handle);
    }

    private function processPartGroupings(string $file): void
    {
        $handle = fopen($file, 'r');
        if (!$handle) {
            throw new Exception('Cannot open PartGroupings.txt');
        }

        $header = null;
        $groups = [];
        $count = 0;
        $inserted = 0;

        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if (empty($line)) continue;

            $data = str_getcsv($line);
            if (!$header) {
                $header = array_map(function($h) {
                    return trim($h, '"');
                }, $data);
                continue;
            }

            if (count($header) !== count($data)) continue;

            $row = array_combine($header, $data);
            if ($row && isset($row['PartMasterID']) && isset($row['GroupID'])) {
                foreach ($row as $key => $value) {
                    $row[$key] = trim($value, '"');
                }

                $partmasterId = $row['PartMasterID'];
                $groupId = $row['GroupID'];

                if (!isset($this->partmasterIndex[$partmasterId])) {
                    continue;
                }

                if (!isset($groups[$groupId])) {
                    $groups[$groupId] = [];
                }

                $groups[$groupId][] = $partmasterId;

                $count++;
                if ($count % 50000 === 0) {
                    $this->line("  Processed {$count} records...");
                }
            }
        }

        fclose($handle);

        $this->info("Processed {$count} PartGroupings records");
        $this->info("Found " . count($groups) . " unique groups");

        $validGroups = array_filter($groups, function($members) {
            return count($members) >= 2;
        });

        $this->info("Valid groups (2+ products): " . count($validGroups));

        $batch = [];
        $batchSize = 1000;

        foreach ($validGroups as $groupId => $members) {
            $groupSize = count($members);

            foreach ($members as $partmasterId) {
                $productInfo = $this->partmasterIndex[$partmasterId];

                $batch[] = [
                    'variant_group_id' => $groupId,
                    'partmaster_id' => $productInfo['sku'],
                    'base_name' => $productInfo['name'],
                    'base_sku' => $productInfo['sku'],
                    'variant_type' => null,
                    'variant_value' => null,
                    'group_size' => $groupSize,
                ];

                $inserted++;

                if (count($batch) >= $batchSize) {
                    DB::table('ds_variant_groups')->insert($batch);
                    $this->line("  Inserted {$inserted} variant records...");
                    $batch = [];
                    gc_collect_cycles();
                }
            }
        }

        if (!empty($batch)) {
            DB::table('ds_variant_groups')->insert($batch);
        }

        $this->info("Inserted {$inserted} variant records");
    }

    private function displayStats(): void
    {
        $totalVariants = DB::table('ds_variant_groups')->count();
        $totalGroups = DB::table('ds_variant_groups')
            ->distinct('variant_group_id')
            ->count('variant_group_id');

        $avgGroupSize = $totalGroups > 0 ? round($totalVariants / $totalGroups, 1) : 0;

        $this->info('Variant Groups Statistics:');
        $this->table(['Metric', 'Value'], [
            ['Total variant products', number_format($totalVariants)],
            ['Total variant groups', number_format($totalGroups)],
            ['Average variants per group', $avgGroupSize],
        ]);

        $topGroups = DB::table('ds_variant_groups')
            ->select('variant_group_id', 'base_name', DB::raw('COUNT(*) as count'))
            ->groupBy('variant_group_id', 'base_name')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $this->info('Top 10 Largest Groups:');
        $tableData = [];
        foreach ($topGroups as $group) {
            $tableData[] = [
                $group->variant_group_id,
                substr($group->base_name, 0, 50),
                $group->count,
            ];
        }
        $this->table(['Group ID', 'Product Name', 'Variants'], $tableData);
    }

    private function detectDataPath(): ?string
    {
        $paths = [
            '/var/www/html/test14/storage/app/datastream/extracted/JonesboroCycleFull',
            storage_path('app/datastream/extracted/JonesboroCycleFull'),
        ];

        foreach ($paths as $path) {
            if (File::exists($path . '/PartGroupings.txt')) {
                return $path;
            }
        }

        return null;
    }
}
