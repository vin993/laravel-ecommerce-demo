<?php

namespace App\Services\DataStream;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OptimizedStagingTransformerService
{
    private int $batchSize = 5000;
    private int $memoryCheckInterval = 10;

    public function __construct()
    {
        // Set memory management settings
        ini_set('memory_limit', '2G');
        ini_set('max_execution_time', 0);
    }

    public function transformAllStagingData(): array
    {
        $results = [];
        
        try {
            Log::info("Starting optimized staging data transformation with batch processing");
            
            // Transform reference data first (order matters due to foreign keys)
            $results['vehicle_types'] = $this->transformVehicleTypesOptimized();
            $results['makes'] = $this->transformMakesOptimized();
            $results['models'] = $this->transformModelsOptimized();
            $results['years'] = $this->transformYearsOptimized();
            $results['engines'] = $this->transformEnginesOptimized();
            $results['manufacturers'] = $this->transformManufacturersOptimized();
            $results['brands'] = $this->transformBrandsOptimized();
            $results['distributors'] = $this->transformDistributorsOptimized();
            $results['attributes'] = $this->transformAttributesOptimized();
            $results['groups'] = $this->transformGroupsOptimized();
            $results['categories'] = $this->transformCategoriesOptimized();
            $results['applications'] = $this->transformApplicationsOptimized();
            
            // Transform main data
            $results['products'] = $this->transformProductsOptimized();
            $results['pricing'] = $this->transformPricingOptimized();
            $results['inventory'] = $this->transformInventoryOptimized();
            $results['images'] = $this->transformImagesOptimized();
            $results['fitment'] = $this->transformFitmentOptimized();
            $results['product_attributes'] = $this->transformProductAttributesOptimized();
            $results['groupings'] = $this->transformGroupingsOptimized();

            Log::info("Successfully transformed all staging data with batch processing");

        } catch (Exception $e) {
            Log::error("Failed to transform staging data: " . $e->getMessage());
            throw $e;
        }

        return $results;
    }

    private function transformVehicleTypesOptimized(): int
    {
        Log::info("Transforming vehicle types from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_vehicle_types',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    // Only process vehicle types from the generic staging table
                    if ($row->entity_name !== 'vehicletypes' && $row->entity_name !== 'vehicle_types') {
                        continue;
                    }
                    
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    
                    // Fallback to direct field access if raw_data is empty
                    $insertData[] = [
                        'vehicle_type_id' => $data['vehicletypeid'] ?? $data['id'] ?? $row->ari_id ?? null,
                        'description' => $data['vehicletypename'] ?? $data['description'] ?? $data['name'] ?? 'Unknown',
                        'update_flag' => $data['updateflag'] ?? $data['update_flag'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                return $insertData;
            },
            'vehicle_type_id'
        );
    }

    private function transformMakesOptimized(): int
    {
        Log::info("Transforming makes from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_makes',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'makes') {
                    $insertData[] = [
                        'make_id' => $data['makeid'] ?? $data['id'] ?? null,
                        'description' => $data['makename'] ?? $data['description'] ?? $data['name'] ?? 'Unknown',
                        'update_flag' => $data['updateflag'] ?? $data['update_flag'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                    }
                }
                return $insertData;
            },
            'make_id'
        );
    }

    private function transformModelsOptimized(): int
    {
        Log::info("Transforming models from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_models',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'models') {
                        $insertData[] = [
                            'model_id' => $data['modelid'] ?? $data['id'] ?? null,
                            'description' => $data['modelname'] ?? $data['description'] ?? $data['name'] ?? 'Unknown',
                            'update_flag' => $data['updateflag'] ?? $data['update_flag'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'model_id'
        );
    }

    private function transformYearsOptimized(): int
    {
        Log::info("Transforming years from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_years',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'years') {
                        $insertData[] = [
                            'year_id' => $data['yearid'] ?? $data['id'] ?? null,
                            'description' => $data['year'] ?? $data['description'] ?? $data['year_value'] ?? '2000',
                            'update_flag' => $data['updateflag'] ?? $data['update_flag'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'year_id'
        );
    }

    private function transformEnginesOptimized(): int
    {
        Log::info("Transforming engines from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_engines',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'engines') {
                        $insertData[] = [
                            'ds_engine_id' => $data['engineid'] ?? $data['id'] ?? null,
                            'name' => $data['enginename'] ?? $data['name'] ?? 'Unknown',
                            'displacement' => $data['displacement'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'ds_engine_id'
        );
    }

    private function transformManufacturersOptimized(): int
    {
        Log::info("Transforming manufacturers from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_partmaster',
            'ds_manufacturers',
            function($batch) {
                $insertData = [];
                $seenIds = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    $manufacturerId = $data['manufacturer_id'] ?? $row->manufacturer_id ?? null;
                    if ($manufacturerId && !in_array($manufacturerId, $seenIds)) {
                        $seenIds[] = $manufacturerId;
                        $insertData[] = [
                            'manufacturer_id' => $manufacturerId,
                            'manufacturer_name' => $data['manufacturername'] ?? 'Unknown',
                            'update_flag' => $data['updateflag'] ?? $data['update_flag'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'manufacturer_id'
        );
    }

    private function transformBrandsOptimized(): int
    {
        Log::info("Transforming brands from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_brands',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'brands') {
                        $insertData[] = [
                            'brand_id' => $data['brandid'] ?? $data['id'] ?? null,
                            'brand_name' => $data['brandname'] ?? $data['brand_name'] ?? $data['name'] ?? 'Unknown',
                            'update_flag' => $data['updateflag'] ?? $data['update_flag'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'brand_id'
        );
    }

    private function transformDistributorsOptimized(): int
    {
        Log::info("Transforming distributors from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_distributor_inventory',
            'ds_distributors',
            function($batch) {
                $insertData = [];
                $seenIds = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    $distributorId = $data['distributor_id'] ?? $row->distributor_id ?? null;
                    if ($distributorId && !in_array($distributorId, $seenIds)) {
                        $seenIds[] = $distributorId;
                        $insertData[] = [
                            'distributor_id' => $distributorId,
                            'description' => $data['distributorname'] ?? $data['description'] ?? 'Unknown',
                            'update_flag' => $data['updateflag'] ?? $data['update_flag'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'distributor_id'
        );
    }

    private function transformAttributesOptimized(): int
    {
        Log::info("Transforming attributes from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_attributes',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'attributes') {
                        $attributeValue = $data['attribute_value'] ?? '';
                        $dataType = 'text';
                        if (is_numeric($attributeValue)) {
                            $dataType = strpos($attributeValue, '.') !== false ? 'decimal' : 'integer';
                        }
                        
                        $insertData[] = [
                            'ds_attribute_id' => $data['attribute_id'] ?? $data['id'] ?? null,
                            'name' => $data['attribute_name'] ?? $data['name'] ?? 'Unknown',
                            'data_type' => $dataType,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'ds_attribute_id'
        );
    }

    private function transformGroupsOptimized(): int
    {
        Log::info("Transforming groups from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_groups',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'groups') {
                        $insertData[] = [
                            'ds_group_id' => $data['group_id'] ?? $data['id'] ?? null,
                            'name' => $data['group_name'] ?? $data['name'] ?? 'Unknown',
                            'parent_group_id' => $data['parent_group_id'] ?? null,
                            'level' => $data['level'] ?? 1,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'ds_group_id'
        );
    }

    private function transformCategoriesOptimized(): int
    {
        Log::info("Transforming categories from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_categories',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'categories') {
                        $insertData[] = [
                            'ds_category_id' => $data['category_id'] ?? $data['id'] ?? null,
                            'name' => $data['category_name'] ?? $data['name'] ?? 'Unknown',
                            'parent_category_id' => $data['parent_category_id'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'ds_category_id'
        );
    }

    private function transformApplicationsOptimized(): int
    {
        Log::info("Transforming applications from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_applications',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'applications') {
                        $insertData[] = [
                            'ds_application_id' => $data['application_id'] ?? $data['id'] ?? null,
                            'ds_make_id' => $data['make_id'] ?? 1,
                            'ds_model_id' => $data['model_id'] ?? 1,
                            'ds_year_id' => $data['year_id'] ?? 1,
                            'ds_engine_id' => $data['engine_id'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return $insertData;
            },
            'ds_application_id'
        );
    }

    private function transformProductsOptimized(): int
    {
        Log::info("Transforming products from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_partmaster',
            'ds_products',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    $insertData[] = [
                        'ds_part_id' => $data['part_id'] ?? $data['id'] ?? $row->part_id ?? null,
                        'part_number' => $data['part_number'] ?? $row->part_number ?? null,
                        'ds_manufacturer_id' => $data['manufacturer_id'] ?? $row->manufacturer_id ?? null,
                        'ds_brand_id' => $data['brandid'] ?? $row->brand_id ?? null,
                        'name' => $data['partname'] ?? $data['name'] ?? $data['item_name'] ?? $row->name ?? $row->item_name ?? 'Unknown',
                        'description' => $data['description'] ?? $data['item_description'] ?? $row->description ?? $row->item_description ?? null,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                return $insertData;
            },
            'ds_part_id'
        );
    }

    private function transformPricingOptimized(): int
    {
        Log::info("Transforming pricing from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_part_price_inv',
            'ds_pricing',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    $insertData[] = [
                        'ds_part_id' => $data['partmaster_id'] ?? $row->partmaster_id ?? $row->part_id ?? null,
                        'ds_distributor_id' => $data['distributor_id'] ?? $row->distributor_id ?? null,
                        'list_price' => $data['list_price'] ?? $data['msrp'] ?? null,
                        'your_price' => $data['your_price'] ?? null,
                        'effective_date' => $data['effective_date'] ?? now(),
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                return array_filter($insertData, function($item) {
                    return $item['ds_part_id'] && $item['ds_distributor_id'];
                });
            },
            ['ds_part_id', 'ds_distributor_id']
        );
    }

    private function transformInventoryOptimized(): int
    {
        Log::info("Transforming inventory from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_distributor_inventory',
            'ds_inventory',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    $insertData[] = [
                        'ds_part_id' => $data['part_id'] ?? $row->part_id ?? null,
                        'ds_distributor_id' => $data['distributor_id'] ?? $row->distributor_id ?? null,
                        'quantity_available' => $data['qty'] ?? $data['quantity_available'] ?? 0,
                        'quantity_on_order' => $data['quantity_on_order'] ?? 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                return array_filter($insertData, function($item) {
                    return $item['ds_part_id'] && $item['ds_distributor_id'];
                });
            },
            ['ds_part_id', 'ds_distributor_id']
        );
    }

    private function transformImagesOptimized(): int
    {
        Log::info("Transforming images from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_images',
            'ds_images',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    $insertData[] = [
                        'ds_part_id' => $data['partmaster_id'] ?? $row->partmaster_id ?? $row->part_id ?? null,
                        'image_url' => $data['image_url'] ?? null,
                        'image_path' => $data['hi_res_image_name'] ?? $data['image_path'] ?? null,
                        'sort_order' => $data['sort_order'] ?? 1,
                        'is_primary' => $data['is_primary'] ?? 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                return array_filter($insertData, function($item) {
                    return $item['ds_part_id'] && ($item['image_url'] || $item['image_path']);
                });
            },
            ['ds_part_id', 'image_url', 'image_path']
        );
    }

    private function transformFitmentOptimized(): int
    {
        Log::info("Transforming fitment from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_fitment',
            'ds_fitment',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    $insertData[] = [
                        'ds_part_id' => $data['part_id'] ?? $row->part_id ?? null,
                        'ds_application_id' => $data['application_id'] ?? null,
                        'ds_make_id' => $data['make_id'] ?? 1,
                        'ds_model_id' => $data['model_id'] ?? 1,
                        'ds_year_id' => $data['year_id'] ?? 1,
                        'ds_engine_id' => $data['engine_id'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }
                return array_filter($insertData, function($item) {
                    return $item['ds_part_id'] && $item['ds_application_id'];
                });
            },
            ['ds_part_id', 'ds_application_id']
        );
    }

    private function transformProductAttributesOptimized(): int
    {
        Log::info("Transforming product attributes from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_generic',
            'ds_product_attributes',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if ($row->entity_name === 'attributes' && (isset($data['part_id']) || $row->part_id)) {
                        $insertData[] = [
                            'ds_part_id' => $data['part_id'] ?? $row->part_id,
                            'ds_attribute_id' => $data['attribute_id'] ?? $data['id'] ?? null,
                            'attribute_value' => $data['attribute_value'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return array_filter($insertData, function($item) {
                    return $item['ds_part_id'] && $item['ds_attribute_id'] && $item['attribute_value'];
                });
            },
            ['ds_part_id', 'ds_attribute_id']
        );
    }

    private function transformGroupingsOptimized(): int
    {
        Log::info("Transforming groupings from staging with batch processing");
        
        return $this->batchTransform(
            'ari_staging_partmaster',
            'ds_groupings',
            function($batch) {
                $insertData = [];
                foreach ($batch as $row) {
                    $data = !empty($row->raw_data) && $row->raw_data !== '{}' ? json_decode($row->raw_data, true) : [];
                    if (isset($data['group_id']) || $row->group_id) {
                        $insertData[] = [
                            'ds_part_id' => $data['part_id'] ?? $data['id'] ?? $row->part_id ?? null,
                            'ds_group_id' => $data['group_id'] ?? $row->group_id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }
                return array_filter($insertData, function($item) {
                    return $item['ds_part_id'] && $item['ds_group_id'];
                });
            },
            ['ds_part_id', 'ds_group_id']
        );
    }

    /**
     * Generic batch transformation method
     */
    private function batchTransform(string $sourceTable, string $targetTable, callable $transformer, $uniqueColumns = null): int
    {
        // Check if tables exist before processing
        try {
            DB::table($sourceTable)->limit(1)->count();
        } catch (Exception $e) {
            Log::warning("Source table {$sourceTable} does not exist, skipping transformation");
            return 0;
        }
        
        try {
            DB::table($targetTable)->limit(1)->count();
        } catch (Exception $e) {
            Log::error("Target table {$targetTable} does not exist, transformation will fail");
            throw new Exception("Target table {$targetTable} does not exist. Please run migrations.");
        }
        
        $totalProcessed = 0;
        $batchCount = 0;
        $offset = 0;

        do {
            try {
                // Get batch from source table
                $batch = DB::table($sourceTable)
                    ->whereNull('processed_at')
                    ->offset($offset)
                    ->limit($this->batchSize)
                    ->get();

                if ($batch->isEmpty()) {
                    break;
                }

                // Transform the batch
                $transformedData = $transformer($batch);
                
                if (!empty($transformedData)) {
                    try {
                        DB::beginTransaction();
                        
                        // Insert using INSERT IGNORE or ON DUPLICATE KEY UPDATE
                        if (is_array($uniqueColumns)) {
                            // Multiple unique columns - use INSERT IGNORE
                            DB::table($targetTable)->insertOrIgnore($transformedData);
                        } elseif ($uniqueColumns) {
                            // Single unique column - use INSERT IGNORE
                            DB::table($targetTable)->insertOrIgnore($transformedData);
                        } else {
                            // No unique constraint
                            DB::table($targetTable)->insert($transformedData);
                        }

                        // Mark source records as processed
                        $sourceIds = $batch->pluck('id');
                        DB::table($sourceTable)
                            ->whereIn('id', $sourceIds)
                            ->update(['processed_at' => now()]);

                        DB::commit();

                        $totalProcessed += count($transformedData);
                        $batchCount++;

                        // Memory management
                        if ($batchCount % $this->memoryCheckInterval == 0) {
                            $memoryUsage = $this->formatBytes(memory_get_usage(true));
                            $peakMemory = $this->formatBytes(memory_get_peak_usage(true));
                            Log::info("Transformed {$totalProcessed} records to {$targetTable}. Memory: {$memoryUsage}, Peak: {$peakMemory}");
                            gc_collect_cycles();
                        }

                } catch (Exception $e) {
                    DB::rollback();
                    Log::error("Failed to transform batch for {$targetTable}: " . $e->getMessage());
                    
                    // Log sample data for debugging
                    if (!empty($transformedData)) {
                        Log::error("Sample transformed data for {$targetTable}: " . json_encode(array_slice($transformedData, 0, 3)));
                    }
                    
                    // After first batch, skip problematic batches instead of failing completely
                    if ($batchCount > 1) {
                        Log::warning("Skipping problematic batch {$batchCount} for {$targetTable}, continuing transformation");
                        $offset += $this->batchSize; // Skip this batch
                        continue; // Continue with next batch
                    } else {
                        // If first batch fails, throw the error
                        throw $e;
                    }
                }
                } else {
                    // No data to transform in this batch, still mark as processed
                    $sourceIds = $batch->pluck('id');
                    DB::table($sourceTable)
                        ->whereIn('id', $sourceIds)
                        ->update(['processed_at' => now()]);
                }

                // Move to next batch only if we processed the full batch size
                if ($batch->count() < $this->batchSize) {
                    break; // No more records
                }
                $offset += $this->batchSize;
                
            } catch (Exception $e) {
                Log::error("Error processing batch {$batchCount} for {$sourceTable} -> {$targetTable}: " . $e->getMessage());
                
                // Skip problematic batch and continue
                $offset += $this->batchSize;
                
                // Don't let one bad batch stop the entire transformation
                if ($batchCount > 0) {
                    Log::warning("Skipping problematic batch, continuing with next batch");
                    continue;
                } else {
                    // If first batch fails, throw the error
                    throw $e;
                }
            }

        } while (true);

        try {
            $finalCount = DB::table($targetTable)->count();
            Log::info("{$targetTable} transformation complete. Total records in target: {$finalCount}");
        } catch (Exception $e) {
            Log::warning("Could not get final count for {$targetTable}: " . $e->getMessage());
            $finalCount = $totalProcessed;
        }
        
        return $finalCount;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }

    public function clearAllDataStreamTables(): void
    {
        Log::info("Clearing all DataStream tables");

        $tables = [
            // Main tables
            'ds_groupings',
            'ds_product_attributes', 
            'ds_fitment',
            'ds_images',
            'ds_inventory',
            'ds_pricing',
            'ds_products',
            
            // Reference tables
            'ds_applications',
            'ds_categories',
            'ds_groups',
            'ds_attributes',
            'ds_distributors',
            'ds_brands',
            'ds_manufacturers',
            'ds_engines',
            'ds_years',
            'ds_models',
            'ds_makes',
            'ds_vehicle_types'
        ];

        foreach ($tables as $table) {
            try {
                DB::table($table)->truncate();
                Log::debug("Cleared table: {$table}");
            } catch (Exception $e) {
                Log::warning("Failed to clear table {$table}: " . $e->getMessage());
            }
        }

        Log::info("Completed clearing DataStream tables");
    }
}
