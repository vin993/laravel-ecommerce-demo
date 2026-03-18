<?php

namespace App\Console\Commands\VariantConversion;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConvertToConfigurable extends Command
{
    protected $signature = 'ari:convert-to-configurable
                            {--batch=100 : Number of groups to process}
                            {--skip=0 : Skip first N groups}
                            {--dry-run : Show what would be done}
                            {--group-id= : Process specific group ID only}';

    protected $description = 'Convert variant products to configurable products with children';

    private $channel = 'maddparts';
    private $locale = 'en';
    private $attributeFamilyId = 1;

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $dryRun = (bool) $this->option('dry-run');
        $specificGroupId = $this->option('group-id');

        if ($dryRun) {
            $this->warn('DRY-RUN MODE - No changes will be made');
        }

        $variantGroupsCount = DB::table('ds_variant_groups')->count();
        if ($variantGroupsCount === 0) {
            $this->error('No variant groups found. Run: php artisan ari:rebuild-variant-groups-from-partgroupings');
            return Command::FAILURE;
        }

        if ($specificGroupId) {
            $this->info("Processing specific group: {$specificGroupId}");
            return $this->processSpecificGroup($specificGroupId, $dryRun);
        }

        $totalGroups = DB::table('ds_variant_groups')
            ->select('variant_group_id')
            ->groupBy('variant_group_id')
            ->havingRaw('COUNT(*) >= 2')
            ->get()
            ->count();

        $this->info("Total variant groups: " . number_format($totalGroups));
        $this->info("Processing batch: {$batch} groups (skip: {$skip})");
        $this->newLine();

        $groups = DB::table('ds_variant_groups')
            ->select('variant_group_id', DB::raw('MIN(base_name) as base_name'), DB::raw('MIN(base_sku) as base_sku'))
            ->groupBy('variant_group_id')
            ->havingRaw('COUNT(*) >= 2')
            ->orderBy('variant_group_id')
            ->skip($skip)
            ->take($batch)
            ->get();

        if ($groups->isEmpty()) {
            $this->warn('No groups to process in this batch');
            return Command::SUCCESS;
        }

        $processed = 0;
        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($groups as $group) {
            $processed++;

            try {
                $result = $this->processGroup($group, $dryRun);

                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                }

                if ($processed % 10 === 0) {
                    $this->line("Progress: {$processed}/{$batch} (created: {$created}, skipped: {$skipped}, errors: {$errors})");
                    gc_collect_cycles();
                }

            } catch (Exception $e) {
                $errors++;
                $this->error("Error processing group {$group->variant_group_id}: " . $e->getMessage());
                Log::error('Variant conversion error', [
                    'group_id' => $group->variant_group_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info('Batch complete:');
        $this->table(['Metric', 'Count'], [
            ['Groups processed', $processed],
            ['Configurables created', $created],
            ['Skipped (already done)', $skipped],
            ['Errors', $errors],
        ]);

        $nextSkip = $skip + $batch;
        if ($nextSkip < $totalGroups) {
            $this->newLine();
            $this->info("To continue:");
            $this->line("  sudo -u www-data php artisan ari:convert-to-configurable --batch={$batch} --skip={$nextSkip}");
        } else {
            $this->newLine();
            $this->info('All groups processed!');
            $this->line('Next step: Rebuild indexes');
            $this->line('  sudo -u www-data php artisan ari:rebuild-indexes');
        }

        return Command::SUCCESS;
    }

    private function processGroup($groupInfo, bool $dryRun): string
    {
        $groupId = $groupInfo->variant_group_id;
        $baseName = $groupInfo->base_name;

        $variants = DB::table('ds_variant_groups as vg')
            ->join('products as p', 'p.sku', '=', 'vg.partmaster_id')
            ->where('vg.variant_group_id', $groupId)
            ->where('p.type', 'simple')
            ->select('p.*', 'vg.variant_group_id')
            ->get();

        if ($variants->isEmpty()) {
            return 'skipped';
        }

        $firstVariant = $variants->first();
        $parentSku = $firstVariant->sku . '-PARENT';

        $existingParent = DB::table('products')
            ->where('sku', $parentSku)
            ->where('type', 'configurable')
            ->first();

        if ($existingParent) {
            if (!$dryRun) {
                foreach ($variants as $variant) {
                    if (is_null($variant->parent_id)) {
                        DB::table('products')
                            ->where('id', $variant->id)
                            ->update(['parent_id' => $existingParent->id]);
                    }
                }
            }
            return 'skipped';
        }

        if ($dryRun) {
            $this->line("Would create: {$baseName} with " . count($variants) . " variants");
            return 'created';
        }

        DB::transaction(function() use ($parentSku, $baseName, $firstVariant, $variants) {
            $parentId = DB::table('products')->insertGetId([
                'sku' => $parentSku,
                'type' => 'configurable',
                'attribute_family_id' => $this->attributeFamilyId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->createParentAttributes($parentId, $parentSku, $baseName, $firstVariant);

            $categoryId = DB::table('product_categories')
                ->where('product_id', $firstVariant->id)
                ->value('category_id');

            if ($categoryId) {
                DB::table('product_categories')->insert([
                    'product_id' => $parentId,
                    'category_id' => $categoryId,
                ]);
            }

            $inventories = DB::table('product_inventories')
                ->where('product_id', $firstVariant->id)
                ->get();

            foreach ($inventories as $inventory) {
                DB::table('product_inventories')->insert([
                    'qty' => 0,
                    'product_id' => $parentId,
                    'inventory_source_id' => $inventory->inventory_source_id,
                    'vendor_id' => $inventory->vendor_id ?? 0,
                ]);
            }

            foreach ($variants as $variant) {
                DB::table('products')
                    ->where('id', $variant->id)
                    ->update([
                        'parent_id' => $parentId,
                        'updated_at' => now(),
                    ]);
            }

            $firstImage = DB::table('product_images')
                ->where('product_id', $firstVariant->id)
                ->first();

            if ($firstImage) {
                DB::table('product_images')->insert([
                    'type' => 'images',
                    'path' => $firstImage->path,
                    'product_id' => $parentId,
                ]);
            }
        });

        $this->line("Created: {$baseName} with " . count($variants) . " variants");
        return 'created';
    }

    private function processSpecificGroup(string $groupId, bool $dryRun): int
    {
        $groupInfo = DB::table('ds_variant_groups')
            ->where('variant_group_id', $groupId)
            ->select('variant_group_id', DB::raw('MIN(base_name) as base_name'), DB::raw('MIN(base_sku) as base_sku'))
            ->groupBy('variant_group_id')
            ->first();

        if (!$groupInfo) {
            $this->error("Group ID {$groupId} not found");
            return Command::FAILURE;
        }

        $this->info("Processing group: {$groupInfo->base_name}");

        $result = $this->processGroup($groupInfo, $dryRun);

        $this->newLine();
        $this->info("Result: {$result}");

        return Command::SUCCESS;
    }

    private function createParentAttributes(int $parentId, string $parentSku, string $baseName, $firstVariant): void
    {
        $urlKey = $this->slugify($baseName . '-' . $parentSku);

        $description = DB::table('product_attribute_values')
            ->where('product_id', $firstVariant->id)
            ->where('attribute_id', 10)
            ->value('text_value');

        if (!$description) {
            $description = '<p>' . htmlspecialchars($baseName) . '</p>';
        }

        $attributes = [
            ['id' => 1, 'value' => $parentSku, 'type' => 'text'],
            ['id' => 2, 'value' => $baseName, 'type' => 'text'],
            ['id' => 3, 'value' => $urlKey, 'type' => 'text'],
            ['id' => 9, 'value' => $baseName, 'type' => 'text'],
            ['id' => 10, 'value' => $description, 'type' => 'text'],
            ['id' => 7, 'value' => 1, 'type' => 'boolean'],
            ['id' => 8, 'value' => 1, 'type' => 'boolean'],
        ];

        $price = DB::table('product_attribute_values')
            ->where('product_id', $firstVariant->id)
            ->where('attribute_id', 11)
            ->value('float_value');

        if ($price) {
            $attributes[] = ['id' => 11, 'value' => $price, 'type' => 'float'];
        }

        foreach ($attributes as $attr) {
            $uniqueId = $this->channel . '|' . $this->locale . '|' . $parentId . '|' . $attr['id'];

            $data = [
                'product_id' => $parentId,
                'attribute_id' => $attr['id'],
                'locale' => $this->locale,
                'channel' => $this->channel,
                'unique_id' => $uniqueId,
                'text_value' => null,
                'float_value' => null,
                'integer_value' => null,
                'boolean_value' => null,
                'datetime_value' => null,
                'date_value' => null,
                'json_value' => null,
            ];

            if ($attr['type'] === 'boolean') {
                $data['boolean_value'] = $attr['value'] ? 1 : 0;
            } elseif ($attr['type'] === 'float') {
                $data['float_value'] = $attr['value'];
            } else {
                $data['text_value'] = (string) $attr['value'];
            }

            DB::table('product_attribute_values')->insert($data);
        }
    }

    private function slugify(string $text): string
    {
        $url = strtolower(trim($text));
        $url = preg_replace('/[^a-z0-9]+/i', '-', $url);
        return trim($url, '-');
    }
}
