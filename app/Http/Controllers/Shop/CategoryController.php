<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductFlat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class CategoryController extends Controller
{
    public function view($slug, Request $request)
    {
        try {
            $category = Category::whereHas('translations', function ($query) use ($slug) {
                $query->where('slug', $slug)->where('locale', 'en');
            })->with(['translations'])->first();

            if (!$category) {
                abort(404, 'Category not found');
            }

            $categoryTranslation = $category->translations->where('locale', 'en')->first();
            $category->name = $categoryTranslation ? $categoryTranslation->name : 'Category';
            $category->description = $categoryTranslation ? $categoryTranslation->description : '';

            if ($request->ajax()) {
                return $this->getFilteredProducts($request, $slug);
            }

            // OPTIMIZATION: Cache entire page result
            $page = $request->get('page', 1);
            $cacheKey = 'category_view_' . $category->id . '_page_' . $page . '_' . md5(serialize($request->except(['_token', 'page'])));
            $cacheDuration = 1800; // 30 minutes

            $cachedData = Cache::remember($cacheKey, $cacheDuration, function() use ($request, $category) {
                return $this->getCachedPageData($request, $category);
            });

            $products = new LengthAwarePaginator(
                $cachedData['items'],
                $cachedData['total'],
                $cachedData['per_page'],
                $cachedData['current_page'],
                ['path' => $request->url(), 'query' => $request->query()]
            );

            $filterData = $this->getFilterData($category, $request);

            return view('categories.view', compact('category', 'products', 'filterData'));

        } catch (\Exception $e) {
            abort(404, 'Category not found');
        }
    }

    private function getCachedPageData(Request $request, $category)
    {
        $page = $request->get('page', 1);
        $perPage = 12;
        $inStock = $request->get('in_stock') === 'true';

        // When filtering by stock, fetch more products than needed
        $fetchMultiplier = $inStock ? 5 : 1;
        $fetchCount = $perPage * $fetchMultiplier;

        $products = $this->buildProductQuery($request, $category)
            ->skip(($page - 1) * $fetchCount)
            ->take($fetchCount)
            ->get();

        $productIds = $products->pluck('product_id')->toArray();
        $stockQuantities = DB::table('product_inventories')
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->whereIn('product_id', $productIds)
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

        $allVariantIds = [];
        foreach ($products as $product) {
            if ($product->product && $product->product->variants) {
                $allVariantIds = array_merge($allVariantIds, $product->product->variants->pluck('id')->toArray());
            }
        }

        $variantPrices = [];
        if (!empty($allVariantIds)) {
            $variantPrices = DB::table('product_flat')
                ->whereIn('product_id', $allVariantIds)
                ->where('channel', 'maddparts')
                ->where('locale', 'en')
                ->get(['product_id', 'price', 'special_price'])
                ->keyBy('product_id');
        }

        // PERFORMANCE FIX: Batch-load all configurable product variant prices in ONE query
        // This eliminates N+1 queries that were running inside the loop
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

        $products->transform(function ($product) use ($stockQuantities, $variantPrices, $variantStockQuantities, $configurableMinPrices) {
            $totalQty = $stockQuantities[$product->product_id] ?? 0;

            // For configurable products, check variant stock
            if (strpos($product->sku, '-PARENT') !== false && isset($variantStockQuantities[$product->product_id])) {
                $totalQty = max($totalQty, $variantStockQuantities[$product->product_id]);
            }

            $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
            $product->stock_quantity = $totalQty;

            // FIXED: Use pre-loaded prices instead of querying in the loop
            if (strpos($product->sku, '-PARENT') !== false) {
                $baseSku = str_replace('-PARENT', '', $product->sku);
                if (isset($configurableMinPrices[$baseSku])) {
                    $product->price = $configurableMinPrices[$baseSku];
                }
            }

            if ($product->product && $product->product->variants && $product->product->variants->count() > 0) {
                foreach ($product->product->variants as $variant) {
                    if (isset($variantPrices[$variant->id])) {
                        $variant->variant_price = $variantPrices[$variant->id]->price;
                        $variant->variant_special_price = $variantPrices[$variant->id]->special_price;
                    }
                }
            }

            return $product;
        });

        // Filter out products with no stock if in_stock filter is active
        if ($inStock) {
            $products = $products->filter(function($product) {
                return $product->stock_quantity > 0;
            })->values();

            // Take only the requested number of products
            $products = $products->take($perPage);
        }

        $total = ($page * $perPage) + ($products->count() == $perPage ? $perPage : 0);

        return [
            'items' => $products,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page
        ];
    }

    public function getFilteredProducts(Request $request, $slug = null)
    {
        $category = null;
        if ($slug) {
            $category = Category::whereHas('translations', function ($query) use ($slug) {
                $query->where('slug', $slug)->where('locale', 'en');
            })->first();
        }
        
        if (!$category) {
            return response()->json(['error' => 'Category not found'], 404);
        }

        $cacheKey = 'category_products_' . $category->id . '_' . md5(serialize($request->except('_token')));
        $cacheDuration = 1800;

        $result = Cache::remember($cacheKey, $cacheDuration, function() use ($request, $category) {
            return $this->getProductsData($request, $category);
        });

        return response()->json($result);
    }

    private function getProductsData(Request $request, $category)
    {
        $page = $request->get('page', 1);
        $perPage = 24;
        $inStock = $request->get('in_stock') === 'true';

        // When filtering by stock, fetch more products than needed
        $fetchMultiplier = $inStock ? 5 : 1;
        $fetchCount = $perPage * $fetchMultiplier;

        $products = $this->buildProductQuery($request, $category)
            ->skip(($page - 1) * $fetchCount)
            ->take($fetchCount)
            ->get();

        $productIds = $products->pluck('product_id')->toArray();
        $stockQuantities = DB::table('product_inventories')
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->whereIn('product_id', $productIds)
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

        $allVariantIds = [];
        foreach ($products as $product) {
            if ($product->product && $product->product->variants) {
                $allVariantIds = array_merge($allVariantIds, $product->product->variants->pluck('id')->toArray());
            }
        }

        $variantPrices = [];
        if (!empty($allVariantIds)) {
            $variantPrices = DB::table('product_flat')
                ->whereIn('product_id', $allVariantIds)
                ->where('channel', 'maddparts')
                ->where('locale', 'en')
                ->get(['product_id', 'price', 'special_price'])
                ->keyBy('product_id');
        }

        // PERFORMANCE FIX: Batch-load all configurable product variant prices in ONE query
        // This eliminates N+1 queries that were running inside the loop
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

        $products->transform(function ($product) use ($stockQuantities, $variantPrices, $variantStockQuantities, $configurableMinPrices) {
            $totalQty = $stockQuantities[$product->product_id] ?? 0;

            // For configurable products, check variant stock
            if (strpos($product->sku, '-PARENT') !== false && isset($variantStockQuantities[$product->product_id])) {
                $totalQty = max($totalQty, $variantStockQuantities[$product->product_id]);
            }

            $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
            $product->stock_quantity = $totalQty;

            // FIXED: Use pre-loaded prices instead of querying in the loop
            if (strpos($product->sku, '-PARENT') !== false) {
                $baseSku = str_replace('-PARENT', '', $product->sku);
                if (isset($configurableMinPrices[$baseSku])) {
                    $product->price = $configurableMinPrices[$baseSku];
                }
            }

            if ($product->product && $product->product->variants && $product->product->variants->count() > 0) {
                foreach ($product->product->variants as $variant) {
                    if (isset($variantPrices[$variant->id])) {
                        $variant->variant_price = $variantPrices[$variant->id]->price;
                        $variant->variant_special_price = $variantPrices[$variant->id]->special_price;
                    }
                }
            }

            return $product;
        });

        // Filter out products with no stock if in_stock filter is active
        if ($inStock) {
            $products = $products->filter(function($product) {
                return $product->stock_quantity > 0;
            })->values();

            // Take only the requested number of products
            $products = $products->take($perPage);
        }

        $filterData = $this->getFilterData($category, $request);

        $productsArray = $products->map(function($product) use ($configurableMinPrices) {
            // Add image URL - check main product, then variants
            $imageUrl = null;

            if ($product->product) {
                if ($product->product->images && $product->product->images->count() > 0) {
                    $imageUrl = $product->product->images->first()->url;
                } elseif ($product->product->variants && $product->product->variants->count() > 0) {
                    foreach ($product->product->variants as $variant) {
                        if ($variant->images && $variant->images->count() > 0) {
                            $imageUrl = $variant->images->first()->url;
                            break;
                        }
                    }
                }
            }

            if (!$imageUrl) {
                $imageUrl = asset('themes/maddparts/images/logo.png');
            }

            // FIXED: Use pre-loaded prices instead of querying in the loop
            $displayPrice = $product->price;
            if (strpos($product->sku, '-PARENT') !== false) {
                $baseSku = str_replace('-PARENT', '', $product->sku);
                if (isset($configurableMinPrices[$baseSku])) {
                    $displayPrice = $configurableMinPrices[$baseSku];
                }
            }

            return [
                'id' => $product->id,
                'product_id' => $product->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $displayPrice,
                'url_key' => $product->url_key,
                'stock_status' => $product->stock_status,
                'stock_quantity' => $product->stock_quantity,
                'image_url' => $imageUrl,
            ];
        });

        $total = ($page * $perPage) + ($products->count() == $perPage ? $perPage : 0);
        $lastPage = (int) ceil($total / $perPage);

        return [
            'success' => true,
            'products' => $productsArray,
            'pagination' => [
                'current_page' => (int) $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'has_more_pages' => $products->count() == $perPage
            ],
            'filterData' => $filterData
        ];
    }

    private function buildProductQuery(Request $request, $category = null)
    {
        $sortBy = $request->get('sort', 'name');
        $sortOrder = $request->get('order', 'asc');
        $brands = $request->get('brands', []);
        if (is_string($brands)) {
            $brands = [$brands];
        }
        $minPrice = $request->get('min_price');
        $maxPrice = $request->get('max_price');
        $inStock = $request->get('in_stock');

        $vehicleType = $request->get('vehicle_type');
        $vehicleMake = $request->get('vehicle_make');
        $vehicleModel = $request->get('vehicle_model');
        $vehicleYear = $request->get('vehicle_year');

        $query = ProductFlat::where('product_flat.visible_individually', 1)
            ->where('product_flat.channel', 'maddparts')
            ->where('product_flat.locale', 'en')
            ->distinct()
            ->select('product_flat.id', 'product_flat.sku', 'product_flat.type', 'product_flat.name',
                     'product_flat.price', 'product_flat.product_id', 'product_flat.url_key',
                     'product_flat.channel', 'product_flat.locale');

        if ($category) {
            $categoryIds = $this->getAllCategoryIds($category);
            $query->join('product_categories', 'product_flat.product_id', '=', 'product_categories.product_id')
                  ->whereIn('product_categories.category_id', $categoryIds);
        }

        if (!empty($brands)) {
            // FIX: Get attribute_option IDs, not manufacturer IDs
            // product_attribute_values.text_value stores attribute_option.id, not manufacturer_id
            $brandOptionIds = DB::table('attribute_options')
                ->where('attribute_id', 25)
                ->whereIn('admin_name', $brands)
                ->pluck('id')
                ->toArray();

            if (!empty($brandOptionIds)) {
                $query->join('product_attribute_values as pav_brand', function($join) use ($brandOptionIds) {
                    $join->on('product_flat.product_id', '=', 'pav_brand.product_id')
                         ->where('pav_brand.attribute_id', 25)
                         ->whereIn('pav_brand.text_value', $brandOptionIds);
                });
            }
        }

        $query->with(['product' => function($q) {
            $q->with(['images' => function($imgQuery) {
                  $imgQuery->select('id', 'path', 'product_id')->limit(1)->orderBy('position', 'asc');
              }])
              ->with(['variants' => function($variantQuery) {
                  $variantQuery->with(['images' => function($imgQuery) {
                      $imgQuery->select('id', 'path', 'product_id')->limit(1)->orderBy('position', 'asc');
                  }])
                  ->with(['product_flats' => function($flatQuery) {
                      $flatQuery->where('channel', 'maddparts')->where('locale', 'en');
                  }]);
              }]);
        }]);

        if ($minPrice) {
            $query->where('price', '>=', $minPrice);
        }

        if ($maxPrice) {
            $query->where('price', '<=', $maxPrice);
        }

        if ($inStock) {
            // Temporarily disabled - testing
        }

        if ($vehicleType && $vehicleMake && $vehicleModel && $vehicleYear) {
            $tmmyId = DB::table('ds_type_make_model_year')
                ->where('vehicle_type_id', $vehicleType)
                ->where('make_id', $vehicleMake)
                ->where('model_id', $vehicleModel)
                ->where('year_id', $vehicleYear)
                ->value('tmmy_id');

            if ($tmmyId) {
                $query->whereHas('product', function ($q) use ($tmmyId) {
                    $q->whereIn('id', function ($subQuery) use ($tmmyId) {
                        $subQuery->select('product_id')
                            ->from('product_vehicle_fitment')
                            ->where('tmmy_id', $tmmyId);
                    });
                });
            }
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

        return $query;
    }

    private function getFilterData($category = null, $request = null)
    {
        if (!$category) {
            return [
                'brands' => [],
                'price_range' => (object)['min_price' => 0, 'max_price' => 1000],
                'categories' => [],
                'vehicles' => true
            ];
        }

        $cacheKey = 'category_filters_' . $category->id;
        $cacheDuration = 1800;

        return Cache::remember($cacheKey, $cacheDuration, function() use ($category) {
            return $this->calculateFilterData($category);
        });
    }

    private function calculateFilterData($category)
    {
        $categoryIds = $this->getAllCategoryIds($category);

        $brands = DB::table('product_attribute_values as pav')
            ->join('product_categories as pc', 'pav.product_id', '=', 'pc.product_id')
            ->join('product_flat as pf', 'pav.product_id', '=', 'pf.product_id')
            ->join('products as p', 'pav.product_id', '=', 'p.id')
            ->join('attribute_options as ao', 'pav.text_value', '=', 'ao.id')
            ->select('ao.admin_name as brand', DB::raw('count(DISTINCT pav.product_id) as product_count'))
            ->whereIn('pc.category_id', $categoryIds)
            ->where('pf.channel', 'maddparts')
            ->where('pf.locale', 'en')
            ->where('pf.status', 1)
            ->where('pf.visible_individually', 1)
            ->whereNull('p.parent_id')
            ->where('pav.attribute_id', 25)
            ->where('ao.attribute_id', 25)
            ->whereNotNull('pav.text_value')
            ->where('pav.text_value', '!=', '')
            ->groupBy('ao.admin_name')
            ->orderBy('product_count', 'desc')
            ->limit(20)
            ->get();

        $priceRange = DB::table('product_flat as pf')
            ->join('product_categories as pc', 'pf.product_id', '=', 'pc.product_id')
            ->join('products as p', 'pf.product_id', '=', 'p.id')
            ->whereIn('pc.category_id', $categoryIds)
            ->where('pf.channel', 'maddparts')
            ->where('pf.locale', 'en')
            ->where('pf.status', 1)
            ->where('pf.visible_individually', 1)
            ->whereNull('p.parent_id')
            ->selectRaw('ROUND(MIN(pf.price), 2) as min_price, ROUND(MAX(pf.price), 2) as max_price')
            ->first();

        return [
            'brands' => $brands,
            'price_range' => $priceRange,
            'categories' => [],
            'vehicles' => true
        ];
    }

    private function getAllCategoryIds($category)
    {
        $categoryIds = [$category->id];

        $children = Category::where('parent_id', $category->id)
            ->where('status', 1)
            ->get();

        foreach ($children as $child) {
            $categoryIds = array_merge($categoryIds, $this->getAllCategoryIds($child));
        }

        return $categoryIds;
    }
}
