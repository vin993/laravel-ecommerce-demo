<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Category\Models\Category;
use Webkul\Category\Repositories\CategoryRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BuildMenuCategories extends Command
{
    protected $signature = 'ari:build-menu-categories';
    protected $description = 'Build menu categories from DataStream vehicle types and LevelThree categories';

    protected $categoryRepository;

    public function __construct(CategoryRepository $categoryRepository)
    {
        parent::__construct();
        $this->categoryRepository = $categoryRepository;
    }

    protected $menuMappings = [
        'ACCESSORIES' => [
            'type' => 'level_three',
            'level_three_ids' => [8],
            'display_name' => 'ACCESSORIES'
        ],
        'GEAR' => [
            'type' => 'level_three',
            'level_three_ids' => [29, 30, 32, 33, 34, 35],
            'display_name' => 'GEAR'
        ],
        'MAINTENANCE' => [
            'type' => 'level_three',
            'level_three_ids' => [11, 31, 36, 46],
            'display_name' => 'MAINTENANCE'
        ],
        'TIRES' => [
            'type' => 'level_three',
            'level_three_ids' => [25],
            'display_name' => 'TIRES'
        ],
        'DIRT BIKE' => [
            'type' => 'vehicle_type',
            'vehicle_type_id' => 1,
            'display_name' => 'DIRT BIKE'
        ],
        'STREET' => [
            'type' => 'vehicle_type',
            'vehicle_type_id' => 2,
            'display_name' => 'STREET'
        ],
        'ATV' => [
            'type' => 'vehicle_type',
            'vehicle_type_id' => 3,
            'display_name' => 'ATV'
        ],
        'UTV' => [
            'type' => 'vehicle_type',
            'vehicle_type_id' => 4,
            'display_name' => 'UTV'
        ],
        'WATERCRAFT' => [
            'type' => 'vehicle_type',
            'vehicle_type_id' => 7,
            'display_name' => 'WATERCRAFT'
        ],
    ];

    public function handle()
    {
        $this->info('Building menu categories from DataStream...');

        $rootCategory = Category::whereNull('parent_id')->where('status', 1)->first();

        if (!$rootCategory) {
            $this->error('Root category not found!');
            return 1;
        }

        $locale = 'en';
        $channel = 'maddparts';

        foreach ($this->menuMappings as $key => $mapping) {
            $this->info("Processing: {$mapping['display_name']}");

            $slug = Str::slug($mapping['display_name']);
            $category = Category::whereHas('translations', function ($query) use ($slug) {
                $query->where('slug', $slug);
            })->first();

            if (!$category) {
                try {
                    $categoryData = [
                        'parent_id' => $rootCategory->id,
                        'position' => 100,
                        'status' => 1,
                        'display_mode' => 'products_only',
                        $locale => [
                            'name' => $mapping['display_name'],
                            'slug' => $slug,
                            'description' => 'Auto-generated category for ' . $mapping['display_name'],
                            'meta_title' => $mapping['display_name'],
                            'meta_description' => 'Shop ' . $mapping['display_name'] . ' at Madd Parts',
                            'meta_keywords' => strtolower($mapping['display_name']),
                        ]
                    ];

                    $category = $this->categoryRepository->create($categoryData);

                    $this->info("  Created category: {$mapping['display_name']}");
                } catch (\Exception $e) {
                    $this->error("  Failed to create category: " . $e->getMessage());
                    $this->warn("  Skipping {$mapping['display_name']}");
                    $this->newLine();
                    continue;
                }
            } else {
                $this->info("  Category already exists: {$mapping['display_name']}");
            }

            $productIds = [];

            if ($mapping['type'] === 'vehicle_type') {
                $vehicleTypeId = $mapping['vehicle_type_id'];
                
                $tmmyIds = DB::table('ds_type_make_model_year')
                    ->where('vehicle_type_id', $vehicleTypeId)
                    ->pluck('tmmy_id')
                    ->toArray();

                if (count($tmmyIds) > 0) {
                    $productIds = [];
                    foreach (array_chunk($tmmyIds, 10000) as $chunk) {
                        $chunkProductIds = DB::table('product_vehicle_fitment')
                            ->whereIn('tmmy_id', $chunk)
                            ->distinct()
                            ->pluck('product_id')
                            ->toArray();
                        
                        $productIds = array_merge($productIds, $chunkProductIds);
                    }
                    
                    $productIds = array_unique($productIds);
                }

                $this->info("  Found " . count($productIds) . " products for vehicle type ID {$vehicleTypeId}");
            } elseif ($mapping['type'] === 'level_three') {
                $levelThreeIds = $mapping['level_three_ids'];

                $levelMasterIds = DB::table('ds_level_master_index')
                    ->whereIn('level_three_id', $levelThreeIds)
                    ->pluck('id')
                    ->toArray();

                if (count($levelMasterIds) > 0) {
                    $partMasterIds = DB::table('ds_category_product_index')
                        ->whereIn('level_master_id', $levelMasterIds)
                        ->pluck('partmaster_id')
                        ->toArray();

                    if (count($partMasterIds) > 0) {
                        $productIds = [];
                        
                        foreach (array_chunk($partMasterIds, 10000) as $chunk) {
                            $skus = DB::table('ds_sku_partmaster_index')
                                ->whereIn('partmaster_id', $chunk)
                                ->pluck('sku')
                                ->toArray();

                            if (count($skus) > 0) {
                                foreach (array_chunk($skus, 10000) as $skuChunk) {
                                    $chunkProductIds = DB::table('products')
                                        ->whereIn('sku', $skuChunk)
                                        ->pluck('id')
                                        ->toArray();
                                    
                                    $productIds = array_merge($productIds, $chunkProductIds);
                                }
                            }
                        }
                        
                        $productIds = array_unique($productIds);
                    }
                }

                $this->info("  Found " . count($productIds) . " products for LevelThree IDs: " . implode(', ', $levelThreeIds));
            }

            if (count($productIds) > 0) {
                $existingLinks = DB::table('product_categories')
                    ->where('category_id', $category->id)
                    ->pluck('product_id')
                    ->toArray();

                $newLinks = array_diff($productIds, $existingLinks);

                if (count($newLinks) > 0) {
                    $insertData = [];
                    foreach ($newLinks as $productId) {
                        $insertData[] = [
                            'product_id' => $productId,
                            'category_id' => $category->id,
                        ];
                    }

                    foreach (array_chunk($insertData, 1000) as $chunk) {
                        DB::table('product_categories')->insert($chunk);
                    }

                    $this->info("  Linked " . count($newLinks) . " new products to category");
                } else {
                    $this->info("  All products already linked");
                }
            } else {
                $this->warn("  No products found for this mapping");
            }

            $this->newLine();
        }

        $this->info('Menu categories build complete!');
        return 0;
    }
}
