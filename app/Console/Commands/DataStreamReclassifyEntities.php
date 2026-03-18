<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class DataStreamReclassifyEntities extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'datastream:reclassify-entities 
                            {--batch-size=1000 : Number of records to process per batch}
                            {--dry-run : Show what would be reclassified without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Re-classify existing DataStream staging data entities using improved detection';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');

        $this->info('🔄 Starting entity re-classification...');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        try {
            // Get total count of unknown records
            $totalUnknown = DB::table('ari_staging_generic')
                ->where('entity_name', 'unknown')
                ->count();

            if ($totalUnknown === 0) {
                $this->info('✅ No unknown entities found to reclassify');
                return 0;
            }

            $this->info("📊 Found {$totalUnknown} unknown entities to reclassify");
            
            $processed = 0;
            $reclassified = 0;
            $stillUnknown = 0;

            $progressBar = $this->output->createProgressBar($totalUnknown);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% - Reclassified: %message%');

            // Process in batches
            DB::table('ari_staging_generic')
                ->where('entity_name', 'unknown')
                ->orderBy('id')
                ->chunk($batchSize, function ($records) use (&$processed, &$reclassified, &$stillUnknown, $dryRun, $progressBar) {
                    foreach ($records as $record) {
                        $processed++;

                        // Decode the raw_data to get original CSV data
                        $rawData = json_decode($record->raw_data, true);
                        
                        if (!empty($rawData)) {
                            // Use the improved entity classification
                            $newEntityName = $this->classifyEntity($rawData);
                            
                            if ($newEntityName !== 'unknown') {
                                if (!$dryRun) {
                                    DB::table('ari_staging_generic')
                                        ->where('id', $record->id)
                                        ->update(['entity_name' => $newEntityName]);
                                }
                                $reclassified++;
                            } else {
                                $stillUnknown++;
                            }
                        } else {
                            $stillUnknown++;
                        }

                        $progressBar->setMessage("{$reclassified} entities");
                        $progressBar->advance();
                    }
                });

            $progressBar->finish();
            $this->line('');

            // Show results
            $this->info("📈 Re-classification Results:");
            $this->info("   Total processed: {$processed}");
            $this->info("   Successfully reclassified: {$reclassified}");
            $this->info("   Still unknown: {$stillUnknown}");

            if (!$dryRun) {
                // Show final entity counts
                $this->info("\n📊 Updated Entity Counts:");
                $entityCounts = DB::table('ari_staging_generic')
                    ->select('entity_name', DB::raw('count(*) as count'))
                    ->groupBy('entity_name')
                    ->orderBy('count', 'desc')
                    ->get();

                foreach ($entityCounts as $count) {
                    $this->line("   {$count->entity_name}: {$count->count}");
                }
            } else {
                $this->warn("DRY RUN: No changes were made. Use --dry-run=false to apply changes.");
            }

            return 0;

        } catch (Exception $e) {
            $this->error('❌ Re-classification failed: ' . $e->getMessage());
            Log::error('DataStream entity reclassification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Classify entity using improved detection logic
     */
    private function classifyEntity(array $row): string
    {
        // Enhanced detection for DataStream CSV format
        $fields = array_keys($row);
        $fieldsLower = array_map('strtolower', $fields);
        $fieldString = implode('|', $fieldsLower);

        // Check for Makes/Make data
        if (in_array('makeid', $fieldsLower) || 
            in_array('make_id', $fieldsLower) ||
            in_array('makename', $fieldsLower) ||
            in_array('make_name', $fieldsLower) ||
            (count($fieldsLower) <= 3 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'make') !== false)) {
            return 'makes';
        }

        // Check for Models data
        if (in_array('modelid', $fieldsLower) || 
            in_array('model_id', $fieldsLower) ||
            in_array('modelname', $fieldsLower) ||
            in_array('model_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'model') !== false)) {
            return 'models';
        }

        // Check for Years data
        if (in_array('yearid', $fieldsLower) || 
            in_array('year_id', $fieldsLower) ||
            in_array('yearvalue', $fieldsLower) ||
            in_array('year_value', $fieldsLower) ||
            in_array('year', $fieldsLower) ||
            (count($fieldsLower) <= 3 && in_array('id', $fieldsLower) && (strpos($fieldString, 'year') !== false))) {
            return 'years';
        }

        // Check for Brands data
        if (in_array('brandid', $fieldsLower) || 
            in_array('brand_id', $fieldsLower) ||
            in_array('brandname', $fieldsLower) ||
            in_array('brand_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'brand') !== false)) {
            return 'brands';
        }

        // Check for Manufacturers data
        if (in_array('manufacturerid', $fieldsLower) || 
            in_array('manufacturer_id', $fieldsLower) ||
            in_array('manufacturername', $fieldsLower) ||
            in_array('manufacturer_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'manufacturer') !== false)) {
            return 'manufacturers';
        }

        // Check for Distributors data
        if (in_array('distributorid', $fieldsLower) || 
            in_array('distributor_id', $fieldsLower) ||
            in_array('distributorname', $fieldsLower) ||
            in_array('distributor_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'distributor') !== false)) {
            return 'distributors';
        }

        // Check for Attributes data
        if (in_array('attributeid', $fieldsLower) || 
            in_array('attribute_id', $fieldsLower) ||
            in_array('attributename', $fieldsLower) ||
            in_array('attribute_name', $fieldsLower) ||
            in_array('attributevalue', $fieldsLower) ||
            (strpos($fieldString, 'attribute') !== false)) {
            return 'attributes';
        }

        // Check for Groups data
        if (in_array('groupid', $fieldsLower) || 
            in_array('group_id', $fieldsLower) ||
            in_array('groupname', $fieldsLower) ||
            in_array('group_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'group') !== false)) {
            return 'groups';
        }

        // Check for Categories data
        if (in_array('categoryid', $fieldsLower) || 
            in_array('category_id', $fieldsLower) ||
            in_array('categoryname', $fieldsLower) ||
            in_array('category_name', $fieldsLower) ||
            (count($fieldsLower) <= 4 && (in_array('id', $fieldsLower) || in_array('name', $fieldsLower)) && strpos($fieldString, 'category') !== false)) {
            return 'categories';
        }

        // Check for Applications data
        if (in_array('applicationid', $fieldsLower) || 
            in_array('application_id', $fieldsLower) ||
            in_array('applicationname', $fieldsLower) ||
            in_array('application_name', $fieldsLower) ||
            (strpos($fieldString, 'application') !== false)) {
            return 'applications';
        }

        // Check for specific DataStream patterns
        if (isset($row['catalogsid']) && isset($row['partpriceinvid'])) {
            return 'catalogs';
        }

        if (isset($row['partpriceinvid']) || in_array('partpriceinvid', $fieldsLower)) {
            return 'part_price_inv';
        }

        if (isset($row['pagenumber']) || in_array('pagenumber', $fieldsLower)) {
            return 'catalog_pages';
        }

        // Check for Associated Parts
        if (strpos($fieldString, 'associated') !== false || strpos($fieldString, 'part') !== false) {
            return 'associated_parts';
        }

        return 'unknown';
    }
}
