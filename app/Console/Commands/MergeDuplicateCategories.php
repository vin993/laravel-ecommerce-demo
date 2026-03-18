<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MergeDuplicateCategories extends Command
{
    protected $signature = 'ari:merge-duplicate-categories {--dry-run} {--min-duplicates=2}';
    protected $description = 'Merge duplicate categories and reassign their products';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $minDuplicates = (int) $this->option('min-duplicates');

        $this->info('Merging Duplicate Categories');
        $this->info('Dry run: ' . ($dryRun ? 'Yes' : 'No'));

        try {
            $duplicates = DB::table('category_translations')
                ->select('name', DB::raw('COUNT(*) as count'))
                ->groupBy('name')
                ->having('count', '>', $minDuplicates)
                ->orderBy('count', 'desc')
                ->get();

            if ($duplicates->isEmpty()) {
                $this->info('No duplicate categories found');
                return Command::SUCCESS;
            }

            $this->info("Found {$duplicates->count()} duplicate category names");

            $totalMerged = 0;
            $totalReassigned = 0;

            foreach ($duplicates as $duplicate) {
                $result = $this->mergeCategoryDuplicates($duplicate->name, $dryRun);
                $totalMerged += $result['merged'];
                $totalReassigned += $result['reassigned'];

                $this->line("'{$duplicate->name}': {$duplicate->count} duplicates -> merged {$result['merged']}, reassigned {$result['reassigned']} products");
            }

            $this->info("Total categories merged: {$totalMerged}");
            $this->info("Total products reassigned: {$totalReassigned}");

            return Command::SUCCESS;

        } catch (Exception $e) {
            $this->error('Fatal: ' . $e->getMessage());
            Log::error('Merge duplicates fatal', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }

    private function mergeCategoryDuplicates(string $name, bool $dryRun): array
    {
        $categoryTranslations = DB::table('category_translations')
            ->where('name', $name)
            ->orderBy('category_id')
            ->get();

        if ($categoryTranslations->count() <= 1) {
            return ['merged' => 0, 'reassigned' => 0];
        }

        $keepCategoryId = $categoryTranslations->first()->category_id;
        $duplicateIds = $categoryTranslations->skip(1)->pluck('category_id')->toArray();

        $reassignedProducts = 0;
        $mergedCategories = 0;

        if (!$dryRun) {
            DB::beginTransaction();
        }

        try {
            foreach ($duplicateIds as $duplicateId) {
                $productCount = DB::table('product_categories')
                    ->where('category_id', $duplicateId)
                    ->count();

                if ($productCount > 0) {
                    if (!$dryRun) {
                        // Get products that don't already have the keep category
                        $productsToReassign = DB::table('product_categories as pc1')
                            ->where('pc1.category_id', $duplicateId)
                            ->whereNotExists(function($query) use ($keepCategoryId) {
                                $query->select(DB::raw(1))
                                    ->from('product_categories as pc2')
                                    ->whereRaw('pc2.product_id = pc1.product_id')
                                    ->where('pc2.category_id', $keepCategoryId);
                            })
                            ->pluck('product_id');

                        // Update in chunks to avoid MySQL limitations
                        $productsToReassign->chunk(1000)->each(function($chunk) use ($duplicateId, $keepCategoryId) {
                            DB::table('product_categories')
                                ->where('category_id', $duplicateId)
                                ->whereIn('product_id', $chunk->toArray())
                                ->update(['category_id' => $keepCategoryId]);
                        });

                        // Remove any remaining duplicate assignments
                        DB::table('product_categories')
                            ->where('category_id', $duplicateId)
                            ->delete();
                    }
                    $reassignedProducts += $productCount;
                }

                if (!$dryRun) {
                    // Delete the duplicate category and its translation
                    DB::table('category_translations')->where('category_id', $duplicateId)->delete();
                    DB::table('categories')->where('id', $duplicateId)->delete();
                }
                $mergedCategories++;
            }

            if (!$dryRun) {
                DB::commit();
            }

        } catch (Exception $e) {
            if (!$dryRun) {
                DB::rollback();
            }
            throw $e;
        }

        return [
            'merged' => $mergedCategories,
            'reassigned' => $reassignedProducts
        ];
    }
}