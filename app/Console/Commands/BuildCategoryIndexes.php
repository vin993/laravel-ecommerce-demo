<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class BuildCategoryIndexes extends Command
{
    protected $signature = 'datastream:build-category-indexes';
    protected $description = 'Build category index tables from Level files';

    private $basePath;

    public function handle()
    {
        $this->info('Building category indexes...');

        $this->basePath = $this->detectLatestExtractedPath();
        if (!$this->basePath) {
            $this->error('No extracted data folders found');
            return Command::FAILURE;
        }

        $this->info("Reading from: {$this->basePath}");

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            $this->buildLevelTwoIndex();
            $this->buildLevelThreeIndex();
            $this->buildLevelFourIndex();
            $this->buildLevelFiveIndex();
            $this->buildLevelMasterIndex();
            $this->buildCategoryProductIndex();

            DB::statement('SET FOREIGN_KEY_CHECKS=1');

            $this->info('Category indexes built successfully!');
            $this->displayStats();

            return Command::SUCCESS;

        } catch (Exception $e) {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            $this->error('Failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    private function buildLevelTwoIndex(): void
    {
        $this->info('Building Level 2 index...');
        DB::table('ds_level_two_index')->truncate();

        $file = $this->basePath . '/LevelTwo.txt';
        if (!File::exists($file)) {
            $this->warn('LevelTwo.txt not found');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $csv = str_getcsv($line);
            if (!$header) {
                $header = array_map(fn($h) => trim($h, '"'), $csv);
                continue;
            }

            if (count($header) !== count($csv)) continue;

            $row = array_combine($header, $csv);
            if ($row && isset($row['id'], $row['description'])) {
                $data[] = [
                    'id' => trim($row['id'], '"'),
                    'description' => trim($row['description'], '"'),
                ];
            }
        }

        if (!empty($data)) {
            DB::table('ds_level_two_index')->insert($data);
            $this->line('Level 2: ' . count($data) . ' records');
        }
    }

    private function buildLevelThreeIndex(): void
    {
        $this->info('Building Level 3 index...');
        DB::table('ds_level_three_index')->truncate();

        $file = $this->basePath . '/LevelThree.txt';
        if (!File::exists($file)) {
            $this->warn('LevelThree.txt not found');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $csv = str_getcsv($line);
            if (!$header) {
                $header = array_map(fn($h) => trim($h, '"'), $csv);
                continue;
            }

            if (count($header) !== count($csv)) continue;

            $row = array_combine($header, $csv);
            if ($row && isset($row['id'], $row['description'])) {
                $data[] = [
                    'id' => trim($row['id'], '"'),
                    'description' => trim($row['description'], '"'),
                ];
            }
        }

        if (!empty($data)) {
            DB::table('ds_level_three_index')->insert($data);
            $this->line('Level 3: ' . count($data) . ' records');
        }
    }

    private function buildLevelFourIndex(): void
    {
        $this->info('Building Level 4 index...');
        DB::table('ds_level_four_index')->truncate();

        $file = $this->basePath . '/LevelFour.txt';
        if (!File::exists($file)) {
            $this->warn('LevelFour.txt not found');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $data = [];
        $batch = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $csv = str_getcsv($line);
            if (!$header) {
                $header = array_map(fn($h) => trim($h, '"'), $csv);
                continue;
            }

            if (count($header) !== count($csv)) continue;

            $row = array_combine($header, $csv);
            if ($row && isset($row['id'], $row['description'])) {
                $data[] = [
                    'id' => trim($row['id'], '"'),
                    'description' => trim($row['description'], '"'),
                ];

                if (count($data) >= 500) {
                    DB::table('ds_level_four_index')->insert($data);
                    $batch += count($data);
                    $data = [];
                }
            }
        }

        if (!empty($data)) {
            DB::table('ds_level_four_index')->insert($data);
            $batch += count($data);
        }

        $this->line('Level 4: ' . $batch . ' records');
    }

    private function buildLevelFiveIndex(): void
    {
        $this->info('Building Level 5 index...');
        DB::table('ds_level_five_index')->truncate();

        $file = $this->basePath . '/LevelFive.txt';
        if (!File::exists($file)) {
            $this->warn('LevelFive.txt not found');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $data = [];
        $batch = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $csv = str_getcsv($line);
            if (!$header) {
                $header = array_map(fn($h) => trim($h, '"'), $csv);
                continue;
            }

            if (count($header) !== count($csv)) continue;

            $row = array_combine($header, $csv);
            if ($row && isset($row['id'], $row['description'])) {
                $data[] = [
                    'id' => trim($row['id'], '"'),
                    'description' => trim($row['description'], '"'),
                ];

                if (count($data) >= 500) {
                    DB::table('ds_level_five_index')->insert($data);
                    $batch += count($data);
                    $data = [];
                }
            }
        }

        if (!empty($data)) {
            DB::table('ds_level_five_index')->insert($data);
            $batch += count($data);
        }

        $this->line('Level 5: ' . $batch . ' records');
    }

    private function buildLevelMasterIndex(): void
    {
        $this->info('Building Level Master index...');
        DB::table('ds_level_master_index')->truncate();

        $file = $this->basePath . '/LevelMaster.txt';
        if (!File::exists($file)) {
            $this->warn('LevelMaster.txt not found');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $data = [];
        $batch = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $csv = str_getcsv($line);
            if (!$header) {
                $header = array_map(fn($h) => trim($h, '"'), $csv);
                continue;
            }

            if (count($header) !== count($csv)) continue;

            $row = array_combine($header, $csv);
            if ($row && isset($row['id'])) {
                $data[] = [
                    'id' => trim($row['id'], '"'),
                    'level_two_id' => isset($row['LevelTwoID']) ? trim($row['LevelTwoID'], '"') : null,
                    'level_three_id' => isset($row['LevelThreeID']) ? trim($row['LevelThreeID'], '"') : null,
                    'level_four_id' => isset($row['LevelFourID']) ? trim($row['LevelFourID'], '"') : null,
                    'level_five_id' => isset($row['LevelFiveID']) ? trim($row['LevelFiveID'], '"') : null,
                    'bagisto_category_id' => null,
                ];

                if (count($data) >= 500) {
                    DB::table('ds_level_master_index')->insert($data);
                    $batch += count($data);
                    $data = [];
                }
            }
        }

        if (!empty($data)) {
            DB::table('ds_level_master_index')->insert($data);
            $batch += count($data);
        }

        $this->line('Level Master: ' . $batch . ' records');
    }

    private function buildCategoryProductIndex(): void
    {
        $this->info('Building Category-Product index...');
        DB::table('ds_category_product_index')->truncate();

        $file = $this->basePath . '/Category.txt';
        if (!File::exists($file)) {
            $this->warn('Category.txt not found');
            return;
        }

        $lines = File::lines($file);
        $header = null;
        $data = [];
        $batch = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $csv = str_getcsv($line);
            if (!$header) {
                $header = array_map(fn($h) => trim($h, '"'), $csv);
                continue;
            }

            if (count($header) !== count($csv)) continue;

            $row = array_combine($header, $csv);
            if ($row && isset($row['PartMasterID'], $row['LevelMasterID'])) {
                $data[] = [
                    'partmaster_id' => trim($row['PartMasterID'], '"'),
                    'level_master_id' => trim($row['LevelMasterID'], '"'),
                ];

                if (count($data) >= 5000) {
                    DB::table('ds_category_product_index')->insert($data);
                    $batch += count($data);
                    $data = [];
                    $this->line('Progress: ' . $batch . ' mappings');
                }
            }
        }

        if (!empty($data)) {
            DB::table('ds_category_product_index')->insert($data);
            $batch += count($data);
        }

        $this->line('Category-Product: ' . $batch . ' records');
    }

    private function displayStats(): void
    {
        $this->info('');
        $this->info('Index Statistics:');
        $this->table(['Table', 'Records'], [
            ['ds_level_two_index', DB::table('ds_level_two_index')->count()],
            ['ds_level_three_index', DB::table('ds_level_three_index')->count()],
            ['ds_level_four_index', DB::table('ds_level_four_index')->count()],
            ['ds_level_five_index', DB::table('ds_level_five_index')->count()],
            ['ds_level_master_index', DB::table('ds_level_master_index')->count()],
            ['ds_category_product_index', DB::table('ds_category_product_index')->count()],
        ]);
    }

    private function detectLatestExtractedPath(): ?string
    {
        $baseExtractedPath = '/var/www/html/test14/storage/app/datastream/extracted';

        $fullPath = $baseExtractedPath . '/JonesboroCycleFull';
        if (File::exists($fullPath . '/LevelMaster.txt')) {
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
                    if (File::exists($folder . '/LevelMaster.txt')) {
                        return $folder;
                    }
                }
            }
        }

        return null;
    }
}
