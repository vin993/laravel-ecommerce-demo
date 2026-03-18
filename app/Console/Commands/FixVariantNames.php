<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixVariantNames extends Command
{
    protected $signature = 'ari:fix-variant-names {--batch=1000 : Number of records per batch} {--dry-run : Preview without making changes}';

    protected $description = 'Fix variant products that have wrong names - sync from parent product_flat to variant tables';

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $isDryRun = $this->option('dry-run');

        $nameAttributeId = DB::table('attributes')->where('code', 'name')->value('id');
        $descAttributeId = DB::table('attributes')->where('code', 'description')->value('id');

        if (!$nameAttributeId || !$descAttributeId) {
            $this->error('Name or Description attribute not found');
            return 1;
        }

        $this->info("Name attribute ID: {$nameAttributeId}");
        $this->info("Description attribute ID: {$descAttributeId}");

        $totalVariants = DB::table('products')->whereNotNull('parent_id')->count();
        $this->info("Total variant products: {$totalVariants}");

        $mismatchedCount = DB::table('products as p')
            ->join('product_flat as pf', 'p.id', '=', 'pf.product_id')
            ->join('products as parent', 'p.parent_id', '=', 'parent.id')
            ->join('product_flat as parent_flat', 'parent.id', '=', 'parent_flat.product_id')
            ->whereNotNull('p.parent_id')
            ->whereRaw('LOWER(pf.name) NOT LIKE CONCAT("%", LOWER(SUBSTRING_INDEX(parent_flat.name, " ", 1)), "%")')
            ->whereRaw('LOWER(pf.name) NOT LIKE CONCAT("%", LOWER(SUBSTRING_INDEX(parent_flat.name, " ", 2)), "%")')
            ->count();

        $this->info("Variants with completely unrelated names (corrupted): {$mismatchedCount}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - Only fixing completely unrelated variant names');
            $this->info('Variants like "Steel Front Sprocket - 13T" will NOT be changed (they are correct)');
            $this->info('Only fixing variants with totally wrong product data');

            $sample = DB::table('products as p')
                ->join('product_flat as pf', 'p.id', '=', 'pf.product_id')
                ->join('products as parent', 'p.parent_id', '=', 'parent.id')
                ->join('product_flat as parent_flat', 'parent.id', '=', 'parent_flat.product_id')
                ->whereNotNull('p.parent_id')
                ->whereRaw('LOWER(pf.name) NOT LIKE CONCAT("%", LOWER(SUBSTRING_INDEX(parent_flat.name, " ", 1)), "%")')
                ->whereRaw('LOWER(pf.name) NOT LIKE CONCAT("%", LOWER(SUBSTRING_INDEX(parent_flat.name, " ", 2)), "%")')
                ->select('p.sku', 'pf.name as wrong_name', 'parent_flat.name as correct_name')
                ->limit(10)
                ->get();

            $this->table(['Variant SKU', 'Wrong Name (Corrupted)', 'Correct Name (from parent)'],
                $sample->map(fn($s) => [$s->sku, $s->wrong_name, $s->correct_name]));

            return 0;
        }

        $this->info('Starting fix...');
        $bar = $this->output->createProgressBar($mismatchedCount);
        $bar->start();

        $processed = 0;
        $updatedFlat = 0;
        $updatedAttrName = 0;
        $updatedAttrDesc = 0;

        while ($processed < $mismatchedCount) {
            $mismatched = DB::table('products as p')
                ->join('product_flat as pf', 'p.id', '=', 'pf.product_id')
                ->join('products as parent', 'p.parent_id', '=', 'parent.id')
                ->join('product_flat as parent_flat', 'parent.id', '=', 'parent_flat.product_id')
                ->whereNotNull('p.parent_id')
                ->whereRaw('LOWER(pf.name) NOT LIKE CONCAT("%", LOWER(SUBSTRING_INDEX(parent_flat.name, " ", 1)), "%")')
                ->whereRaw('LOWER(pf.name) NOT LIKE CONCAT("%", LOWER(SUBSTRING_INDEX(parent_flat.name, " ", 2)), "%")')
                ->select('p.id as product_id', 'p.sku', 'parent_flat.name as correct_name', 'parent_flat.description as correct_desc')
                ->skip($processed)
                ->take($batch)
                ->get();

            if ($mismatched->isEmpty()) {
                break;
            }

            foreach ($mismatched as $item) {
                DB::table('product_flat')
                    ->where('product_id', $item->product_id)
                    ->update(['name' => $item->correct_name, 'description' => $item->correct_desc]);
                $updatedFlat++;

                DB::table('product_attribute_values')
                    ->where('product_id', $item->product_id)
                    ->where('attribute_id', $nameAttributeId)
                    ->update(['text_value' => $item->correct_name]);
                $updatedAttrName++;

                DB::table('product_attribute_values')
                    ->where('product_id', $item->product_id)
                    ->where('attribute_id', $descAttributeId)
                    ->update(['text_value' => $item->correct_desc]);
                $updatedAttrDesc++;

                $bar->advance();
            }

            $processed += $mismatched->count();

            if ($processed % 1000 === 0) {
                $this->newLine();
                $this->info("Processed: {$processed}");
                $bar->display();
            }

            gc_collect_cycles();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Completed!');
        $this->info("Updated product_flat: {$updatedFlat}");
        $this->info("Updated name attributes: {$updatedAttrName}");
        $this->info("Updated description attributes: {$updatedAttrDesc}");

        return 0;
    }
}
