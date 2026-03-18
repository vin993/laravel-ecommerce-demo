<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductFlat;

class KawasakiProductController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductFlat::query()
            ->where('product_flat.status', 1)
            ->where('product_flat.visible_individually', 1)
            ->whereNull('product_flat.parent_id')
            ->where('product_flat.channel', 'maddparts')
            ->where('product_flat.locale', 'en')
            ->join('product_attribute_values as brand_attr', function($join) {
                $join->on('product_flat.product_id', '=', 'brand_attr.product_id')
                    ->where('brand_attr.attribute_id', 25)
                    ->where('brand_attr.channel', 'maddparts')
                    ->where('brand_attr.locale', 'en')
                    ->where('brand_attr.text_value', 'Kawasaki');
            })
            ->with(['product.images'])
            ->select('product_flat.*');

        // Apply filters
        if ($request->has('categories') && is_array($request->categories) && count($request->categories) > 0) {
            $query->join('product_categories', 'product_flat.product_id', '=', 'product_categories.product_id')
                  ->whereIn('product_categories.category_id', $request->categories);
        }

        if ($request->has('min_price') && $request->min_price) {
            $query->where('product_flat.price', '>=', $request->min_price);
        }

        if ($request->has('max_price') && $request->max_price) {
            $query->where('product_flat.price', '<=', $request->max_price);
        }

        if ($request->has('in_stock') && $request->in_stock) {
            // Add stock filter if needed
        }

        // Apply sorting
        $sort = $request->get('sort', 'newest');
        switch ($sort) {
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
            case 'oldest':
                $query->orderBy('created_at', 'asc');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
        }

        // Cache the total count to avoid expensive COUNT(*) query on every page load
        // Only use cached count when NO filters are applied
        $hasFilters = ($request->has('categories') && is_array($request->categories) && count($request->categories) > 0)
                   || ($request->has('min_price') && $request->min_price)
                   || ($request->has('max_price') && $request->max_price);
        
        if ($hasFilters) {
            // When filters are applied, we need to count the filtered results
            // Clone the query before groupBy to get accurate count
            $countQuery = clone $query;
            $totalCount = DB::table(DB::raw("({$countQuery->toSql()}) as sub"))
                ->mergeBindings($countQuery->getQuery())
                ->count();
        } else {
            // Use cached count for unfiltered results
            $totalCount = \Cache::remember('kawasaki_products_total_count', 3600, function() {
                return DB::table('product_flat')
                    ->where('product_flat.status', 1)
                    ->where('product_flat.visible_individually', 1)
                    ->whereNull('product_flat.parent_id')
                    ->where('product_flat.channel', 'maddparts')
                    ->where('product_flat.locale', 'en')
                    ->join('product_attribute_values as brand_attr', function($join) {
                        $join->on('product_flat.product_id', '=', 'brand_attr.product_id')
                            ->where('brand_attr.attribute_id', 25)
                            ->where('brand_attr.channel', 'maddparts')
                            ->where('brand_attr.locale', 'en')
                            ->where('brand_attr.text_value', 'Kawasaki');
                    })
                    ->distinct()
                    ->count('product_flat.id');
            });
        }

        // Group by to prevent duplicates from joins
        $query->groupBy('product_flat.id');

        // Use custom pagination with cached count
        $perPage = 24;
        $currentPage = request()->get('page', 1);
        $products = $query->forPage($currentPage, $perPage)->get();
        
        // Create paginator manually with cached count
        $products = new \Illuminate\Pagination\LengthAwarePaginator(
            $products,
            $totalCount,
            $perPage,
            $currentPage,
            ['path' => request()->url(), 'query' => request()->query()]
        );

        $products->getCollection()->transform(function ($product) {
            $productImage = $product->product->images->first();
            
            if ($productImage) {
                // Check if additional_data contains a Kawasaki URL
                if (!empty($productImage->additional_data)) {
                    $additionalData = json_decode($productImage->additional_data, true);
                    if (isset($additionalData['kawasaki_image_url'])) {
                        // Use the Kawasaki URL directly
                        $product->image_url = $additionalData['kawasaki_image_url'];
                    } else {
                        // Fallback to path check
                        if (str_starts_with($productImage->path, 'http://') || str_starts_with($productImage->path, 'https://')) {
                            $product->image_url = $productImage->path;
                        } else {
                            $product->image_url = asset('storage/' . $productImage->path);
                        }
                    }
                } else {
                    // No additional_data, check if path is already a URL
                    if (str_starts_with($productImage->path, 'http://') || str_starts_with($productImage->path, 'https://')) {
                        $product->image_url = $productImage->path;
                    } else {
                        $product->image_url = asset('storage/' . $productImage->path);
                    }
                }
            } else {
                $product->image_url = asset('themes/maddparts/images/placeholder.jpg');
            }
            
            return $product;
        });

        // Get filter data
        $filterData = $this->getFilterData();

        // If AJAX request, return JSON
        if ($request->ajax() || $request->wantsJson()) {
            // Transform products for JSON response with explicit field mapping
            $productsArray = [];
            foreach ($products->items() as $product) {
                $imageUrl = asset('themes/maddparts/images/placeholder.jpg');
                
                if (isset($product->product->images) && $product->product->images->count() > 0) {
                    $productImage = $product->product->images->first();
                    // Check if additional_data contains a Kawasaki URL
                    if (!empty($productImage->additional_data)) {
                        $additionalData = json_decode($productImage->additional_data, true);
                        if (isset($additionalData['kawasaki_image_url'])) {
                            $imageUrl = $additionalData['kawasaki_image_url'];
                        } else {
                            // Fallback to path check
                            if (str_starts_with($productImage->path, 'http://') || str_starts_with($productImage->path, 'https://')) {
                                $imageUrl = $productImage->path;
                            } else {
                                $imageUrl = asset('storage/' . $productImage->path);
                            }
                        }
                    } else {
                        // No additional_data, check if path is already a URL
                        if (str_starts_with($productImage->path, 'http://') || str_starts_with($productImage->path, 'https://')) {
                            $imageUrl = $productImage->path;
                        } else {
                            $imageUrl = asset('storage/' . $productImage->path);
                        }
                    }
                }
                
                // Explicitly map all required fields
                $productsArray[] = [
                    'id' => $product->product_id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'url_key' => $product->url_key,
                    'price' => $product->price,
                    'image_url' => $imageUrl,
                    'stock_status' => 'in_stock', // You can add actual stock logic here if needed
                ];
            }
            
            return response()->json([
                'success' => true,
                'products' => $productsArray,
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
                'filterData' => $filterData
            ]);
        }

        return view('kawasaki.products', compact('products', 'filterData'));
    }

    private function getFilterData()
    {
        // Cache filter data for 1 hour to avoid expensive queries on every page load
        return \Cache::remember('kawasaki_filter_data', 3600, function() {
            // Get price range for Kawasaki products only
            $priceRange = DB::table('product_flat')
                ->join('product_attribute_values as brand_attr', function($join) {
                    $join->on('product_flat.product_id', '=', 'brand_attr.product_id')
                        ->where('brand_attr.attribute_id', 25)
                        ->where('brand_attr.channel', 'maddparts')
                        ->where('brand_attr.locale', 'en')
                        ->where('brand_attr.text_value', 'Kawasaki');
                })
                ->where('product_flat.status', 1)
                ->whereNull('product_flat.parent_id')
                ->where('product_flat.channel', 'maddparts')
                ->where('product_flat.locale', 'en')
                ->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
                ->first();

            // Get categories for Kawasaki products
            $categories = DB::table('categories')
                ->join('category_translations', 'categories.id', '=', 'category_translations.category_id')
                ->join('product_categories', 'categories.id', '=', 'product_categories.category_id')
                ->join('product_flat', 'product_categories.product_id', '=', 'product_flat.product_id')
                ->join('product_attribute_values as brand_attr', function($join) {
                    $join->on('product_flat.product_id', '=', 'brand_attr.product_id')
                        ->where('brand_attr.attribute_id', 25)
                        ->where('brand_attr.channel', 'maddparts')
                        ->where('brand_attr.locale', 'en')
                        ->where('brand_attr.text_value', 'Kawasaki');
                })
                ->where('product_flat.status', 1)
                ->whereNull('product_flat.parent_id')
                ->where('category_translations.locale', 'en')
                ->where('category_translations.name', '!=', 'Accessory Catalogs') // Exclude promotional materials
                ->select('categories.id', 'category_translations.name', DB::raw('COUNT(DISTINCT product_flat.product_id) as product_count'))
                ->groupBy('categories.id', 'category_translations.name')
                ->having('product_count', '>', 0)
                ->orderBy('category_translations.name')
                ->get();

            return [
                'brands' => [], 
                'categories' => $categories,
                'price_range' => $priceRange
            ];
        });
    }

    public function filter(Request $request)
    {
        return $this->index($request);
    }
}
