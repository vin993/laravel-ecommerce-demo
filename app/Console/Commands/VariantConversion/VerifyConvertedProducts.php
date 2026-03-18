<?php

namespace App\Console\Commands\VariantConversion;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyConvertedProducts extends Command
{
    protected $signature = 'ari:verify-converted-products
                            {--limit=20 : Number of groups to show}
                            {--group-id= : Check specific group ID}
                            {--sku= : Check specific SKU}';

    protected $description = 'Verify which products have been successfully converted to configurable';

    public function handle()
    {
        $limit = (int) $this->option('limit');
        $groupId = $this->option('group-id');
        $sku = $this->option('sku');

        if ($sku) {
            return $this->verifySku($sku);
        }

        if ($groupId) {
            return $this->verifyGroup($groupId);
        }

        $this->info('Checking Converted Products Status...');
        $this->newLine();

        $totalConfigurables = DB::table('products')
            ->where('type', 'configurable')
            ->where('sku', 'LIKE', '%-PARENT')
            ->count();

        $totalVariantChildren = DB::table('products')
            ->where('type', 'simple')
            ->whereNotNull('parent_id')
            ->count();

        $this->info("Conversion Summary:");
        $this->line("  Total configurable parents created: " . number_format($totalConfigurables));
        $this->line("  Total variant children linked: " . number_format($totalVariantChildren));
        $this->newLine();

        $this->info("Sample Converted Products (showing {$limit}):");
        $this->newLine();

        $convertedGroups = DB::table('products as parent')
            ->join('products as child', 'child.parent_id', '=', 'parent.id')
            ->where('parent.type', 'configurable')
            ->where('parent.sku', 'LIKE', '%-PARENT')
            ->select(
                'parent.id as parent_id',
                'parent.sku as parent_sku',
                DB::raw('COUNT(child.id) as variant_count')
            )
            ->groupBy('parent.id', 'parent.sku')
            ->orderBy('parent.id', 'desc')
            ->limit($limit)
            ->get();

        foreach ($convertedGroups as $group) {
            $parentName = DB::table('product_attribute_values')
                ->where('product_id', $group->parent_id)
                ->where('attribute_id', 2)
                ->value('text_value');

            $this->line("Group: {$parentName}");
            $this->line("  Parent SKU: {$group->parent_sku}");
            $this->line("  Variants: {$group->variant_count}");

            $children = DB::table('products')
                ->where('parent_id', $group->parent_id)
                ->select('id', 'sku')
                ->limit(5)
                ->get();

            foreach ($children as $child) {
                $this->line("    - {$child->sku}");
            }

            if ($group->variant_count > 5) {
                $this->line("    ... and " . ($group->variant_count - 5) . " more");
            }

            $this->newLine();
        }

        $this->info("Commands to verify specific products:");
        $this->line("  sudo -u www-data php artisan ari:verify-converted-products --sku=YOUR_SKU");
        $this->line("  sudo -u www-data php artisan ari:verify-converted-products --group-id=GROUP_ID");

        return Command::SUCCESS;
    }

    private function verifySku(string $sku): int
    {
        $this->info("Checking SKU: {$sku}");
        $this->newLine();

        $product = DB::table('products')
            ->where('sku', $sku)
            ->first();

        if (!$product) {
            $this->error("Product not found: {$sku}");
            return Command::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $product->id],
                ['SKU', $product->sku],
                ['Type', $product->type],
                ['Parent ID', $product->parent_id ?? 'NULL'],
            ]
        );

        if ($product->parent_id) {
            $parent = DB::table('products')
                ->where('id', $product->parent_id)
                ->first();

            if ($parent) {
                $parentName = DB::table('product_attribute_values')
                    ->where('product_id', $parent->id)
                    ->where('attribute_id', 2)
                    ->value('text_value');

                $this->newLine();
                $this->info("Parent Product:");
                $this->line("  ID: {$parent->id}");
                $this->line("  SKU: {$parent->sku}");
                $this->line("  Name: {$parentName}");
                $this->line("  Type: {$parent->type}");

                $siblingCount = DB::table('products')
                    ->where('parent_id', $parent->id)
                    ->count();

                $this->newLine();
                $this->info("Total variants in this group: {$siblingCount}");

                $siblings = DB::table('products')
                    ->where('parent_id', $parent->id)
                    ->select('sku')
                    ->limit(10)
                    ->get();

                $this->newLine();
                $this->info("Sibling variants (up to 10):");
                foreach ($siblings as $sibling) {
                    $this->line("  - {$sibling->sku}");
                }
            }
        } else {
            if ($product->type === 'configurable') {
                $childCount = DB::table('products')
                    ->where('parent_id', $product->id)
                    ->count();

                $this->newLine();
                $this->info("This is a configurable parent with {$childCount} variants:");

                $children = DB::table('products')
                    ->where('parent_id', $product->id)
                    ->select('sku')
                    ->limit(10)
                    ->get();

                foreach ($children as $child) {
                    $this->line("  - {$child->sku}");
                }
            } else {
                $this->warn("This product is standalone (not part of any variant group)");
            }
        }

        $variantGroup = DB::table('ds_variant_groups')
            ->where('partmaster_id', $sku)
            ->first();

        if ($variantGroup) {
            $this->newLine();
            $this->info("Variant Group Info:");
            $this->line("  Group ID: {$variantGroup->variant_group_id}");
            $this->line("  Base SKU: {$variantGroup->base_sku}");
            $this->line("  Base Name: {$variantGroup->base_name}");
        }

        return Command::SUCCESS;
    }

    private function verifyGroup(string $groupId): int
    {
        $this->info("Checking Variant Group: {$groupId}");
        $this->newLine();

        $groupInfo = DB::table('ds_variant_groups')
            ->where('variant_group_id', $groupId)
            ->select('base_name', 'base_sku')
            ->first();

        if (!$groupInfo) {
            $this->error("Group ID {$groupId} not found in ds_variant_groups");
            return Command::FAILURE;
        }

        $this->info("Group: {$groupInfo->base_name}");
        $this->line("  Base SKU: {$groupInfo->base_sku}");
        $this->newLine();

        $parentSku = $groupInfo->base_sku . '-PARENT';
        $parent = DB::table('products')
            ->where('sku', $parentSku)
            ->where('type', 'configurable')
            ->first();

        if ($parent) {
            $this->info("Status: CONVERTED");
            $this->line("  Parent ID: {$parent->id}");
            $this->line("  Parent SKU: {$parent->sku}");

            $childCount = DB::table('products')
                ->where('parent_id', $parent->id)
                ->count();

            $this->line("  Variants linked: {$childCount}");
            $this->newLine();

            $this->info("Variant Products:");
            $children = DB::table('products')
                ->where('parent_id', $parent->id)
                ->select('id', 'sku', 'parent_id')
                ->get();

            foreach ($children as $child) {
                $this->line("  - {$child->sku} (ID: {$child->id}, Parent: {$child->parent_id})");
            }
        } else {
            $this->warn("Status: NOT CONVERTED");
            $this->line("  No configurable parent found");
            $this->newLine();

            $variants = DB::table('ds_variant_groups as vg')
                ->leftJoin('products as p', 'p.sku', '=', 'vg.partmaster_id')
                ->where('vg.variant_group_id', $groupId)
                ->select('vg.partmaster_id', 'p.id', 'p.type', 'p.parent_id')
                ->get();

            $this->info("Variant Products in Group (not yet converted):");
            foreach ($variants as $variant) {
                if ($variant->id) {
                    $status = $variant->parent_id ? 'Has parent' : 'No parent';
                    $this->line("  - {$variant->partmaster_id} (ID: {$variant->id}, {$status})");
                } else {
                    $this->line("  - {$variant->partmaster_id} (NOT IN DATABASE)");
                }
            }
        }

        return Command::SUCCESS;
    }
}
