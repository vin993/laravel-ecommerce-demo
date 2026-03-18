<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixProductDescriptions extends Command
{
    protected $signature = 'ari:fix-descriptions {--batch=5000 : Number of records per batch} {--skip=0 : Skip first N records} {--dry-run : Preview without making changes}';

    protected $description = 'Fix mismatched descriptions by syncing from product_flat to product_attribute_values';

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $isDryRun = $this->option('dry-run');

        $descriptionAttributeId = DB::table('attributes')
            ->where('code', 'description')
            ->value('id');

        if (!$descriptionAttributeId) {
            $this->error('Description attribute not found');
            return 1;
        }

        $this->info("Description attribute ID: {$descriptionAttributeId}");

        $totalCount = DB::table('product_attribute_values as pav')
            ->join('attributes as a', 'pav.attribute_id', '=', 'a.id')
            ->join('product_flat as pf', 'pav.product_id', '=', 'pf.product_id')
            ->where('a.code', 'description')
            ->whereRaw('pav.text_value != pf.description')
            ->whereRaw('LENGTH(pav.text_value) > 0')
            ->whereRaw('LENGTH(pf.description) > 0')
            ->count();

        $this->info("Total products with mismatched descriptions: {$totalCount}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');

            $sample = DB::table('product_attribute_values as pav')
                ->join('product_flat as pf', 'pav.product_id', '=', 'pf.product_id')
                ->join('products as p', 'pav.product_id', '=', 'p.id')
                ->where('pav.attribute_id', $descriptionAttributeId)
                ->whereRaw('pav.text_value != pf.description')
                ->select('p.sku', 'pf.name',
                    DB::raw('SUBSTRING(pav.text_value, 1, 100) as wrong_desc'),
                    DB::raw('SUBSTRING(pf.description, 1, 100) as correct_desc'))
                ->limit(5)
                ->get();

            $this->table(['SKU', 'Name', 'Wrong Desc (first 100)', 'Correct Desc (first 100)'],
                $sample->map(fn($s) => [$s->sku, $s->name, $s->wrong_desc, $s->correct_desc]));

            return 0;
        }

        $processed = 0;
        $updated = 0;

        $this->info("Starting sync from offset {$skip}...");

        $bar = $this->output->createProgressBar($totalCount - $skip);
        $bar->start();

        while ($processed < $totalCount - $skip) {
            $mismatched = DB::table('product_attribute_values as pav')
                ->join('product_flat as pf', 'pav.product_id', '=', 'pf.product_id')
                ->where('pav.attribute_id', $descriptionAttributeId)
                ->whereRaw('pav.text_value != pf.description')
                ->select('pav.id as pav_id', 'pf.description', 'pav.product_id')
                ->skip($skip + $processed)
                ->take($batch)
                ->get();

            if ($mismatched->isEmpty()) {
                break;
            }

            foreach ($mismatched as $item) {
                DB::table('product_attribute_values')
                    ->where('id', $item->pav_id)
                    ->update([
                        'text_value' => $item->description
                    ]);

                $updated++;
                $bar->advance();
            }

            $processed += $mismatched->count();

            if ($processed % 10000 === 0) {
                $this->newLine();
                $this->info("Processed: {$processed} | Updated: {$updated}");
                $bar->display();
            }

            gc_collect_cycles();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Completed!");
        $this->info("Total processed: {$processed}");
        $this->info("Total updated: {$updated}");

        return 0;
    }
}
