<?php

namespace App\Services\Search;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class VehicleTypeMapper
{
    protected $vehicleTypeMapping = [
        1 => 'motorcycle',
        2 => 'atv',
        3 => 'utv',
        4 => 'dirt bike',
        5 => 'scooter',
        6 => 'watercraft',
        7 => 'snowmobile',
        8 => 'atv'
    ];

    public function getVehicleTypesForProduct($productId)
    {
        $cacheKey = "vehicle_types_product_{$productId}";

        return Cache::remember($cacheKey, 3600, function() use ($productId) {
            try {
                $tableExists = DB::select("SHOW TABLES LIKE 'product_vehicle_fitment'");
                if (empty($tableExists)) {
                    return [];
                }

                $vehicleTypeIds = DB::table('product_vehicle_fitment as pvf')
                    ->join('ds_type_make_model_year as tmmy', 'pvf.tmmy_id', '=', 'tmmy.id')
                    ->where('pvf.product_id', $productId)
                    ->distinct()
                    ->pluck('tmmy.vehicle_type_id')
                    ->toArray();

                $vehicleTypes = [];
                foreach ($vehicleTypeIds as $typeId) {
                    if (isset($this->vehicleTypeMapping[$typeId])) {
                        $vehicleTypes[] = $this->vehicleTypeMapping[$typeId];
                    }
                }

                return array_unique($vehicleTypes);
            } catch (\Exception $e) {
                \Log::warning("Error getting vehicle types for product {$productId}: " . $e->getMessage());
                return [];
            }
        });
    }

    public function getVehicleBrandsForProduct($productId)
    {
        $cacheKey = "vehicle_brands_product_{$productId}";

        return Cache::remember($cacheKey, 3600, function() use ($productId) {
            try {
                $tableExists = DB::select("SHOW TABLES LIKE 'product_vehicle_fitment'");
                if (empty($tableExists)) {
                    return [];
                }

                $brands = DB::table('product_vehicle_fitment as pvf')
                    ->join('ds_type_make_model_year as tmmy', 'pvf.tmmy_id', '=', 'tmmy.id')
                    ->join('ds_makes as m', 'tmmy.make_id', '=', 'm.id')
                    ->where('pvf.product_id', $productId)
                    ->distinct()
                    ->pluck('m.make_name')
                    ->map(function($brand) {
                        return strtolower(trim($brand));
                    })
                    ->toArray();

                return array_unique($brands);
            } catch (\Exception $e) {
                \Log::warning("Error getting vehicle brands for product {$productId}: " . $e->getMessage());
                return [];
            }
        });
    }

    public function getVehicleModelsForProduct($productId)
    {
        $cacheKey = "vehicle_models_product_{$productId}";

        return Cache::remember($cacheKey, 3600, function() use ($productId) {
            try {
                $tableExists = DB::select("SHOW TABLES LIKE 'product_vehicle_fitment'");
                if (empty($tableExists)) {
                    return [];
                }

                $models = DB::table('product_vehicle_fitment as pvf')
                    ->join('ds_type_make_model_year as tmmy', 'pvf.tmmy_id', '=', 'tmmy.id')
                    ->join('ds_models as mod', 'tmmy.model_id', '=', 'mod.id')
                    ->where('pvf.product_id', $productId)
                    ->distinct()
                    ->limit(50)
                    ->pluck('mod.model_name')
                    ->map(function($model) {
                        return strtolower(trim($model));
                    })
                    ->toArray();

                return array_unique($models);
            } catch (\Exception $e) {
                \Log::warning("Error getting vehicle models for product {$productId}: " . $e->getMessage());
                return [];
            }
        });
    }

    public function mapVehicleTypeName($typeId)
    {
        return $this->vehicleTypeMapping[$typeId] ?? null;
    }

    public function getAllVehicleTypes()
    {
        return array_unique(array_values($this->vehicleTypeMapping));
    }
}
