<?php

namespace App\Console\Commands\VariantConversion;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyVariants extends Command
{
    protected $signature = 'ari:verify-variants';
    protected $description = 'Verify variant conversion status and show what needs to be done';

    public function handle()
    {
        $this->info('Analyzing Variant Conversion Status...');
        $this->newLine();

        $variantGroupsCount = DB::table('ds_variant_groups')->count();
        if ($variantGroupsCount === 0) {
            $this->error('No variant groups found. Run: php artisan ari:rebuild-variant-groups-from-partgroupings');
            return Command::FAILURE;
        }

        $uniqueGroups = DB::table('ds_variant_groups')
            ->distinct('variant_group_id')
            ->count('variant_group_id');

        $this->info("Variant Groups in ds_variant_groups:");
        $this->line("  Total variant products: " . number_format($variantGroupsCount));
        $this->line("  Unique groups: " . number_format($uniqueGroups));
        $this->newLine();

        $totalProducts = DB::table('products')->count();
        $simpleProducts = DB::table('products')->where('type', 'simple')->count();
        $configurableProducts = DB::table('products')->where('type', 'configurable')->count();

        $this->info("Products Table:");
        $this->line("  Total products: " . number_format($totalProducts));
        $this->line("  Simple products: " . number_format($simpleProducts));
        $this->line("  Configurable products: " . number_format($configurableProducts));
        $this->newLine();

        $variantChildren = DB::table('products')
            ->where('type', 'simple')
            ->whereNotNull('parent_id')
            ->count();

        $standaloneSimple = DB::table('products')
            ->where('type', 'simple')
            ->whereNull('parent_id')
            ->count();

        $this->info("Simple Products Breakdown:");
        $this->line("  Linked to parents (variant children): " . number_format($variantChildren));
        $this->line("  Standalone (no parent): " . number_format($standaloneSimple));
        $this->newLine();

        $groupsWithParents = DB::table('ds_variant_groups as vg')
            ->join('products as p', 'p.sku', '=', DB::raw("CONCAT(vg.base_sku, '-PARENT')"))
            ->where('p.type', 'configurable')
            ->distinct('vg.variant_group_id')
            ->count('vg.variant_group_id');

        $groupsWithoutParents = $uniqueGroups - $groupsWithParents;

        $this->info("Configurable Parents Status:");
        $this->line("  Groups with configurable parents: " . number_format($groupsWithParents));
        $this->line("  Groups missing configurable parents: " . number_format($groupsWithoutParents));
        $this->newLine();

        $variantProductsInDb = DB::table('ds_variant_groups as vg')
            ->join('products as p', 'p.sku', '=', 'vg.partmaster_id')
            ->count();

        $variantProductsLinked = DB::table('ds_variant_groups as vg')
            ->join('products as p', 'p.sku', '=', 'vg.partmaster_id')
            ->whereNotNull('p.parent_id')
            ->count();

        $variantProductsNotLinked = $variantProductsInDb - $variantProductsLinked;

        $this->info("Variant Products Status:");
        $this->line("  Variant products in database: " . number_format($variantProductsInDb));
        $this->line("  Already linked to parents: " . number_format($variantProductsLinked));
        $this->line("  Need linking: " . number_format($variantProductsNotLinked));
        $this->newLine();

        if ($groupsWithoutParents > 0) {
            $this->warn("CONVERSION NEEDED:");
            $this->line("  Groups needing conversion: " . number_format($groupsWithoutParents));
            $this->line("  Products needing linking: " . number_format($variantProductsNotLinked));
            $this->newLine();
            $this->info("Next step: Run conversion command:");
            $this->line("  sudo -u www-data php artisan ari:convert-to-configurable --batch=100");
        } else {
            $this->info("All variant groups have configurable parents!");

            if ($variantProductsNotLinked > 0) {
                $this->warn("However, {$variantProductsNotLinked} products need linking to parents");
                $this->line("Run: sudo -u www-data php artisan ari:convert-to-configurable --batch=100");
            } else {
                $this->info("All variant products are properly linked!");
            }
        }

        $sampleGroup = DB::table('ds_variant_groups')
            ->select('variant_group_id', 'base_name')
            ->groupBy('variant_group_id', 'base_name')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByRaw('COUNT(*) DESC')
            ->first();

        if ($sampleGroup) {
            $this->newLine();
            $this->info("Sample Group (ID: {$sampleGroup->variant_group_id}):");
            $this->line("  Product: {$sampleGroup->base_name}");

            $variants = DB::table('ds_variant_groups as vg')
                ->leftJoin('products as p', 'p.sku', '=', 'vg.partmaster_id')
                ->where('vg.variant_group_id', $sampleGroup->variant_group_id)
                ->select('vg.partmaster_id', 'p.id', 'p.type', 'p.parent_id')
                ->limit(5)
                ->get();

            foreach ($variants as $variant) {
                $status = $variant->id ?
                    ($variant->parent_id ? 'Linked' : 'Not Linked') :
                    'Missing from DB';
                $this->line("    SKU: {$variant->partmaster_id} - {$status}");
            }
        }

        return Command::SUCCESS;
    }
}
