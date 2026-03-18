<?php

namespace App\Services\DataStream;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StagingTransformerService
{
    public function __construct()
    {
        // Constructor
    }

    public function transformAllStagingData(): array
    {
        $results = [];
        
        try {
            DB::beginTransaction();

            // Transform reference data first (order matters due to foreign keys)
            $results['vehicle_types'] = $this->transformVehicleTypes();
            $results['makes'] = $this->transformMakes();
            $results['models'] = $this->transformModels();
            $results['years'] = $this->transformYears();
            $results['engines'] = $this->transformEngines();
            $results['manufacturers'] = $this->transformManufacturers();
            $results['brands'] = $this->transformBrands();
            $results['distributors'] = $this->transformDistributors();
            $results['attributes'] = $this->transformAttributes();
            $results['groups'] = $this->transformGroups();
            $results['categories'] = $this->transformCategories();
            $results['applications'] = $this->transformApplications();
            
            // Transform main data
            $results['products'] = $this->transformProducts();
            $results['pricing'] = $this->transformPricing();
            $results['inventory'] = $this->transformInventory();
            $results['images'] = $this->transformImages();
            $results['fitment'] = $this->transformFitment();
            $results['product_attributes'] = $this->transformProductAttributes();
            $results['groupings'] = $this->transformGroupings();

            DB::commit();
            Log::info("Successfully transformed all staging data");

        } catch (Exception $e) {
            DB::rollback();
            Log::error("Failed to transform staging data: " . $e->getMessage());
            throw $e;
        }

        return $results;
    }

    public function transformVehicleTypes(): int
    {
        Log::info("Transforming vehicle types from staging");

        $sql = "
            INSERT INTO ds_vehicle_types (ds_vehicle_type_id, name, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(vehicletypeid, id) as ds_vehicle_type_id,
                COALESCE(vehicletypename, name, 'Unknown') as name,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_vehicle_types_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_vehicle_types d 
                WHERE d.ds_vehicle_type_id = COALESCE(s.vehicletypeid, s.id)
            )
            AND processed_at IS NULL
        ";

        $inserted = DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_vehicle_types_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_vehicle_types')->count();
        Log::info("Vehicle types transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformMakes(): int
    {
        Log::info("Transforming makes from staging");

        $sql = "
            INSERT INTO ds_makes (ds_make_id, ds_vehicle_type_id, name, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.makeid, s.id) as ds_make_id,
                COALESCE(s.vehicletypeid, 1) as ds_vehicle_type_id,
                COALESCE(s.makename, s.name, 'Unknown') as name,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_makes_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_makes d 
                WHERE d.ds_make_id = COALESCE(s.makeid, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_makes_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_makes')->count();
        Log::info("Makes transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformModels(): int
    {
        Log::info("Transforming models from staging");

        $sql = "
            INSERT INTO ds_models (ds_model_id, ds_make_id, name, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.modelid, s.id) as ds_model_id,
                COALESCE(s.makeid, 1) as ds_make_id,
                COALESCE(s.modelname, s.name, 'Unknown') as name,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_models_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_models d 
                WHERE d.ds_model_id = COALESCE(s.modelid, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_models_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_models')->count();
        Log::info("Models transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformYears(): int
    {
        Log::info("Transforming years from staging");

        $sql = "
            INSERT INTO ds_years (ds_year_id, year_value, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.yearid, s.id) as ds_year_id,
                COALESCE(s.year, s.year_value, 2000) as year_value,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_years_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_years d 
                WHERE d.ds_year_id = COALESCE(s.yearid, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_years_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_years')->count();
        Log::info("Years transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformEngines(): int
    {
        Log::info("Transforming engines from staging");

        $sql = "
            INSERT INTO ds_engines (ds_engine_id, name, displacement, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.engineid, s.id) as ds_engine_id,
                COALESCE(s.enginename, s.name, 'Unknown') as name,
                s.displacement,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_engines_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_engines d 
                WHERE d.ds_engine_id = COALESCE(s.engineid, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_engines_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_engines')->count();
        Log::info("Engines transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformManufacturers(): int
    {
        Log::info("Transforming manufacturers from staging");

        // Extract unique manufacturers from parts staging
        $sql = "
            INSERT INTO ds_manufacturers (ds_manufacturer_id, name, created_at, updated_at)
            SELECT DISTINCT 
                s.manufacturer_id as ds_manufacturer_id,
                COALESCE(s.manufacturername, 'Unknown') as name,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_parts_staging s
            WHERE s.manufacturer_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_manufacturers d 
                WHERE d.ds_manufacturer_id = s.manufacturer_id
            )
        ";

        DB::statement($sql);

        $count = DB::table('ds_manufacturers')->count();
        Log::info("Manufacturers transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformBrands(): int
    {
        Log::info("Transforming brands from staging");

        // Extract unique brands from parts staging
        $sql = "
            INSERT INTO ds_brands (ds_brand_id, name, created_at, updated_at)
            SELECT DISTINCT 
                s.brandid as ds_brand_id,
                COALESCE(s.brandname, 'Unknown') as name,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_parts_staging s
            WHERE s.brandid IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_brands d 
                WHERE d.ds_brand_id = s.brandid
            )
        ";

        DB::statement($sql);

        $count = DB::table('ds_brands')->count();
        Log::info("Brands transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformDistributors(): int
    {
        Log::info("Transforming distributors from staging");

        // Extract unique distributors from inventory staging
        $sql = "
            INSERT INTO ds_distributors (ds_distributor_id, name, created_at, updated_at)
            SELECT DISTINCT 
                s.distributor_id as ds_distributor_id,
                COALESCE(s.distributorname, 'Unknown') as name,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_inventories_staging s
            WHERE s.distributor_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_distributors d 
                WHERE d.ds_distributor_id = s.distributor_id
            )
        ";

        DB::statement($sql);

        $count = DB::table('ds_distributors')->count();
        Log::info("Distributors transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformAttributes(): int
    {
        Log::info("Transforming attributes from staging");

        $sql = "
            INSERT INTO ds_attributes (ds_attribute_id, name, data_type, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.attribute_id, s.id) as ds_attribute_id,
                COALESCE(s.attribute_name, s.name, 'Unknown') as name,
                CASE 
                    WHEN s.attribute_value REGEXP '^[0-9]+$' THEN 'integer'
                    WHEN s.attribute_value REGEXP '^[0-9]*\\.[0-9]+$' THEN 'decimal'
                    ELSE 'text'
                END as data_type,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_attributes_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_attributes d 
                WHERE d.ds_attribute_id = COALESCE(s.attribute_id, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_attributes_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_attributes')->count();
        Log::info("Attributes transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformGroups(): int
    {
        Log::info("Transforming groups from staging");

        $sql = "
            INSERT INTO ds_groups (ds_group_id, name, parent_group_id, level, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.group_id, s.id) as ds_group_id,
                COALESCE(s.group_name, s.name, 'Unknown') as name,
                s.parent_group_id,
                COALESCE(s.level, 1) as level,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_groups_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_groups d 
                WHERE d.ds_group_id = COALESCE(s.group_id, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_groups_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_groups')->count();
        Log::info("Groups transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformCategories(): int
    {
        Log::info("Transforming categories from staging");

        $sql = "
            INSERT INTO ds_categories (ds_category_id, name, parent_category_id, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.category_id, s.id) as ds_category_id,
                COALESCE(s.category_name, s.name, 'Unknown') as name,
                s.parent_category_id,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_categories_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_categories d 
                WHERE d.ds_category_id = COALESCE(s.category_id, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_categories_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_categories')->count();
        Log::info("Categories transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformApplications(): int
    {
        Log::info("Transforming applications from staging");

        $sql = "
            INSERT INTO ds_applications (ds_application_id, ds_make_id, ds_model_id, ds_year_id, ds_engine_id, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.application_id, s.id) as ds_application_id,
                COALESCE(s.make_id, 1) as ds_make_id,
                COALESCE(s.model_id, 1) as ds_model_id,
                COALESCE(s.year_id, 1) as ds_year_id,
                s.engine_id as ds_engine_id,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_applications_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_applications d 
                WHERE d.ds_application_id = COALESCE(s.application_id, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_applications_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_applications')->count();
        Log::info("Applications transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformProducts(): int
    {
        Log::info("Transforming products from staging");

        $sql = "
            INSERT INTO ds_products (ds_part_id, part_number, ds_manufacturer_id, ds_brand_id, name, description, created_at, updated_at)
            SELECT DISTINCT 
                COALESCE(s.part_id, s.id) as ds_part_id,
                s.part_number,
                s.manufacturer_id as ds_manufacturer_id,
                s.brandid as ds_brand_id,
                COALESCE(s.partname, s.name, 'Unknown') as name,
                s.description,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_parts_staging s
            WHERE NOT EXISTS (
                SELECT 1 FROM ds_products d 
                WHERE d.ds_part_id = COALESCE(s.part_id, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_parts_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_products')->count();
        Log::info("Products transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformPricing(): int
    {
        Log::info("Transforming pricing from staging");

        $sql = "
            INSERT INTO ds_pricing (ds_part_id, ds_distributor_id, list_price, your_price, effective_date, created_at, updated_at)
            SELECT DISTINCT 
                s.part_id as ds_part_id,
                s.distributor_id as ds_distributor_id,
                s.list_price,
                s.your_price,
                COALESCE(s.effective_date, NOW()) as effective_date,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_pricing_staging s
            WHERE s.part_id IS NOT NULL
            AND s.distributor_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_pricing d 
                WHERE d.ds_part_id = s.part_id
                AND d.ds_distributor_id = s.distributor_id
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_pricing_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_pricing')->count();
        Log::info("Pricing transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformInventory(): int
    {
        Log::info("Transforming inventory from staging");

        $sql = "
            INSERT INTO ds_inventory (ds_part_id, ds_distributor_id, quantity_available, quantity_on_order, created_at, updated_at)
            SELECT DISTINCT 
                s.part_id as ds_part_id,
                s.distributor_id as ds_distributor_id,
                COALESCE(s.quantity_available, 0) as quantity_available,
                COALESCE(s.quantity_on_order, 0) as quantity_on_order,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_inventories_staging s
            WHERE s.part_id IS NOT NULL
            AND s.distributor_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_inventory d 
                WHERE d.ds_part_id = s.part_id
                AND d.ds_distributor_id = s.distributor_id
            )
            AND processed_at IS NULL
            ON DUPLICATE KEY UPDATE
                quantity_available = VALUES(quantity_available),
                quantity_on_order = VALUES(quantity_on_order),
                updated_at = NOW()
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_inventories_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_inventory')->count();
        Log::info("Inventory transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformImages(): int
    {
        Log::info("Transforming images from staging");

        $sql = "
            INSERT INTO ds_images (ds_part_id, image_url, image_path, sort_order, is_primary, created_at, updated_at)
            SELECT DISTINCT 
                s.part_id as ds_part_id,
                s.image_url,
                s.image_path,
                COALESCE(s.sort_order, 1) as sort_order,
                COALESCE(s.is_primary, 0) as is_primary,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_images_staging s
            WHERE s.part_id IS NOT NULL
            AND (s.image_url IS NOT NULL OR s.image_path IS NOT NULL)
            AND NOT EXISTS (
                SELECT 1 FROM ds_images d 
                WHERE d.ds_part_id = s.part_id
                AND (d.image_url = s.image_url OR d.image_path = s.image_path)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);
        
        // Mark as processed
        DB::table('ds_images_staging')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        $count = DB::table('ds_images')->count();
        Log::info("Images transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformFitment(): int
    {
        Log::info("Transforming fitment from staging");

        $sql = "
            INSERT INTO ds_fitment (ds_part_id, ds_application_id, ds_make_id, ds_model_id, ds_year_id, ds_engine_id, created_at, updated_at)
            SELECT DISTINCT 
                s.part_id as ds_part_id,
                s.application_id as ds_application_id,
                COALESCE(s.make_id, 1) as ds_make_id,
                COALESCE(s.model_id, 1) as ds_model_id,
                COALESCE(s.year_id, 1) as ds_year_id,
                s.engine_id as ds_engine_id,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_applications_staging s
            WHERE s.part_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_fitment d 
                WHERE d.ds_part_id = s.part_id
                AND d.ds_application_id = s.application_id
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);

        $count = DB::table('ds_fitment')->count();
        Log::info("Fitment transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformProductAttributes(): int
    {
        Log::info("Transforming product attributes from staging");

        $sql = "
            INSERT INTO ds_product_attributes (ds_part_id, ds_attribute_id, attribute_value, created_at, updated_at)
            SELECT DISTINCT 
                s.part_id as ds_part_id,
                COALESCE(s.attribute_id, s.id) as ds_attribute_id,
                s.attribute_value,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_attributes_staging s
            WHERE s.part_id IS NOT NULL
            AND s.attribute_value IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_product_attributes d 
                WHERE d.ds_part_id = s.part_id
                AND d.ds_attribute_id = COALESCE(s.attribute_id, s.id)
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);

        $count = DB::table('ds_product_attributes')->count();
        Log::info("Product attributes transformation complete. Total records: {$count}");
        
        return $count;
    }

    public function transformGroupings(): int
    {
        Log::info("Transforming groupings from staging");

        $sql = "
            INSERT INTO ds_groupings (ds_part_id, ds_group_id, created_at, updated_at)
            SELECT DISTINCT 
                s.part_id as ds_part_id,
                s.group_id as ds_group_id,
                NOW() as created_at,
                NOW() as updated_at
            FROM ds_parts_staging s
            WHERE s.part_id IS NOT NULL
            AND s.group_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM ds_groupings d 
                WHERE d.ds_part_id = s.part_id
                AND d.ds_group_id = s.group_id
            )
            AND processed_at IS NULL
        ";

        DB::statement($sql);

        $count = DB::table('ds_groupings')->count();
        Log::info("Groupings transformation complete. Total records: {$count}");
        
        return $count;
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
