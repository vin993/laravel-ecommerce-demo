<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MatchTurn14Products extends Command
{
    protected $signature = 'turn14:match-products {--limit=1000} {--dry-run}';
    protected $description = 'Auto-match ARI products with Turn14 by manufacturer part number';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $service = new \App\Services\Dropship\Turn14DropshipService();
        $service->ensureMappingTableExists();

        if (!DB::getSchemaBuilder()->hasTable('turn14_sku_mapping')) {
            $this->error('Failed to create turn14_sku_mapping table');
            return Command::FAILURE;
        }

        $this->info('Matching ARI products with Turn14 catalog...');

        $matches = DB::select("
            SELECT
                p.id,
                p.sku as our_sku,
                p.sku as our_mpn,
                t14.brand as our_brand,
                t14.item_id as turn14_item_id,
                t14.part_number as turn14_part_number,
                t14.mfr_part_number as turn14_mpn,
                t14.brand as turn14_brand,
                t14.product_name
            FROM products p
            INNER JOIN turn14_catalog t14
                ON TRIM(UPPER(p.sku)) COLLATE utf8mb4_unicode_ci = TRIM(UPPER(t14.mfr_part_number)) COLLATE utf8mb4_unicode_ci
            LEFT JOIN turn14_sku_mapping existing
                ON p.sku COLLATE utf8mb4_unicode_ci = existing.our_sku COLLATE utf8mb4_unicode_ci
            WHERE existing.our_sku IS NULL
            LIMIT ?
        ", [$limit]);

        if (empty($matches)) {
            $this->warn('No matches found');
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($matches) . ' matches');

        if ($dryRun) {
            $this->table(
                ['Our SKU', 'Our MPN', 'Turn14 Item ID', 'Turn14 MPN', 'Brand'],
                array_map(fn($m) => [
                    $m->our_sku,
                    $m->our_mpn,
                    $m->turn14_item_id,
                    $m->turn14_mpn,
                    $m->turn14_brand
                ], array_slice($matches, 0, 10))
            );
            $this->warn('Dry run - no mappings created');
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($matches));

        foreach ($matches as $match) {
            DB::table('turn14_sku_mapping')->insert([
                'our_sku' => $match->our_sku,
                'turn14_item_id' => $match->turn14_item_id,
                'turn14_part_number' => $match->turn14_part_number,
                'mfr_part_number' => $match->turn14_mpn,
                'product_name' => $match->product_name,
                'brand' => $match->turn14_brand,
                'is_active' => true,
                'notes' => 'Auto-matched by MPN',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Created ' . count($matches) . ' mappings');

        return Command::SUCCESS;
    }
}
