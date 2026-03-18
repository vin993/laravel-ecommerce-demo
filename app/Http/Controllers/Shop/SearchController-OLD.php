<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Webkul\Product\Models\ProductFlat;
use Webkul\Category\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $searchQuery = trim($request->input('q') ?? $request->input('query') ?? '');
        $categorySlug = trim($request->input('category') ?? '');

        // Allow empty search query if category is provided
        if (empty($searchQuery) && empty($categorySlug)) {
            return redirect()->route('shop.home.index')->with('warning', 'Please enter a search keyword or select a category.');
        }

        // Convert empty search query to null for cleaner logic
        $searchQuery = $searchQuery ?: null;

        if ($request->ajax()) {
            return $this->getFilteredProducts($request);
        }

        $page = $request->get('page', 1);
        $cacheKey = 'search_results_' . md5($searchQuery . $categorySlug . serialize($request->except(['_token', 'page']))) . '_page_' . $page;
        $cacheDuration = 1800; // 30 minutes

        $cachedData = Cache::remember($cacheKey, $cacheDuration, function() use ($request, $page) {
            $perPage = 12;

            // CRITICAL FIX: Do NOT run COUNT queries on 632K records - use estimation
            // This prevents server crashes from expensive aggregate queries
            $products = $this->buildProductQuery($request)
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            // Optimize: Calculate stock quantities in bulk with a single query
            $productIds = $products->pluck('product_id')->toArray();
            $stockQuantities = [];
            if (!empty($productIds)) {
                $stockQuantities = DB::table('product_inventories')
                    ->select('product_id', DB::raw('SUM(qty) as total_qty'))
                    ->whereIn('product_id', $productIds)
                    ->groupBy('product_id')
                    ->pluck('total_qty', 'product_id');
            }

            $items = $products->map(function ($product) use ($stockQuantities) {
                $totalQty = $stockQuantities[$product->product_id] ?? 0;
                $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
                $product->stock_quantity = $totalQty;
                return $product;
            });

            // CRITICAL FIX: Estimate total instead of COUNT - prevents server crash
            $total = ($page * $perPage) + ($products->count() == $perPage ? $perPage : 0);

            return [
                'items' => $items,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page
            ];
        });

        $products = new \Illuminate\Pagination\LengthAwarePaginator(
            $cachedData['items'],
            $cachedData['total'],
            $cachedData['per_page'],
            $cachedData['current_page'],
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $filterData = $this->getFilterData($searchQuery, $categorySlug, $request);
        
        $oemBrands = [
            ['name' => 'CFMOTO', 'code' => 'CFMTO', 'logo' => 'CFMTO.png'],
            ['name' => 'Honda Powersports', 'code' => 'HOM', 'logo' => 'HOM.png'],
            ['name' => 'Kawasaki Motorcycle', 'code' => 'KUS', 'logo' => 'KUS.png'],
            ['name' => 'Polaris', 'code' => 'POL', 'logo' => 'POL.png'],
            ['name' => 'Ski-Doo / Sea-Doo / Can-Am', 'code' => 'BRP', 'logo' => 'BRP.png'],
            ['name' => 'Suzuki Motor of America, Inc. – Marine', 'code' => 'SZM', 'logo' => 'SZM.png'],
            ['name' => 'Yamaha', 'code' => 'YAM', 'logo' => 'YAM.png'],
        ];

        return view('search.custom', [
            'products' => $products,
            'query' => $searchQuery,
            'category' => $categorySlug,
            'filterData' => $filterData,
            'oemBrands' => $oemBrands
        ]);
    }

    public function allProducts(Request $request)
    {
        try {
            $categories = Cache::remember('products_page_categories', 86400, function () {
                $locale = core()->getCurrentLocale()->code;

                $menuItems = [
                    ['name' => 'OEM PARTS', 'url' => '/oem-parts', 'description' => 'Original Equipment Manufacturer parts for all vehicles', 'image' => null],
                    ['name' => 'ACCESSORIES', 'slug' => 'accessories', 'description' => 'Enhance your ride with quality accessories', 'image' => 'product/258508/8f6a20849d8dd73f2298853e24daba4e.jpg'],
                    ['name' => 'GEAR', 'slug' => 'gear', 'description' => 'Protective gear and riding apparel', 'image' => 'product/504125/728be674a46a497cadec661f7dc00f97.jpg'],
                    ['name' => 'MAINTENANCE', 'slug' => 'maintenance', 'description' => 'Keep your vehicle running smoothly', 'image' => 'product/247606/8e6ce649c647b52124084dcd95d31ab3.jpg'],
                    ['name' => 'TIRES', 'slug' => 'tires', 'description' => 'Premium tires for all terrains', 'image' => 'product/416296/8b63137ca10640a6b9889bbf45c819c4.jpg'],
                    ['name' => 'DIRT BIKE', 'slug' => 'dirt-bike', 'description' => 'Parts and accessories for dirt bikes', 'image' => 'product/592522/3c3fb9ef38eb45c3bb5e4379d3ea5539.jpg'],
                    ['name' => 'STREET', 'slug' => 'street', 'description' => 'Street motorcycle parts and gear', 'image' => 'product/262258/fc65f96394d7d0af1622b777bbec630b.jpg'],
                    ['name' => 'ATV', 'slug' => 'atv', 'description' => 'All-terrain vehicle parts and accessories', 'image' => 'product/272535/293c832036e0944ca248b6bc463ea0b8.jpg'],
                    ['name' => 'UTV', 'slug' => 'utv', 'description' => 'Utility terrain vehicle components', 'image' => 'product/316188/862fb39abca7851946071ff37b1e1c1f.jpg'],
                    ['name' => 'WATERCRAFT', 'id' => 1374, 'description' => 'Watercraft parts and accessories', 'image' => 'product/61799/139491810d6957a0b73fcd877d07088b.jpg'],
                ];

                $slugs = array_filter(array_column($menuItems, 'slug'));

                $categoryMap = Category::whereHas('translations', function ($query) use ($slugs, $locale) {
                    $query->whereIn('slug', $slugs)->where('locale', $locale);
                })
                ->where('status', 1)
                ->with(['translations' => function ($query) use ($locale) {
                    $query->where('locale', $locale);
                }])
                ->get()
                ->keyBy(function ($cat) use ($locale) {
                    return $cat->translations->first()->slug ?? '';
                });

                $categories = [];
                foreach ($menuItems as $item) {
                    $categoryUrl = null;

                    if (isset($item['url'])) {
                        $categoryUrl = url($item['url']);
                    } elseif (isset($item['id'])) {
                        $category = Category::where('id', $item['id'])
                            ->where('status', 1)
                            ->with(['translations' => function ($query) use ($locale) {
                                $query->where('locale', $locale);
                            }])
                            ->first();

                        if ($category && $category->translations->isNotEmpty()) {
                            $translation = $category->translations->first();
                            $categoryUrl = url($translation->slug ?? '');
                        }
                    } elseif (isset($item['slug'])) {
                        $category = $categoryMap->get($item['slug']);

                        if ($category && $category->translations->isNotEmpty()) {
                            $translation = $category->translations->first();
                            $categoryUrl = url($translation->slug ?? '');
                        }
                    }

                    if ($categoryUrl) {
                        $categories[] = [
                            'name' => $item['name'],
                            'url' => $categoryUrl,
                            'image' => $item['image'],
                            'description' => $item['description']
                        ];
                    }
                }

                return $categories;
            });

            $trendingProducts = Cache::remember('trending_products', 3600, function () {
                return ProductFlat::where('channel', 'maddparts')
                    ->where('locale', 'en')
                    ->where('status', 1)
                    ->with(['product' => function($q) {
                        $q->with(['images' => function($imgQuery) {
                            $imgQuery->select('id', 'path', 'product_id')->limit(1)->orderBy('position', 'asc');
                        }])
                        ->with(['inventories' => function($invQuery) {
                            $invQuery->select('product_id', 'qty');
                        }])
                        ->with(['categories' => function($catQuery) {
                            $catQuery->select('categories.id')->with(['translations' => function($transQuery) {
                                $transQuery->select('category_id', 'name')->where('locale', 'en');
                            }]);
                        }]);
                    }])
                    ->orderBy('product_id','desc')->limit(200)
                    ->limit(8)
                    ->get();
            });

            return view('products.categories', compact('categories', 'trendingProducts'));

        } catch (\Exception $e) {
            \Log::error('Error in allProducts: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            abort(500, 'Unable to load categories');
        }
    }

    private function getAllProductsCachedPageData(Request $request, $page)
    {
        $perPage = 12;
        $maxProducts = 120;

        if (($page - 1) * $perPage >= $maxProducts) {
            return [
                'items' => collect(),
                'total' => $maxProducts,
                'per_page' => $perPage,
                'current_page' => $page
            ];
        }

        $productsToTake = min($perPage, $maxProducts - (($page - 1) * $perPage));

        $products = DB::table('product_flat')
            ->select('id', 'sku', 'name', 'price', 'product_id', 'url_key')
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->where('status', 1)
            ->orderBy('id', 'asc')
            ->skip(($page - 1) * $perPage)
            ->take($productsToTake)
            ->get();

        $productIds = $products->pluck('product_id')->toArray();

        $images = DB::table('product_images')
            ->whereIn('product_id', $productIds)
            ->select('id', 'product_id', 'path')
            ->orderBy('position', 'asc')
            ->get()
            ->groupBy('product_id');

        $stockQuantities = DB::table('product_inventories')
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id');

        $products = $products->map(function ($product) use ($stockQuantities, $images) {
            $totalQty = $stockQuantities[$product->product_id] ?? 0;
            $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
            $product->stock_quantity = $totalQty;

            $productImages = $images->get($product->product_id);
            $imageCollection = collect();

            if ($productImages && $productImages->isNotEmpty()) {
                $firstImage = $productImages->first();
                $imageObj = (object)[
                    'id' => $firstImage->id ?? null,
                    'path' => $firstImage->path,
                    'product_id' => $product->product_id
                ];
                $imageCollection->push($imageObj);
            }

            $product->product = (object)[
                'images' => $imageCollection
            ];

            return $product;
        });

        return [
            'items' => $products,
            'total' => $maxProducts,
            'per_page' => $perPage,
            'current_page' => $page
        ];
    }

    private function getAllProductsFilterData()
    {
        return [
            'brands' => collect(),
            'price_range' => (object)['min_price' => 0, 'max_price' => 5000],
            'categories' => collect(),
            'vehicles' => true
        ];
    }

    public function autocomplete(Request $request)
    {
        $query = $request->input('q', '');
        $categorySlug = $request->input('category');

        if (strlen($query) < 2) {
            return response()->json(['suggestions' => []]);
        }

        $cleanQuery = preg_replace('/[^a-zA-Z0-9]/', '', $query);

        $productsQuery = ProductFlat::where('channel', 'maddparts')
            ->where('locale', 'en')
            ->where('status', 1)
            ->where(function($q) use ($query, $cleanQuery) {
                $q->where('name', 'like', '%' . $query . '%')
                  ->orWhere('sku', 'like', '%' . $query . '%')
                  ->orWhereRaw('REPLACE(REPLACE(REPLACE(sku, "-", ""), " ", ""), "_", "") LIKE ?', ['%' . $cleanQuery . '%']);
            })
            ->selectRaw('product_flat.*, (
                    CASE
                        WHEN LOWER(product_flat.name) = LOWER(?) THEN 1000
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 900
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 800
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 800
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 700
                        WHEN LOWER(product_flat.name) LIKE CONCAT(?, LOWER(?), ?) THEN 600
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 500
                        WHEN product_flat.sku = ? THEN 400
                        WHEN product_flat.sku LIKE ? THEN 300
                        WHEN product_flat.sku LIKE ? THEN 200
                        WHEN REPLACE(REPLACE(REPLACE(product_flat.sku, "-", ""), " ", ""), "_", "") = ? THEN 100
                        WHEN REPLACE(REPLACE(REPLACE(product_flat.sku, "-", ""), " ", ""), "_", "") LIKE ? THEN 50
                        ELSE 1
                    END
                    +
                    COALESCE((
                        SELECT
                            CASE
                                WHEN LOWER(ct_rel.name) = LOWER(?) THEN 500
                                WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 400
                                WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 300
                                ELSE 200
                            END
                        FROM product_categories pc_rel
                        INNER JOIN category_translations ct_rel ON pc_rel.category_id = ct_rel.category_id
                        WHERE pc_rel.product_id = product_flat.product_id
                        AND ct_rel.locale = "en"
                        AND LOWER(ct_rel.name) LIKE LOWER(?)
                        ORDER BY
                            CASE
                                WHEN LOWER(ct_rel.name) = LOWER(?) THEN 1
                                WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 2
                                ELSE 3
                            END
                        LIMIT 1
                    ), 0)
                    -
                    CASE
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 100
                        ELSE 0
                    END
                ) as relevance_score', [
                    $query,
                    $query . ' %',
                    '% ' . $query . ' %',
                    '% ' . $query,
                    $query . '%',
                    '% ', $query, '%',
                    '%' . $query . '%',
                    $query,
                    $query . '%',
                    '%' . $query . '%',
                    $cleanQuery,
                    $cleanQuery . '%',
                    $query,
                    $query . '%',
                    '%' . $query . '%',
                    '%' . $query . '%',
                    $query,
                    $query . '%',
                    '%assembly%'
                ]);

        if ($categorySlug && $categorySlug !== '') {
            $category = Category::whereHas('translations', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            })->first();

            if ($category) {
                $productsQuery->whereHas('product.categories', function ($q) use ($category) {
                    $q->where('categories.id', $category->id);
                });
            }
        }

        $products = $productsQuery->with(['product.images'])
            ->orderBy('relevance_score', 'desc')
            ->orderBy('name', 'asc')
            ->limit(8)
            ->get()
            ->map(function($product) {
                $image = $product->product->images->first();
                $price = $product->price ?: ($product->special_price ?: 0);

                return [
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'price' => $price > 0 ? $price : null,
                    'url' => url($product->url_key),
                    'image' => $image ? asset('storage/' . $image->path) : asset('themes/maddparts/images/placeholder.jpg')
                ];
            });

        return response()->json(['suggestions' => $products]);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:2048'
        ]);

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('search-images', 'public');
            return response()->json(Storage::url($path));
        }

        return response()->json(['error' => 'No image uploaded'], 400);
    }

    public function getFilteredProducts(Request $request)
    {
        $page = $request->get('page', 1);
        $perPage = 12;

        // Build the query once
        $query = $this->buildProductQuery($request);

        // CRITICAL FIX: Do NOT run COUNT on 632K records - use estimation
        // Get paginated products
        $products = $query->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        // Optimize: Calculate stock quantities in bulk with a single query
        $productIds = $products->pluck('product_id')->toArray();
        $stockQuantities = [];
        if (!empty($productIds)) {
            $stockQuantities = DB::table('product_inventories')
                ->select('product_id', DB::raw('SUM(qty) as total_qty'))
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id')
                ->pluck('total_qty', 'product_id');
        }

        $items = $products->map(function ($product) use ($stockQuantities) {
            $totalQty = $stockQuantities[$product->product_id] ?? 0;
            $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
            $product->stock_quantity = $totalQty;

            // Add image URL for JSON response
            $imageUrl = $product->product && $product->product->images && $product->product->images->first()
                ? asset('storage/' . $product->product->images->first()->path)
                : asset('themes/maddparts/images/logo.png');
            $product->image_url = $imageUrl;

            return $product;
        });

        $searchQuery = trim($request->input('q') ?? $request->input('query') ?? '');
        $searchQuery = $searchQuery ?: null;
        $categorySlug = trim($request->input('category') ?? '');
        $filterData = $this->getFilterData($searchQuery, $categorySlug, $request);

        // CRITICAL FIX: Estimate total instead of COUNT
        $total = ($page * $perPage) + ($products->count() == $perPage ? $perPage : 0);
        $lastPage = (int)ceil($total / $perPage);

        return response()->json([
            'success' => true,
            'products' => $items,
            'pagination' => [
                'current_page' => (int)$page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'has_more_pages' => $products->count() == $perPage
            ],
            'filterData' => $filterData
        ]);
    }

    private function buildProductQuery(Request $request)
    {
        $searchQuery = trim($request->input('q') ?? $request->input('query') ?? '');
        $searchQuery = $searchQuery ?: null;
        $categorySlug = trim($request->input('category') ?? '');
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

        $vehicleType = $request->get('vehicle_type');
        $vehicleMake = $request->get('vehicle_make');
        $vehicleModel = $request->get('vehicle_model');
        $vehicleYear = $request->get('vehicle_year');

        $query = ProductFlat::where('channel', 'maddparts')
            ->where('locale', 'en')
            ->where('status', 1)
            ->with([
                'product.images' => function($query) {
                    $query->select('id', 'path', 'product_id')->limit(1);
                },
                'product.inventories' => function($query) {
                    $query->select('product_id', 'qty');
                },
                'product.categories' => function($query) {
                    $query->select('categories.id', 'categories.parent_id');
                }
            ]);

        if ($searchQuery) {
            $cleanSearchQuery = preg_replace('/[^a-zA-Z0-9]/', '', $searchQuery);
            $query->where(function($q) use ($searchQuery, $cleanSearchQuery) {
                $q->where('name', 'like', '%' . $searchQuery . '%')
                  ->orWhere('sku', 'like', '%' . $searchQuery . '%')
                  ->orWhereRaw('REPLACE(REPLACE(REPLACE(sku, "-", ""), " ", ""), "_", "") LIKE ?', ['%' . $cleanSearchQuery . '%']);
            });

            $query->selectRaw('product_flat.*, (
                    CASE
                        WHEN LOWER(product_flat.name) = LOWER(?) THEN 1000
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 900
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 800
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 800
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 700
                        WHEN LOWER(product_flat.name) LIKE CONCAT(?, LOWER(?), ?) THEN 600
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 500
                        WHEN product_flat.sku = ? THEN 400
                        WHEN product_flat.sku LIKE ? THEN 300
                        WHEN product_flat.sku LIKE ? THEN 200
                        WHEN REPLACE(REPLACE(REPLACE(product_flat.sku, "-", ""), " ", ""), "_", "") = ? THEN 100
                        WHEN REPLACE(REPLACE(REPLACE(product_flat.sku, "-", ""), " ", ""), "_", "") LIKE ? THEN 50
                        ELSE 1
                    END
                    +
                    COALESCE((
                        SELECT
                            CASE
                                WHEN LOWER(ct_rel.name) = LOWER(?) THEN 500
                                WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 400
                                WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 300
                                ELSE 200
                            END
                        FROM product_categories pc_rel
                        INNER JOIN category_translations ct_rel ON pc_rel.category_id = ct_rel.category_id
                        WHERE pc_rel.product_id = product_flat.product_id
                        AND ct_rel.locale = "en"
                        AND LOWER(ct_rel.name) LIKE LOWER(?)
                        ORDER BY
                            CASE
                                WHEN LOWER(ct_rel.name) = LOWER(?) THEN 1
                                WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 2
                                ELSE 3
                            END
                        LIMIT 1
                    ), 0)
                    -
                    CASE
                        WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 100
                        ELSE 0
                    END
                ) as relevance_score', [
                    $searchQuery,
                    $searchQuery . ' %',
                    '% ' . $searchQuery . ' %',
                    '% ' . $searchQuery,
                    $searchQuery . '%',
                    '% ', $searchQuery, '%',
                    '%' . $searchQuery . '%',
                    $searchQuery,
                    $searchQuery . '%',
                    '%' . $searchQuery . '%',
                    $cleanSearchQuery,
                    $cleanSearchQuery . '%',
                    $searchQuery,
                    $searchQuery . '%',
                    '%' . $searchQuery . '%',
                    '%' . $searchQuery . '%',
                    $searchQuery,
                    $searchQuery . '%',
                    '%assembly%'
                ]);
        }

        if ($categorySlug && $categorySlug !== '') {
            // Handle category slug with ID suffix (e.g., "riding-apparel-279")
            // Try to extract ID from the end of the slug
            if (preg_match('/^(.+)-(\d+)$/', $categorySlug, $matches)) {
                $possibleSlug = $matches[1];
                $categoryId = $matches[2];

                // Try to find category by ID first (more reliable)
                $category = Category::find($categoryId);

                // If not found by ID, try by full slug
                if (!$category) {
                    $category = Category::whereHas('translations', function ($q) use ($categorySlug) {
                        $q->where('slug', $categorySlug);
                    })->first();
                }
            } else {
                // No ID suffix, search by slug only
                $category = Category::whereHas('translations', function ($q) use ($categorySlug) {
                    $q->where('slug', $categorySlug);
                })->first();
            }

            if ($category) {
                $query->whereHas('product.categories', function ($q) use ($category) {
                    $q->where('categories.id', $category->id);
                });
            } else {
                // Category not found - return no results by adding impossible condition
                $query->whereRaw('1 = 0');
            }
        }

        if (!empty($categories)) {
            $query->whereHas('product.categories', function ($q) use ($categories) {
                $q->whereIn('category_id', $categories);
            });
        }

        if (!empty($brands)) {
            // Convert brand names to manufacturer IDs for filtering
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

        if ($minPrice) {
            $query->where('price', '>=', $minPrice);
        }

        if ($maxPrice) {
            $query->where('price', '<=', $maxPrice);
        }

        if ($inStock) {
            $query->whereHas('product.inventories', function ($q) {
                $q->where('qty', '>', 0);
            });
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

        if ($searchQuery && $sortBy === 'name_az') {
            $query->orderBy('relevance_score', 'desc')->orderBy('name', 'asc');
        } else {
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
                    if ($searchQuery) {
                        $query->orderBy('relevance_score', 'desc')->orderBy('name', 'asc');
                    } else {
                        $query->orderBy('name', 'asc');
                    }
            }
        }

        return $query;
    }

    private function getFilterData($searchQuery = null, $categorySlug = null, $request = null)
    {
        $cacheKey = 'search_filters_' . md5($searchQuery . $categorySlug . serialize($request?->only(['brands', 'categories', 'min_price', 'max_price', 'in_stock'])));
        
        return Cache::remember($cacheKey, 1800, function() use ($searchQuery, $categorySlug, $request) {
            return $this->calculateSearchFilterData($searchQuery, $categorySlug, $request);
        });
    }
    
    private function calculateSearchFilterData($searchQuery = null, $categorySlug = null, $request = null)
    {
        try {
            $query = ProductFlat::where('channel', 'maddparts')
                ->where('locale', 'en')
                ->where('status', 1);

        $categoryFound = true;

        if ($searchQuery) {
            $cleanSearchQuery = preg_replace('/[^a-zA-Z0-9]/', '', $searchQuery);
            $query->where(function($q) use ($searchQuery, $cleanSearchQuery) {
                $q->where('name', 'like', '%' . $searchQuery . '%')
                  ->orWhere('sku', 'like', '%' . $searchQuery . '%')
                  ->orWhereRaw('REPLACE(REPLACE(REPLACE(sku, "-", ""), " ", ""), "_", "") LIKE ?', ['%' . $cleanSearchQuery . '%']);
            });
        }

        if ($categorySlug) {
            // Handle category slug with ID suffix (e.g., "riding-apparel-279")
            if (preg_match('/^(.+)-(\d+)$/', $categorySlug, $matches)) {
                $possibleSlug = $matches[1];
                $categoryId = $matches[2];

                // Try to find category by ID first (more reliable)
                $category = Category::find($categoryId);

                // If not found by ID, try by full slug
                if (!$category) {
                    $category = Category::whereHas('translations', function ($q) use ($categorySlug) {
                        $q->where('slug', $categorySlug);
                    })->first();
                }
            } else {
                // No ID suffix, search by slug only
                $category = Category::whereHas('translations', function ($q) use ($categorySlug) {
                    $q->where('slug', $categorySlug);
                })->first();
            }

            if ($category) {
                $query->whereHas('product.categories', function ($q) use ($category) {
                    $q->where('categories.id', $category->id);
                });
            } else {
                // Category not found - set flag to return empty results
                $categoryFound = false;
            }
        }

        if ($request) {
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
            $vehicleType = $request->get('vehicle_type');
            $vehicleMake = $request->get('vehicle_make');
            $vehicleModel = $request->get('vehicle_model');
            $vehicleYear = $request->get('vehicle_year');

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
                $query->whereHas('product.inventories', function ($q) {
                    $q->where('qty', '>', 0);
                });
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
        }

        // If category was not found, return empty filter data
        if (!$categoryFound) {
            return [
                'brands' => collect(),
                'price_range' => (object)['min_price' => 0, 'max_price' => 0],
                'categories' => collect(),
                'vehicles' => true
            ];
        }

        // Get filtered product IDs based on search query and category
        $filteredProductIds = $query->pluck('product_id')->toArray();

        // Calculate brands only for filtered products
        $brands = collect();
        if (!empty($filteredProductIds)) {
            $brands = DB::table('product_attribute_values as pav')
                ->join('ds_manufacturer_index as mi', 'pav.text_value', '=', 'mi.manufacturer_id')
                ->join('product_flat as pf', 'pav.product_id', '=', 'pf.product_id')
                ->select('mi.manufacturer_name as brand', DB::raw('count(DISTINCT pav.product_id) as product_count'))
                ->where('pav.attribute_id', 25)
                ->where('pf.channel', 'maddparts')
                ->where('pf.locale', 'en')
                ->where('pf.status', 1)
                ->whereIn('pav.product_id', $filteredProductIds)
                ->whereNotNull('pav.text_value')
                ->where('pav.text_value', '!=', '')
                ->groupBy('mi.manufacturer_name')
                ->orderBy('product_count', 'desc')
                ->limit(20)
                ->get();
        }

        // Calculate price range only for filtered products
        $priceRange = (object)['min_price' => 0, 'max_price' => 0];
        if (!empty($filteredProductIds)) {
            $priceRange = ProductFlat::whereIn('product_id', $filteredProductIds)
                ->where('channel', 'maddparts')
                ->where('locale', 'en')
                ->where('status', 1)
                ->selectRaw('ROUND(MIN(price), 2) as min_price, ROUND(MAX(price), 2) as max_price')
                ->first();
        }

        // Calculate categories only for filtered products
        $categories = collect();
        if (!empty($filteredProductIds)) {
            $categories = DB::table('product_flat as pf')
                ->join('product_categories as pc', 'pf.product_id', '=', 'pc.product_id')
                ->join('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
                ->select('ct.category_id as id', 'ct.name', DB::raw('COUNT(DISTINCT pf.product_id) as product_count'))
                ->where('pf.channel', 'maddparts')
                ->where('pf.locale', 'en')
                ->where('pf.status', 1)
                ->where('ct.locale', 'en')
                ->whereIn('pf.product_id', $filteredProductIds)
                ->groupBy('ct.category_id', 'ct.name')
                ->orderBy('product_count', 'desc')
                ->limit(20)
                ->get()
                ->map(function ($cat) {
                    return (object)[
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'product_count' => $cat->product_count
                    ];
                });
        }

            return [
                'brands' => $brands,
                'price_range' => $priceRange,
                'categories' => $categories,
                'vehicles' => true
            ];
        } catch (\Exception $e) {
            \Log::error('Error in calculateSearchFilterData: ' . $e->getMessage());
            return [
                'brands' => collect(),
                'price_range' => (object)['min_price' => 0, 'max_price' => 0],
                'categories' => collect(),
                'vehicles' => true
            ];
        }
    }

    public function brands()
    {
        $brands = DB::table('product_attribute_values as pav')
            ->join('ds_manufacturer_index as mi', 'pav.text_value', '=', 'mi.manufacturer_id')
            ->join('product_flat as pf', 'pav.product_id', '=', 'pf.product_id')
            ->select('mi.manufacturer_name as brand', 'mi.logo_path', DB::raw('count(DISTINCT pav.product_id) as product_count'))
            ->where('pav.attribute_id', 25)
            ->where('pf.channel', 'maddparts')
            ->where('pf.locale', 'en')
            ->where('pf.status', 1)
            ->whereNotNull('pav.text_value')
            ->where('pav.text_value', '!=', '')
            ->groupBy('mi.manufacturer_name', 'mi.logo_path')
            ->orderBy('mi.manufacturer_name', 'asc')
            ->get();

        return view('brands.index', compact('brands'));
    }

    public function brandProducts(Request $request, $brand)
    {
        if ($request->ajax()) {
            return $this->getBrandFilteredProducts($request, $brand);
        }

        $page = $request->get('page', 1);
        $cacheKey = 'brand_products_' . md5($brand . serialize($request->except(['_token', 'page']))) . '_page_' . $page;
        $cacheDuration = 1800; // 30 minutes

        $cachedData = Cache::remember($cacheKey, $cacheDuration, function() use ($request, $brand, $page) {
            $perPage = 12;

            // Get total count first
            $query = $this->buildBrandProductQuery($request, $brand);
            $countQuery = clone $query;
            $total = $countQuery->select('product_flat.product_id')
                ->distinct()
                ->count('product_flat.product_id');

            $products = $query->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            // Optimize: Calculate stock quantities in bulk with a single query
            $productIds = $products->pluck('product_id')->toArray();
            $stockQuantities = DB::table('product_inventories')
                ->select('product_id', DB::raw('SUM(qty) as total_qty'))
                ->whereIn('product_id', $productIds)
                ->groupBy('product_id')
                ->pluck('total_qty', 'product_id');

            $items = $products->map(function ($product) use ($stockQuantities) {
                $totalQty = $stockQuantities[$product->product_id] ?? 0;
                $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
                $product->stock_quantity = $totalQty;
                return $product;
            });

            return [
                'items' => $items,
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page
            ];
        });

        $products = new \Illuminate\Pagination\LengthAwarePaginator(
            $cachedData['items'],
            $cachedData['total'],
            $cachedData['per_page'],
            $cachedData['current_page'],
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $filterData = $this->getBrandFilterData($brand, $request);

        return view('brands.products', [
            'products' => $products,
            'brandName' => $brand,
            'filterData' => $filterData
        ]);
    }

    public function getBrandFilteredProducts(Request $request, $brand)
    {
        $products = $this->buildBrandProductQuery($request, $brand)->paginate(12);

        // Optimize: Calculate stock quantities in bulk with a single query
        $productIds = $products->pluck('product_id')->toArray();
        $stockQuantities = DB::table('product_inventories')
            ->select('product_id', DB::raw('SUM(qty) as total_qty'))
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->pluck('total_qty', 'product_id');

        $products->getCollection()->transform(function ($product) use ($stockQuantities) {
            $totalQty = $stockQuantities[$product->product_id] ?? 0;
            $product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
            $product->stock_quantity = $totalQty;
            return $product;
        });

        $filterData = $this->getBrandFilterData($brand, $request);

        $productsArray = $products->map(function($product) {
            $imageUrl = $product->product && $product->product->images && $product->product->images->first()
                ? asset('storage/' . $product->product->images->first()->path)
                : asset('themes/maddparts/images/logo.png');

            return [
                'id' => $product->id,
                'product_id' => $product->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $product->price,
                'url_key' => $product->url_key,
                'stock_status' => $product->stock_status,
                'stock_quantity' => $product->stock_quantity,
                'image_url' => $imageUrl,
            ];
        });

        return response()->json([
            'success' => true,
            'products' => $productsArray,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'has_more_pages' => $products->hasMorePages()
            ],
            'filterData' => $filterData
        ]);
    }

    private function buildBrandProductQuery(Request $request, $brand)
    {
        $sortBy = $request->get('sort', 'name_az');
        $categories = $request->get('categories', []);
        if (is_string($categories)) {
            $categories = [$categories];
        }
        $minPrice = $request->get('min_price');
        $maxPrice = $request->get('max_price');
        $inStock = $request->get('in_stock');
        $vehicleType = $request->get('vehicle_type');
        $vehicleMake = $request->get('vehicle_make');
        $vehicleModel = $request->get('vehicle_model');
        $vehicleYear = $request->get('vehicle_year');

        $manufacturerId = DB::table('ds_manufacturer_index')
            ->where('manufacturer_name', $brand)
            ->value('manufacturer_id');

        if (!$manufacturerId) {
            return ProductFlat::where('channel', 'maddparts')->whereRaw('1=0');
        }

        $productIdsWithBrand = DB::table('product_attribute_values')
            ->where('attribute_id', 25)
            ->where('text_value', $manufacturerId)
            ->pluck('product_id');

        $query = ProductFlat::where('channel', 'maddparts')
            ->where('locale', 'en')
            ->where('status', 1)
            ->whereIn('product_id', $productIdsWithBrand)
            ->with([
                'product.images' => function($query) {
                    $query->select('id', 'path', 'product_id')->limit(1);
                },
                'product.inventories' => function($query) {
                    $query->select('product_id', 'qty');
                },
                'product.categories' => function($query) {
                    $query->select('categories.id', 'categories.parent_id');
                }
            ]);

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
            $query->whereHas('product.inventories', function ($q) {
                $q->where('qty', '>', 0);
            });
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
                $query->orderBy('name', 'asc'); // Default to Name A-Z
        }

        return $query;
    }

    private function getBrandFilterData($brand, $request = null)
    {
        $manufacturerId = DB::table('ds_manufacturer_index')
            ->where('manufacturer_name', $brand)
            ->value('manufacturer_id');

        if (!$manufacturerId) {
            return [
                'brands' => collect(),
                'categories' => collect(),
                'price_range' => (object)['min_price' => 0, 'max_price' => 0],
                'vehicles' => true
            ];
        }

        $productIdsWithBrand = DB::table('product_attribute_values')
            ->where('attribute_id', 25)
            ->where('text_value', $manufacturerId)
            ->pluck('product_id');

        $query = ProductFlat::where('channel', 'maddparts')
            ->where('locale', 'en')
            ->where('status', 1)
            ->whereIn('product_id', $productIdsWithBrand);

        if ($request) {
            $categories = $request->get('categories', []);
            if (is_string($categories)) {
                $categories = [$categories];
            }
            $minPrice = $request->get('min_price');
            $maxPrice = $request->get('max_price');
            $inStock = $request->get('in_stock');
            $vehicleType = $request->get('vehicle_type');
            $vehicleMake = $request->get('vehicle_make');
            $vehicleModel = $request->get('vehicle_model');
            $vehicleYear = $request->get('vehicle_year');

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
                $query->whereHas('product.inventories', function ($q) {
                    $q->where('qty', '>', 0);
                });
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
        }

        $productIds = $query->pluck('product_id')->toArray();

        $priceRange = ProductFlat::whereIn('product_id', $productIds)
            ->selectRaw('ROUND(MIN(price), 2) as min_price, ROUND(MAX(price), 2) as max_price')
            ->first();

        $categories = DB::table('product_flat as pf')
            ->join('product_categories as pc', 'pf.product_id', '=', 'pc.product_id')
            ->join('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
            ->select('ct.category_id as id', 'ct.name', DB::raw('COUNT(DISTINCT pf.product_id) as product_count'))
            ->where('pf.channel', 'maddparts')
            ->where('pf.locale', 'en')
            ->where('pf.status', 1)
            ->where('ct.locale', 'en')
            ->whereIn('pf.product_id', $productIds)
            ->groupBy('ct.category_id', 'ct.name')
            ->orderBy('product_count', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($cat) {
                return (object)[
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'product_count' => $cat->product_count
                ];
            });

        return [
            'brands' => collect(),
            'categories' => $categories,
            'price_range' => $priceRange,
            'vehicles' => true
        ];
    }
}
