<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Services\ProductCountCacheService;
use Webkul\Product\Models\ProductFlat;
use Webkul\Category\Models\Category;

class VehicleFitmentController extends Controller
{
    public function getTypes()
    {
        $types = Cache::remember('vehicle_types', 86400, function () {
            return DB::table('ds_vehicle_types')
                ->select('vehicle_type_id as id', 'description')
                ->whereNotNull('description')
                ->where('description', '!=', '')
                ->orderBy('description')
                ->get();
        });

        return response()->json($types);
    }

    public function getMakes(Request $request)
    {
        $typeId = $request->input('type_id');
        
        $cacheKey = 'vehicle_makes_' . ($typeId ?: 'all');
        
        $makes = Cache::remember($cacheKey, 86400, function () use ($typeId) {
            $query = DB::table('ds_makes as m')
                ->select('m.make_id as id', 'm.description')
                ->whereNotNull('m.description')
                ->where('m.description', '!=', '')
                ->distinct();

            if ($typeId) {
                $query->join('ds_type_make_model_year as tmmy', 'm.make_id', '=', 'tmmy.make_id')
                    ->where('tmmy.vehicle_type_id', $typeId);
            }

            return $query->orderBy('m.description')->get();
        });

        return response()->json($makes);
    }

    public function getModels(Request $request)
    {
        $typeId = $request->input('type_id');
        $makeId = $request->input('make_id');
        
        $cacheKey = 'vehicle_models_' . ($typeId ?: '0') . '_' . ($makeId ?: '0');
        
        $models = Cache::remember($cacheKey, 86400, function () use ($typeId, $makeId) {
            $query = DB::table('ds_models as m')
                ->select('m.model_id as id', 'm.description')
                ->whereNotNull('m.description')
                ->where('m.description', '!=', '')
                ->distinct();

            if ($typeId && $makeId) {
                $query->join('ds_type_make_model_year as tmmy', 'm.model_id', '=', 'tmmy.model_id')
                    ->where('tmmy.vehicle_type_id', $typeId)
                    ->where('tmmy.make_id', $makeId);
            } elseif ($makeId) {
                $query->join('ds_type_make_model_year as tmmy', 'm.model_id', '=', 'tmmy.model_id')
                    ->where('tmmy.make_id', $makeId);
            }

            return $query->orderBy('m.description')->get();
        });

        return response()->json($models);
    }

    public function getYears(Request $request)
    {
        $typeId = $request->input('type_id');
        $makeId = $request->input('make_id');
        $modelId = $request->input('model_id');
        
        $cacheKey = 'vehicle_years_' . ($typeId ?: '0') . '_' . ($makeId ?: '0') . '_' . ($modelId ?: '0');
        
        $years = Cache::remember($cacheKey, 86400, function () use ($typeId, $makeId, $modelId) {
            $query = DB::table('ds_years as y')
                ->select('y.year_id as id', 'y.description')
                ->whereNotNull('y.description')
                ->where('y.description', '!=', '')
                ->distinct();

            if ($typeId && $makeId && $modelId) {
                $query->join('ds_type_make_model_year as tmmy', 'y.year_id', '=', 'tmmy.year_id')
                    ->where('tmmy.vehicle_type_id', $typeId)
                    ->where('tmmy.make_id', $makeId)
                    ->where('tmmy.model_id', $modelId);
            } elseif ($makeId && $modelId) {
                $query->join('ds_type_make_model_year as tmmy', 'y.year_id', '=', 'tmmy.year_id')
                    ->where('tmmy.make_id', $makeId)
                    ->where('tmmy.model_id', $modelId);
            }

            return $query->orderBy('y.description', 'desc')->get();
        });

        return response()->json($years);
    }

    public function searchByVehicle(Request $request)
    {
        $typeId = $request->input('type_id');
        $makeId = $request->input('make_id');
        $modelId = $request->input('model_id');
        $yearId = $request->input('year_id');

        $tmmyId = DB::table('ds_type_make_model_year')
            ->where('vehicle_type_id', $typeId)
            ->where('make_id', $makeId)
            ->where('model_id', $modelId)
            ->where('year_id', $yearId)
            ->value('tmmy_id');

        if (!$tmmyId) {
            return response()->json([
                'products' => [],
                'vehicle_info' => null,
                'total' => 0
            ]);
        }

        $vehicleInfo = DB::table('ds_type_make_model_year as tmmy')
            ->join('ds_vehicle_types as t', 'tmmy.vehicle_type_id', '=', 't.vehicle_type_id')
            ->join('ds_makes as m', 'tmmy.make_id', '=', 'm.make_id')
            ->join('ds_models as mo', 'tmmy.model_id', '=', 'mo.model_id')
            ->join('ds_years as y', 'tmmy.year_id', '=', 'y.year_id')
            ->where('tmmy.tmmy_id', $tmmyId)
            ->select('t.description as type', 'm.description as make', 'mo.description as model', 'y.description as year')
            ->first();

        $products = DB::table('product_vehicle_fitment as pvf')
            ->join('products as p', 'pvf.product_id', '=', 'p.id')
            ->leftJoin('product_flat as pf', function($join) {
                $join->on('p.id', '=', 'pf.product_id')
                    ->where('pf.channel', '=', 'maddparts')
                    ->where('pf.locale', '=', 'en');
            })
            ->leftJoin('product_images as pi', 'p.id', '=', 'pi.product_id')
            ->where('pvf.tmmy_id', $tmmyId)
            ->select(
                'p.id',
                'p.sku',
                'pf.name',
                'pf.url_key',
                'pf.price',
                'pf.special_price',
                'pf.short_description',
                'pi.path as image_path'
            )
            ->distinct()
            ->paginate(24);

        return response()->json([
            'products' => $products->items(),
            'vehicle_info' => $vehicleInfo,
            'total' => $products->total(),
            'per_page' => $products->perPage(),
            'current_page' => $products->currentPage(),
            'last_page' => $products->lastPage()
        ]);
    }

    public function showSearchPage(Request $request)
    {
        $typeId = $request->input('type_id');
        $makeId = $request->input('make_id');
        $modelId = $request->input('model_id');
        $yearId = $request->input('year_id');

        if (!$typeId || !$makeId || !$modelId || !$yearId) {
            return redirect()->route('shop.home.index')
                ->with('error', 'Please select all vehicle options');
        }

        $tmmyId = DB::table('ds_type_make_model_year')
            ->where('vehicle_type_id', $typeId)
            ->where('make_id', $makeId)
            ->where('model_id', $modelId)
            ->where('year_id', $yearId)
            ->value('tmmy_id');

        if (!$tmmyId) {
            return redirect()->route('shop.home.index')
                ->with('error', 'No products found for selected vehicle');
        }

        $vehicleInfo = DB::table('ds_type_make_model_year as tmmy')
            ->join('ds_vehicle_types as t', 'tmmy.vehicle_type_id', '=', 't.vehicle_type_id')
            ->join('ds_makes as m', 'tmmy.make_id', '=', 'm.make_id')
            ->join('ds_models as mo', 'tmmy.model_id', '=', 'mo.model_id')
            ->join('ds_years as y', 'tmmy.year_id', '=', 'y.year_id')
            ->where('tmmy.tmmy_id', $tmmyId)
            ->select('t.description as type', 'm.description as make', 'mo.description as model', 'y.description as year')
            ->first();

        $productIds = DB::table('product_vehicle_fitment')
            ->where('tmmy_id', $tmmyId)
            ->pluck('product_id')
            ->toArray();

        $products = ProductFlat::whereIn('product_id', $productIds)
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->with(['product.images', 'product.inventories'])
            ->paginate(24);

        $products->getCollection()->transform(function ($product) {
            $totalQty = $product->product->inventories->sum('qty');
            $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
            $product->stock_quantity = $totalQty;
            return $product;
        });

        $priceRange = ProductFlat::whereIn('product_id', $productIds)
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
            ->first();

        $brands = DB::table('product_attribute_values as pav')
            ->join('ds_manufacturer_index as mi', 'pav.text_value', '=', 'mi.manufacturer_id')
            ->select('mi.manufacturer_name as brand', DB::raw('count(*) as product_count'))
            ->where('pav.attribute_id', 25)
            ->whereNotNull('pav.text_value')
            ->where('pav.text_value', '!=', '')
            ->whereIn('pav.product_id', $productIds)
            ->groupBy('mi.manufacturer_name')
            ->orderBy('product_count', 'desc')
            ->limit(20)
            ->get();

        $categories = Category::with('translations')
            ->whereHas('translations', function ($q) {
                $q->where('locale', 'en');
            })
            ->get()
            ->map(function ($cat) use ($productIds) {
                $translation = $cat->translations->where('locale', 'en')->first();

                // Cache category count query
                $cacheService = app(ProductCountCacheService::class);
                $cacheKey = $cacheService->generateCategoryCountKey(
                    $cat->id,
                    $productIds,
                    'maddparts',
                    'en'
                );

                $productCount = $cacheService->remember(
                    $cacheKey,
                    function() use ($cat, $productIds) {
                        return ProductFlat::where('channel', 'maddparts')
                            ->where('locale', 'en')
                            ->whereHas('product.categories', function ($q) use ($cat) {
                                $q->where('category_id', $cat->id);
                            })
                            ->whereIn('product_id', $productIds)
                            ->count();
                    }
                );

                return (object)[
                    'id' => $cat->id,
                    'name' => $translation ? $translation->name : 'Category',
                    'product_count' => $productCount
                ];
            })
            ->filter(function ($cat) {
                return $cat->product_count > 0;
            })
            ->sortByDesc('product_count')
            ->values();

        $filterData = [
            'brands' => $brands,
            'categories' => $categories,
            'price_range' => (object)[
                'min_price' => $priceRange->min_price ?? 0,
                'max_price' => $priceRange->max_price ?? 1000
            ],
            'vehicles' => true
        ];

        return view('search.vehicle', [
            'products' => $products,
            'vehicleInfo' => $vehicleInfo,
            'filterData' => $filterData,
            'initialFilters' => [
                'vehicleType' => $typeId,
                'vehicleMake' => $makeId,
                'vehicleModel' => $modelId,
                'vehicleYear' => $yearId
            ]
        ]);
    }

    public function filterByVehicle(Request $request)
    {
        $vehicleType = $request->input('vehicle_type');
        $vehicleMake = $request->input('vehicle_make');
        $vehicleModel = $request->input('vehicle_model');
        $vehicleYear = $request->input('vehicle_year');

        $page = $request->get('page', 1);
        $perPage = 24;
        $sortBy = $request->get('sort', 'name_az');
        $brands = $request->get('brands', []);
        if (is_string($brands)) {
            $brands = [$brands];
        }
        $categories = $request->get('categories', []);
        if (is_string($categories)) {
            $categories = [$categories];
        }
        $minPrice = $request->get('min_price');
        $maxPrice = $request->get('max_price');
        $inStock = $request->get('in_stock');

        // Initialize productIds array for filter data generation
        $productIds = [];
        $vehicleFilterApplied = false;

        // If vehicle filters are cleared, return empty result with message
        if (!$vehicleType || !$vehicleMake || !$vehicleModel || !$vehicleYear) {
            return response()->json([
                'success' => true,
                'products' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => $perPage,
                    'total' => 0,
                    'has_more_pages' => false
                ],
                'filterData' => [
                    'brands' => [],
                    'price_range' => (object)['min_price' => 0, 'max_price' => 0],
                    'categories' => [],
                    'vehicles' => true
                ],
                'message' => 'Please select a vehicle to see products'
            ]);
        } else {
            // Vehicle filters are applied - filter by vehicle fitment
            $vehicleFilterApplied = true;

            $tmmyId = DB::table('ds_type_make_model_year')
                ->where('vehicle_type_id', $vehicleType)
                ->where('make_id', $vehicleMake)
                ->where('model_id', $vehicleModel)
                ->where('year_id', $vehicleYear)
                ->value('tmmy_id');

            if (!$tmmyId) {
                $typeDesc = DB::table('ds_vehicle_types')->where('vehicle_type_id', $vehicleType)->value('description');
                $makeDesc = DB::table('ds_makes')->where('make_id', $vehicleMake)->value('description');
                $modelDesc = DB::table('ds_models')->where('model_id', $vehicleModel)->value('description');
                $yearDesc = DB::table('ds_years')->where('year_id', $vehicleYear)->value('description');

                return response()->json([
                    'success' => true,
                    'products' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 24,
                        'total' => 0,
                        'has_more_pages' => false
                    ],
                    'filterData' => [
                        'brands' => [],
                        'price_range' => (object)['min_price' => 0, 'max_price' => 0],
                        'categories' => [],
                        'vehicles' => true
                    ],
                    'vehicleInfo' => [
                        'type' => $typeDesc,
                        'make' => $makeDesc,
                        'model' => $modelDesc,
                        'year' => $yearDesc
                    ]
                ]);
            }

            $productIds = DB::table('product_vehicle_fitment')
                ->where('tmmy_id', $tmmyId)
                ->pluck('product_id')
                ->toArray();

            if (empty($productIds)) {
                $typeDesc = DB::table('ds_vehicle_types')->where('vehicle_type_id', $vehicleType)->value('description');
                $makeDesc = DB::table('ds_makes')->where('make_id', $vehicleMake)->value('description');
                $modelDesc = DB::table('ds_models')->where('model_id', $vehicleModel)->value('description');
                $yearDesc = DB::table('ds_years')->where('year_id', $vehicleYear)->value('description');

                return response()->json([
                    'success' => true,
                    'products' => [],
                    'pagination' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => $perPage,
                        'total' => 0,
                        'has_more_pages' => false
                    ],
                    'filterData' => [
                        'brands' => [],
                        'price_range' => (object)['min_price' => 0, 'max_price' => 0],
                        'categories' => [],
                        'vehicles' => true
                    ],
                    'vehicleInfo' => [
                        'type' => $typeDesc,
                        'make' => $makeDesc,
                        'model' => $modelDesc,
                        'year' => $yearDesc
                    ]
                ]);
            }

            $query = ProductFlat::whereIn('product_id', $productIds)
                ->where('channel', 'maddparts')
                ->where('locale', 'en')
                ->with(['product.images', 'product.inventories']);
        }

        if (!empty($brands)) {
            $manufacturerIds = DB::table('ds_manufacturer_index')
                ->whereIn('manufacturer_name', $brands)
                ->pluck('manufacturer_id')
                ->toArray();

            if (!empty($manufacturerIds)) {
                $query->whereHas('product.attribute_values', function ($q) use ($manufacturerIds) {
                    $q->where('attribute_id', 25)->whereIn('text_value', $manufacturerIds);
                });
            }
        }

        if (!empty($categories)) {
            $query->whereHas('product.categories', function ($q) use ($categories) {
                $q->whereIn('category_id', $categories);
            });
        }

        if ($minPrice) {
            $query->where('price', '>=', $minPrice);
        }

        if ($maxPrice) {
            $query->where('price', '<=', $maxPrice);
        }

        if ($inStock) {
            // Filter will be applied after fetching - see below where stock_quantities are calculated
        }

        switch ($sortBy) {
            case 'price_low':
                $query->orderBy('price', 'asc');
                break;
            case 'price_high':
                $query->orderBy('price', 'desc');
                break;
            case 'name_az':
                $query->orderBy('name', 'asc');
                break;
            case 'name_za':
                $query->orderBy('name', 'desc');
                break;
            case 'newest':
                $query->orderBy('created_at', 'desc');
                break;
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            default:
                $query->orderBy('name', 'asc');
        }

        // Cache the expensive count() query with Redis
        $cacheService = app(ProductCountCacheService::class);
        $cacheKey = $cacheService->generateVehicleCountKey([
            'vehicleType' => $vehicleType,
            'vehicleMake' => $vehicleMake,
            'vehicleModel' => $vehicleModel,
            'vehicleYear' => $vehicleYear,
            'productIds' => $productIds,
            'brands' => $brands,
            'categories' => $categories,
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'inStock' => $inStock,
            'sortBy' => $sortBy,
        ]);

        $total = $cacheService->remember(
            $cacheKey,
            function() use ($query) {
                return $query->count();
            }
        );

        // When filtering by stock, fetch more products than needed
        $fetchMultiplier = ($inStock === 'true') ? 5 : 1;
        $fetchCount = $perPage * $fetchMultiplier;

        $products = $query->skip(($page - 1) * $fetchCount)
            ->take($fetchCount)
            ->get();

        $productIdsFromQuery = $products->pluck('product_id')->toArray();
        $stockQuantities = DB::table('product_inventories')
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->whereIn('product_id', $productIdsFromQuery)
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id');

        // Get variant stock for configurable products (ALWAYS calculate, not just when filtering)
        $variantStockQuantities = [];
        $parentProductIds = $products->filter(function($product) {
            return strpos($product->sku, '-PARENT') !== false;
        })->pluck('product_id')->toArray();

        if (!empty($parentProductIds)) {
            $variantStocks = DB::table('products as p')
                ->join('product_inventories as pi', 'pi.product_id', '=', 'p.id')
                ->whereIn('p.parent_id', $parentProductIds)
                ->whereNotNull('p.parent_id')
                ->select('p.parent_id', DB::raw('SUM(pi.qty) as total_qty'))
                ->groupBy('p.parent_id')
                ->pluck('total_qty', 'parent_id');

            $variantStockQuantities = $variantStocks->toArray();
        }

        // PERFORMANCE FIX: Batch-load all configurable product variant prices in ONE query
        // This eliminates N+1 queries that were running inside the loop (70% of total time!)
        $configurableProducts = $products->filter(function($product) {
            return strpos($product->sku, '-PARENT') !== false;
        });

        $configurableMinPrices = [];
        if ($configurableProducts->count() > 0) {
            // Extract all base SKUs for configurable products
            $baseSkus = $configurableProducts->map(function($product) {
                return str_replace('-PARENT', '', $product->sku);
            })->toArray();

            // Build a single query to get minimum prices for ALL configurable products at once
            if (!empty($baseSkus)) {
                // MEMORY FIX: Process in chunks to avoid loading too much data into memory
                // With large catalogs, this query was loading 100k+ rows causing MySQL memory issues
                $allVariantPriceData = collect();

                // Process base SKUs in chunks of 50 to limit memory usage per query
                foreach (array_chunk($baseSkus, 50) as $baseSkuChunk) {
                    $chunkData = DB::table('product_flat')
                        ->where('channel', 'maddparts')
                        ->where('locale', 'en')
                        ->where('type', 'simple')
                        ->whereRaw('CAST(price AS DECIMAL(10,2)) > 0')
                        ->where(function($query) use ($baseSkuChunk) {
                            foreach ($baseSkuChunk as $baseSku) {
                                $query->orWhere('sku', 'LIKE', $baseSku . '%');
                            }
                        })
                        ->select('sku', 'price')
                        ->get();

                    $allVariantPriceData = $allVariantPriceData->merge($chunkData);
                }

                // Group by base SKU and find minimum price
                foreach ($baseSkus as $baseSku) {
                    $variantPricesForSku = $allVariantPriceData->filter(function($item) use ($baseSku) {
                        return str_starts_with($item->sku, $baseSku) && $item->sku !== $baseSku . '-PARENT';
                    });

                    if ($variantPricesForSku->count() > 0) {
                        $configurableMinPrices[$baseSku] = $variantPricesForSku->min('price');
                    }
                }
            }
        }

        $products->transform(function ($product) use ($stockQuantities, $variantStockQuantities, $configurableMinPrices) {
            $totalQty = $stockQuantities[$product->product_id] ?? 0;

            // For configurable products, check variant stock
            if (strpos($product->sku, '-PARENT') !== false && isset($variantStockQuantities[$product->product_id])) {
                $totalQty = max($totalQty, $variantStockQuantities[$product->product_id]);
            }

            $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
            $product->stock_quantity = $totalQty;

            $firstImage = $product->product && $product->product->images ? $product->product->images->first() : null;
            $imageUrl = $firstImage
                ? $firstImage->url
                : asset('themes/maddparts/images/logo.png');
            $product->image_url = $imageUrl;

            // FIXED: Use pre-loaded prices instead of querying in the loop
            if (strpos($product->sku, '-PARENT') !== false) {
                $baseSku = str_replace('-PARENT', '', $product->sku);
                if (isset($configurableMinPrices[$baseSku])) {
                    $product->price = $configurableMinPrices[$baseSku];
                }
            }

            return $product;
        });

        // Filter out products with no stock if in_stock filter is active
        if ($inStock === 'true') {
            $products = $products->filter(function($product) {
                return $product->stock_quantity > 0;
            })->values();

            // Take only the requested number of products
            $products = $products->take($perPage);
        }

        $filteredProductIds = ProductFlat::whereIn('product_id', $productIds)
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->pluck('product_id')
            ->toArray();

        $brandsWithProducts = DB::table('product_attribute_values as pav')
            ->join('ds_manufacturer_index as mi', 'pav.text_value', '=', 'mi.manufacturer_id')
            ->select('mi.manufacturer_name as brand', DB::raw('count(DISTINCT pav.product_id) as product_count'))
            ->where('pav.attribute_id', 25)
            ->whereNotNull('pav.text_value')
            ->where('pav.text_value', '!=', '')
            ->whereIn('pav.product_id', $filteredProductIds)
            ->groupBy('mi.manufacturer_name')
            ->orderBy('product_count', 'desc')
            ->limit(20)
            ->get();

        // If there are selected brand filters from request, include them even if count is 0
        $selectedBrandFilters = $request->get('brands', []);
        if (is_string($selectedBrandFilters)) {
            $selectedBrandFilters = [$selectedBrandFilters];
        }

        if (!empty($selectedBrandFilters)) {
            foreach ($selectedBrandFilters as $selectedBrand) {
                // Check if this brand is already in the results
                $exists = $brandsWithProducts->contains('brand', $selectedBrand);

                if (!$exists) {
                    // Add with count of 0
                    $brandsWithProducts->push((object)[
                        'brand' => $selectedBrand,
                        'product_count' => 0
                    ]);
                }
            }
        }

        $brandResults = $brandsWithProducts;

        $priceRange = ProductFlat::whereIn('product_id', $filteredProductIds)
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->selectRaw('ROUND(MIN(price), 2) as min_price, ROUND(MAX(price), 2) as max_price')
            ->first();

        // Get categories that have products for this vehicle
        $categoriesWithProducts = DB::table('product_flat as pf')
            ->join('product_categories as pc', 'pf.product_id', '=', 'pc.product_id')
            ->join('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
            ->select('ct.category_id as id', 'ct.name', DB::raw('COUNT(DISTINCT pf.product_id) as product_count'))
            ->where('pf.channel', 'maddparts')
            ->where('pf.locale', 'en')
            ->where('ct.locale', 'en')
            ->whereIn('pf.product_id', $filteredProductIds)
            ->groupBy('ct.category_id', 'ct.name')
            ->orderBy('product_count', 'desc')
            ->limit(20)
            ->get();

        // If there are selected category filters from request, include them even if count is 0
        $selectedCategoryFilters = $request->get('categories', []);
        if (is_string($selectedCategoryFilters)) {
            $selectedCategoryFilters = [$selectedCategoryFilters];
        }

        if (!empty($selectedCategoryFilters)) {
            // Get names for selected categories
            $selectedCategoryData = DB::table('category_translations')
                ->select('category_id as id', 'name')
                ->where('locale', 'en')
                ->whereIn('category_id', $selectedCategoryFilters)
                ->get();

            foreach ($selectedCategoryData as $selectedCat) {
                // Check if this category is already in the results
                $exists = $categoriesWithProducts->contains('id', $selectedCat->id);

                if (!$exists) {
                    // Add with count of 0
                    $categoriesWithProducts->push((object)[
                        'id' => $selectedCat->id,
                        'name' => $selectedCat->name,
                        'product_count' => 0
                    ]);
                }
            }
        }

        $categoryResults = $categoriesWithProducts->map(function ($cat) {
            return (object)[
                'id' => $cat->id,
                'name' => $cat->name,
                'product_count' => $cat->product_count
            ];
        });

        $vehicleInfo = null;
        if ($vehicleType && $vehicleMake && $vehicleModel && $vehicleYear) {
            $typeDesc = DB::table('ds_vehicle_types')->where('vehicle_type_id', $vehicleType)->value('description');
            $makeDesc = DB::table('ds_makes')->where('make_id', $vehicleMake)->value('description');
            $modelDesc = DB::table('ds_models')->where('model_id', $vehicleModel)->value('description');
            $yearDesc = DB::table('ds_years')->where('year_id', $vehicleYear)->value('description');

            $vehicleInfo = [
                'type' => $typeDesc,
                'make' => $makeDesc,
                'model' => $modelDesc,
                'year' => $yearDesc
            ];
        }

        return response()->json([
            'success' => true,
            'products' => $products,
            'pagination' => [
                'current_page' => (int)$page,
                'last_page' => (int)ceil($total / $perPage),
                'per_page' => $perPage,
                'total' => $total,
                'has_more_pages' => $page < ceil($total / $perPage)
            ],
            'filterData' => [
                'brands' => $brandResults,
                'price_range' => $priceRange,
                'categories' => $categoryResults,
                'vehicles' => true
            ],
            'vehicleInfo' => $vehicleInfo
        ]);
    }
}
