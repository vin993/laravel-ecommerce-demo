<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Webkul\Product\Models\ProductFlat;
use Webkul\Category\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

		// SEARCH CACHE DISABLED - Dropshipper prices must always be fresh
		// Client requirement: All pages must show consistent real-time pricing from dropshipper APIs
		// Caching caused price mismatches between search results and product detail pages
		/*
		$cacheKey = 'search_results_' . md5($searchQuery . $categorySlug . serialize($request->except(['_token', 'page']))) . '_page_' . $page;
		$cacheDuration = 1800; // 30 minutes
		$cachedData = Cache::remember($cacheKey, $cacheDuration, function() use ($request, $page) {
		*/

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

		$cachedData = [
			'items' => $items,
			'total' => $total,
			'per_page' => $perPage,
			'current_page' => $page
		];

		// }); // End of Cache::remember (commented out)

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
			['name' => 'Kawasaki', 'code' => 'KUS', 'logo' => 'KUS.png'],
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
						}])
						->with(['variants' => function($variantQuery) {
							$variantQuery->with(['images' => function($imgQuery) {
								$imgQuery->select('id', 'path', 'product_id')->limit(1)->orderBy('position', 'asc');
							}])
							->with(['product_flats' => function($flatQuery) {
								$flatQuery->where('channel', 'maddparts')->where('locale', 'en');
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

		try {
			$products = \App\Http\Controllers\Shop\SearchControllerEnhanced::buildTagBasedProductQuery($request)
				->with(['product' => function($q) {
					$q->select('id', 'type', 'sku')->with(['images' => function($imgQuery) {
						$imgQuery->select('id', 'path', 'product_id')->limit(1)->orderBy('position', 'asc');
					}]);
				}])
				->limit(8)
				->get()
				->map(function($product) {
					$image = $product->product && $product->product->images ? $product->product->images->first() : null;

					$productType = $product->product ? $product->product->type : 'simple';
					$price = $product->price ?? 0;
					$price = (float) $price;

					if ($productType === 'configurable' || $productType === 'bundle' || $productType === 'grouped') {
						$priceDisplay = 'See options';
					} elseif ($price > 0) {
						$priceDisplay = '$' . number_format($price, 2);
					} else {
						$priceDisplay = 'Call for price';
					}

					return [
						'name' => $product->name,
						'url' => url($product->url_key),
						'price' => (string) $priceDisplay,
						'image' => $image ? $image->url : asset('themes/shop/default/assets/images/product/meduim-product-placeholder.png'),
						'sku' => $product->sku
					];
				});

			return response()->json(['suggestions' => $products]);
		} catch (\Exception $e) {
			\Log::error('Autocomplete error: ' . $e->getMessage() . ' | Line: ' . $e->getLine() . ' | File: ' . $e->getFile());
			return response()->json(['suggestions' => [], 'error' => $e->getMessage()], 500);
		}
	}

	public function autocomplete_old_backup(Request $request)
	{
		$query = $request->input('q', '');
		$categorySlug = $request->input('category');

		if (strlen($query) < 2) {
			return response()->json(['suggestions' => []]);
		}

		$cleanQuery = preg_replace('/[^a-zA-Z0-9]/', '', $query);
		$searchWords = array_filter(explode(' ', trim($query)));
		$lowerQuery = strtolower(trim($query));

		// Normalize search query for common plural/singular variations
		$normalizedQuery = $query;
		$isTireSearch = false;
		$isHelmetSearch = false;
		$lowerQuery = strtolower($query);

		if ($lowerQuery === 'tires' || $lowerQuery === 'tire') {
			$normalizedQuery = 'tire';
			$isTireSearch = true;
		} elseif ($lowerQuery === 'helmets' || $lowerQuery === 'helmet') {
			$normalizedQuery = 'helmet';
			$isHelmetSearch = true;
		} elseif ($lowerQuery === 'oils') {
			$normalizedQuery = 'oil';
		}

		$isBrandSearch = false;
		$brandOptionIds = [];

		$allBrands = DB::table('attribute_options')
			->where('attribute_id', 25)
			->pluck('admin_name', 'id')
			->toArray();

		foreach ($allBrands as $id => $brandName) {
			$lowerBrandName = strtolower($brandName);
			if (strpos($lowerQuery, $lowerBrandName) !== false) {
				$brandOptionIds[] = $id;
				$isBrandSearch = true;

				if (strpos($lowerQuery, 'tire') !== false || strpos($lowerQuery, 'tyre') !== false) {
					$isTireSearch = true;
				}
				if (strpos($lowerQuery, 'helmet') !== false) {
					$isHelmetSearch = true;
				}
				break;
			}
		}

		$productsQuery = ProductFlat::where('channel', 'maddparts')
			->where('locale', 'en')
			->where('status', 1);

		if ($isBrandSearch && !empty($brandOptionIds)) {
			$productsQuery->whereHas('product.attribute_values', function($attrQuery) use ($brandOptionIds) {
				$attrQuery->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
			});

			if ($isTireSearch) {
				$productsQuery->where(function($tireFilter) {
					$tireFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%tire%'])
						->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%tyre%'])
						->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tire%'])
						->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tyre%'])
						->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tire%'])
						->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tyre%']);
				});
			} elseif ($isHelmetSearch) {
				if (empty($categorySlug)) {
					$productsQuery->whereHas('product.categories', function($catQuery) {
						$catQuery->whereHas('translations', function($transQuery) {
							$transQuery->whereRaw('LOWER(name) LIKE ?', ['%helmet%']);
						});
					});
				}

				$productsQuery->where(function($helmetFilter) {
					$helmetFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%helmet%'])
						->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%helmet%'])
						->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%helmet%']);
				});
			}

			$productsQuery->selectRaw('product_flat.*,
                (CASE
                    WHEN LOWER(name) LIKE ? THEN 100
                    WHEN LOWER(name) LIKE ? THEN 90
                    WHEN LOWER(short_description) LIKE ? THEN 70
                    ELSE 50
                END) as relevance_score',
				['%' . strtolower($normalizedQuery) . '%', '%' . strtolower($normalizedQuery) . '%', '%' . strtolower($normalizedQuery) . '%']
			)->orderByDesc('relevance_score');
		} elseif ($isTireSearch) {
			$productsQuery->where(function($tireFilter) {
				$tireFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%tire%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%tyre%'])
					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tire%'])
					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tyre%'])
					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tire%'])
					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tyre%']);
			});

			$productsQuery->selectRaw('product_flat.*,
                (CASE
                    WHEN LOWER(name) LIKE ? THEN 100
                    WHEN LOWER(name) LIKE ? THEN 90
                    WHEN LOWER(short_description) LIKE ? THEN 70
                    ELSE 50
                END) as relevance_score',
				['%tire %', '%tire%', '%tire%']
			)->orderByDesc('relevance_score');
		} elseif ($isHelmetSearch) {
			if (empty($categorySlug)) {
				$productsQuery->whereHas('product.categories', function($catQuery) {
					$catQuery->whereHas('translations', function($transQuery) {
						$transQuery->whereRaw('LOWER(name) LIKE ?', ['%helmet%']);
					});
				});
			}

			$productsQuery->where(function($helmetFilter) use ($query, $normalizedQuery, $brandOptionIds) {
				$helmetFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%' . $normalizedQuery . '%'])
					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%' . $normalizedQuery . '%'])
					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%' . $normalizedQuery . '%']);

				if (!empty($brandOptionIds)) {
					$helmetFilter->orWhereHas('product.attribute_values', function($attrQuery) use ($brandOptionIds) {
						$attrQuery->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
					});
				}
			});

			$productsQuery->selectRaw('product_flat.*,
                (CASE
                    WHEN LOWER(name) LIKE ? THEN 100
                    WHEN LOWER(name) LIKE ? THEN 90
                    WHEN LOWER(short_description) LIKE ? THEN 70
                    ELSE 50
                END) as relevance_score',
				['%helmet %', '%helmet%', '%helmet%']
			)->orderByDesc('relevance_score');
		} else {
			$productsQuery->where(function($q) use ($query, $normalizedQuery, $cleanQuery, $searchWords, $brandOptionIds) {
				$q->where(function($exactMatch) use ($query, $normalizedQuery, $cleanQuery) {
					$exactMatch->where('name', 'like', '%' . $normalizedQuery . '%')
						->orWhere('sku', 'like', '%' . $query . '%')
						->orWhere('short_description', 'like', '%' . $normalizedQuery . '%')
						->orWhere('description', 'like', '%' . $normalizedQuery . '%')
						->orWhereRaw('REPLACE(REPLACE(REPLACE(sku, "-", ""), " ", ""), "_", "") LIKE ?', ['%' . $cleanQuery . '%']);
				});

				if (!empty($brandOptionIds)) {
					$q->orWhereHas('product.attribute_values', function($attrQuery) use ($brandOptionIds) {
						$attrQuery->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
					});
				}

				if (count($searchWords) > 1) {
					$q->orWhere(function($multiWord) use ($searchWords) {
						foreach ($searchWords as $word) {
							$multiWord->where(function($wordQuery) use ($word) {
								$wordQuery->where('name', 'like', '%' . $word . '%')
									->orWhere('sku', 'like', '%' . $word . '%')
									->orWhere('short_description', 'like', '%' . $word . '%')
									->orWhere('description', 'like', '%' . $word . '%');
							});
						}
					});
				}
			});

			$productsQuery->selectRaw('product_flat.*,
                (CASE
                    WHEN LOWER(name) = ? THEN 100
                    WHEN LOWER(name) LIKE ? THEN 90
                    WHEN name LIKE ? THEN 80
                    WHEN LOWER(sku) = ? THEN 75
                    WHEN sku LIKE ? THEN 70
                    WHEN LOWER(short_description) LIKE ? THEN 50
                    ELSE 10
                END) as relevance_score',
				[strtolower($normalizedQuery),
					strtolower($normalizedQuery) . '%',
					'%' . $normalizedQuery . '%',
					strtolower($query),
					'%' . $query . '%',
					'%' . strtolower($normalizedQuery) . '%']
			)->orderByDesc('relevance_score');
		}

		if ($lowerQuery === 'oil' || $lowerQuery === 'oils') {
			$productsQuery->where(function($oilFilter) {
				$oilFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%engine oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%motor oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%transmission oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%fork oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%shock oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%gear oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%2-cycle oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%4-cycle oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%2-stroke oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%4-stroke oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%synthetic oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%chain lube%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%chain oil%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%brake fluid%'])
					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%coolant%']);
			})
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% kit%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%kit %'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%kits%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%seal%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%filter%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% cap%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%drain%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% plug%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% pan%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% pump%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%cooler%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% tank%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% line%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%spout%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%funnel%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%hose%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%deflector%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%dipstick%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%gauge%'])
			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%foil%']);
		} elseif (in_array($lowerQuery, ['tire', 'tires', 'tyre', 'tyres'])) {
			$productsQuery->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire iron%'])
				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire gauge%'])
				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire pressure%'])
				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire repair%'])
				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire tool%'])
				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire changer%'])
				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire lever%']);
		}

		$productsQuery->selectRaw('product_flat.*, (
                    CASE
                        WHEN LOWER(product_flat.name) LIKE "% helmet" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%wheel and tire%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%radial tire%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%tweel%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%engine oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%motor oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%synthetic oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%transmission oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%fork oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%shock oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%gear oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%2-cycle oil%" THEN 10000
                        WHEN LOWER(product_flat.name) LIKE "%4-cycle oil%" THEN 10000
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
				$categoryIds = [$category->id];

				$childCategories = Category::where('parent_id', $category->id)->pluck('id')->toArray();
				if (!empty($childCategories)) {
					$categoryIds = array_merge($categoryIds, $childCategories);

					$grandchildCategories = Category::whereIn('parent_id', $childCategories)->pluck('id')->toArray();
					if (!empty($grandchildCategories)) {
						$categoryIds = array_merge($categoryIds, $grandchildCategories);
					}
				}

				$productsQuery->whereHas('product.categories', function ($q) use ($categoryIds) {
					$q->whereIn('categories.id', $categoryIds);
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
					'image' => $image ? $image->url : asset('themes/maddparts/images/placeholder.jpg')
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
		$inStock = $request->get('in_stock') === 'true';

		// Build the query once
		$query = $this->buildProductQuery($request);

		// When filtering by stock, fetch more products than needed
		$fetchMultiplier = $inStock ? 5 : 1;
		$fetchCount = $perPage * $fetchMultiplier;

		// CRITICAL FIX: Do NOT run COUNT on 632K records - use estimation
		// Get paginated products
		$products = $query->skip(($page - 1) * $fetchCount)
			->take($fetchCount)
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

		$parentProductSkus = $products->filter(function($product) {
			return strpos($product->sku, '-PARENT') !== false;
		})->pluck('sku')->toArray();

		$variantPrices = [];
		if (!empty($parentProductSkus)) {
			$baseSkus = array_map(function($sku) {
				return str_replace('-PARENT', '', $sku);
			}, $parentProductSkus);

			$variantPricesRaw = DB::table('product_flat')
				->select('sku', 'price')
				->where('channel', 'maddparts')
				->where('locale', 'en')
				->where('type', 'simple')
				->whereRaw('CAST(price AS DECIMAL(10,2)) > 0')
				->where(function($q) use ($baseSkus) {
					foreach ($baseSkus as $baseSku) {
						$q->orWhere('sku', 'LIKE', $baseSku . '%');
					}
				})
				->get();

			foreach ($variantPricesRaw as $variant) {
				foreach ($baseSkus as $baseSku) {
					if (strpos($variant->sku, $baseSku) === 0 && $variant->sku !== $baseSku . '-PARENT') {
						$parentSku = $baseSku . '-PARENT';
						if (!isset($variantPrices[$parentSku]) || $variant->price < $variantPrices[$parentSku]) {
							$variantPrices[$parentSku] = $variant->price;
						}
					}
				}
			}
		}

		$items = $products->map(function ($product) use ($stockQuantities, $variantStockQuantities, $variantPrices) {
			$totalQty = $stockQuantities[$product->product_id] ?? 0;

			if (strpos($product->sku, '-PARENT') !== false && isset($variantStockQuantities[$product->product_id])) {
				$totalQty = max($totalQty, $variantStockQuantities[$product->product_id]);
			}

			$product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
			$product->stock_quantity = $totalQty;

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

			$product->image_url = $imageUrl;

			if (strpos($product->sku, '-PARENT') !== false && isset($variantPrices[$product->sku])) {
				$product->price = $variantPrices[$product->sku];
			}

			return $product;
		});

		// Filter out products with no stock if in_stock filter is active
		if ($inStock) {
			$items = $items->filter(function($product) {
				return $product->stock_quantity > 0;
			})->values();

			// Take only the requested number of products
			$items = $items->take($perPage);
		}

		$searchQuery = trim($request->input('q') ?? $request->input('query') ?? '');
		$searchQuery = $searchQuery ?: null;
		$categorySlug = trim($request->input('category') ?? '');
		$filterData = $this->getFilterData($searchQuery, $categorySlug, $request);

		// CRITICAL FIX: Estimate total instead of COUNT
		$total = ($page * $perPage) + ($items->count() == $perPage ? $perPage : 0);
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

	// private function buildProductQuery(Request $request)
	// {
	// 	$searchQuery = trim($request->input('q') ?? $request->input('query') ?? '');
	// 	$searchQuery = $searchQuery ?: null;
	// 	$lowerSearchQuery = $searchQuery ? strtolower(trim($searchQuery)) : '';
	// 	$categorySlug = trim($request->input('category') ?? '');
	// 	$sortBy = $request->get('sort', 'name_az');
	// 	$brands = $request->get('brands', []);
	// 	if (is_string($brands)) {
	// 		$brands = [$brands];
	// 	}

	// 	$categories = $request->get('categories', []);
	// 	if (is_string($categories)) {
	// 		$categories = [$categories];
	// 	}
	// 	$minPrice = $request->get('min_price');
	// 	$maxPrice = $request->get('max_price');
	// 	$inStock = $request->get('in_stock');

	// 	$vehicleType = $request->get('vehicle_type');
	// 	$vehicleMake = $request->get('vehicle_make');
	// 	$vehicleModel = $request->get('vehicle_model');
	// 	$vehicleYear = $request->get('vehicle_year');

	// 	$query = ProductFlat::where('channel', 'maddparts')
	// 		->where('locale', 'en')
	// 		->where('status', 1)
	// 		->with([
	// 			'product.images' => function($query) {
	// 				$query->select('id', 'path', 'product_id')->limit(1);
	// 			},
	// 			'product.inventories' => function($query) {
	// 				$query->select('product_id', 'qty');
	// 			},
	// 			'product.categories' => function($query) {
	// 				$query->select('categories.id', 'categories.parent_id');
	// 			}
	// 		]);

	// 	if ($searchQuery) {
	// 		$cleanSearchQuery = preg_replace('/[^a-zA-Z0-9]/', '', $searchQuery);
	// 		$searchWords = array_filter(explode(' ', trim($searchQuery)));

	// 		// Normalize search query for common plural/singular variations
	// 		$normalizedQuery = $searchQuery;
	// 		$isTireSearch = false;
	// 		$isHelmetSearch = false;
	// 		$lowerSearchQuery = strtolower($searchQuery);

	// 		if ($lowerSearchQuery === 'tires' || $lowerSearchQuery === 'tire') {
	// 			$normalizedQuery = 'tire';
	// 			$isTireSearch = true;
	// 		} elseif ($lowerSearchQuery === 'helmets' || $lowerSearchQuery === 'helmet') {
	// 			$normalizedQuery = 'helmet';
	// 			$isHelmetSearch = true;
	// 		} elseif ($lowerSearchQuery === 'oils') {
	// 			$normalizedQuery = 'oil';
	// 		}

	// 		$isBrandSearch = false;
	// 		$brandOptionIds = [];

	// 		$allBrands = DB::table('attribute_options')
	// 			->where('attribute_id', 25)
	// 			->pluck('admin_name', 'id')
	// 			->toArray();

	// 		foreach ($allBrands as $id => $brandName) {
	// 			$lowerBrandName = strtolower($brandName);
	// 			if (strpos($lowerSearchQuery, $lowerBrandName) !== false) {
	// 				$brandOptionIds[] = $id;
	// 				$isBrandSearch = true;

	// 				if (strpos($lowerSearchQuery, 'tire') !== false || strpos($lowerSearchQuery, 'tyre') !== false) {
	// 					$isTireSearch = true;
	// 				}
	// 				if (strpos($lowerSearchQuery, 'helmet') !== false) {
	// 					$isHelmetSearch = true;
	// 				}
	// 				break;
	// 			}
	// 		}

	// 		if ($isBrandSearch && !empty($brandOptionIds)) {
	// 			$query->whereHas('product.attribute_values', function($attrQuery) use ($brandOptionIds) {
	// 				$attrQuery->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
	// 			})->where(function($tireFilter) {
	// 				$tireFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%tire%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%tyre%'])
	// 					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tire%'])
	// 					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tyre%'])
	// 					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tire%'])
	// 					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tyre%']);
	// 			});

	// 			$query->selectRaw('product_flat.*,
    //                 (CASE
    //                     WHEN LOWER(name) LIKE ? THEN 100
    //                     WHEN LOWER(name) LIKE ? THEN 90
    //                     WHEN LOWER(short_description) LIKE ? THEN 70
    //                     WHEN LOWER(description) LIKE ? THEN 60
    //                     ELSE 50
    //                 END) as relevance_score',
	// 				['%' . strtolower($normalizedQuery) . ' tire%',
	// 					'%tire%',
	// 					'%tire%',
	// 					'%tire%']
	// 			)->orderByDesc('relevance_score');
	// 		} elseif ($isTireSearch) {
	// 			$query->where(function($tireFilter) {
	// 				$tireFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%tire%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%tyre%'])
	// 					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tire%'])
	// 					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%tyre%'])
	// 					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tire%'])
	// 					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%tyre%']);
	// 			});

	// 			$query->selectRaw('product_flat.*,
    //                 (CASE
    //                     WHEN LOWER(name) LIKE ? THEN 100
    //                     WHEN LOWER(name) LIKE ? THEN 95
    //                     WHEN LOWER(name) LIKE ? THEN 90
    //                     WHEN LOWER(short_description) LIKE ? THEN 70
    //                     WHEN LOWER(description) LIKE ? THEN 60
    //                     ELSE 50
    //                 END) as relevance_score',
	// 				['%tire %', '%tyre %', '%tire%', '%tire%', '%tire%']
	// 			)->orderByDesc('relevance_score');
	// 		} elseif ($isHelmetSearch) {
	// 			if (empty($categorySlug)) {
	// 				$query->whereHas('product.categories', function($catQuery) {
	// 					$catQuery->whereHas('translations', function($transQuery) {
	// 						$transQuery->whereRaw('LOWER(name) LIKE ?', ['%helmet%']);
	// 					});
	// 				});
	// 			}

	// 			$query->where(function($helmetFilter) use ($searchQuery, $normalizedQuery, $brandOptionIds) {
	// 				$helmetFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%' . $normalizedQuery . '%'])
	// 					->orWhereRaw('LOWER(product_flat.short_description) LIKE ?', ['%' . $normalizedQuery . '%'])
	// 					->orWhereRaw('LOWER(product_flat.description) LIKE ?', ['%' . $normalizedQuery . '%']);

	// 				if (!empty($brandOptionIds)) {
	// 					$helmetFilter->orWhereHas('product.attribute_values', function($attrQuery) use ($brandOptionIds) {
	// 						$attrQuery->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
	// 					});
	// 				}
	// 			});

	// 			$query->selectRaw('product_flat.*,
    //                 (CASE
    //                     WHEN LOWER(name) LIKE ? THEN 100
    //                     WHEN LOWER(name) LIKE ? THEN 90
    //                     WHEN LOWER(short_description) LIKE ? THEN 70
    //                     WHEN LOWER(description) LIKE ? THEN 60
    //                     ELSE 50
    //                 END) as relevance_score',
	// 				['%helmet %', '%helmet%', '%helmet%', '%helmet%']
	// 			)->orderByDesc('relevance_score');
	// 		} else {
	// 			$query->where(function($q) use ($searchQuery, $normalizedQuery, $cleanSearchQuery, $searchWords, $brandOptionIds) {
	// 				$q->where(function($exactMatch) use ($searchQuery, $normalizedQuery, $cleanSearchQuery) {
	// 					$exactMatch->where('name', 'like', '%' . $normalizedQuery . '%')
	// 						->orWhere('sku', 'like', '%' . $searchQuery . '%')
	// 						->orWhere('short_description', 'like', '%' . $normalizedQuery . '%')
	// 						->orWhere('description', 'like', '%' . $normalizedQuery . '%')
	// 						->orWhereRaw('REPLACE(REPLACE(REPLACE(sku, "-", ""), " ", ""), "_", "") LIKE ?', ['%' . $cleanSearchQuery . '%']);
	// 				});

	// 				if (!empty($brandOptionIds)) {
	// 					$q->orWhereHas('product.attribute_values', function($attrQuery) use ($brandOptionIds) {
	// 						$attrQuery->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
	// 					});
	// 				}

	// 				if (count($searchWords) > 1) {
	// 					$q->orWhere(function($multiWord) use ($searchWords) {
	// 						foreach ($searchWords as $word) {
	// 							$multiWord->where(function($wordQuery) use ($word) {
	// 								$wordQuery->where('name', 'like', '%' . $word . '%')
	// 									->orWhere('sku', 'like', '%' . $word . '%')
	// 									->orWhere('short_description', 'like', '%' . $word . '%')
	// 									->orWhere('description', 'like', '%' . $word . '%');
	// 							});
	// 						}
	// 					});
	// 				}
	// 			});

	// 			$query->selectRaw('product_flat.*,
    //                 (CASE
    //                     WHEN LOWER(name) = ? THEN 100
    //                     WHEN LOWER(name) LIKE ? THEN 90
    //                     WHEN name LIKE ? THEN 80
    //                     WHEN LOWER(sku) = ? THEN 75
    //                     WHEN sku LIKE ? THEN 70
    //                     WHEN LOWER(short_description) LIKE ? THEN 50
    //                     WHEN LOWER(description) LIKE ? THEN 30
    //                     ELSE 10
    //                 END) as relevance_score',
	// 				[strtolower($normalizedQuery),
	// 					strtolower($normalizedQuery) . '%',
	// 					'%' . $normalizedQuery . '%',
	// 					strtolower($searchQuery),
	// 					'%' . $searchQuery . '%',
	// 					'%' . strtolower($normalizedQuery) . '%',
	// 					'%' . strtolower($normalizedQuery) . '%']
	// 			)->orderByDesc('relevance_score');
	// 		}

	// 		if ($lowerSearchQuery === 'oil' || $lowerSearchQuery === 'oils') {
	// 			$query->where(function($oilFilter) {
	// 				$oilFilter->whereRaw('LOWER(product_flat.name) LIKE ?', ['%engine oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%motor oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%transmission oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%fork oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%shock oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%gear oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%2-cycle oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%4-cycle oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%2-stroke oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%4-stroke oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%synthetic oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%chain lube%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%chain oil%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%brake fluid%'])
	// 					->orWhereRaw('LOWER(product_flat.name) LIKE ?', ['%coolant%']);
	// 			})
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% kit%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%kit %'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%kits%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%seal%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%filter%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% cap%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%drain%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% plug%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% pan%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% pump%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%cooler%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% tank%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['% line%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%spout%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%funnel%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%hose%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%deflector%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%dipstick%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%gauge%'])
	// 			->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%foil%']);
	// 		} elseif (in_array($lowerSearchQuery, ['tire', 'tires', 'tyre', 'tyres'])) {
	// 			$query->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire iron%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire gauge%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire pressure%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire repair%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire tool%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire changer%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%tire lever%']);
	// 		} elseif (in_array($lowerSearchQuery, ['helmet', 'helmets'])) {
	// 			$query->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%helmet bag%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%helmet lock%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%helmet shield%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%helmet visor%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%child%'])
	// 				->whereRaw('LOWER(product_flat.name) NOT LIKE ?', ['%youth%']);
	// 		}

	// 		$query->selectRaw('product_flat.*, (
    //                 CASE
    //                     WHEN LOWER(product_flat.name) LIKE "% helmet" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%wheel and tire%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%radial tire%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%tweel%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%engine oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%motor oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%synthetic oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%transmission oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%fork oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%shock oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%gear oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%2-cycle oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) LIKE "%4-cycle oil%" THEN 10000
    //                     WHEN LOWER(product_flat.name) = LOWER(?) THEN 1000
    //                     WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 900
    //                     WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 800
    //                     WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 800
    //                     WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 700
    //                     WHEN LOWER(product_flat.name) LIKE CONCAT(?, LOWER(?), ?) THEN 600
    //                     WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 500
    //                     WHEN product_flat.sku = ? THEN 400
    //                     WHEN product_flat.sku LIKE ? THEN 300
    //                     WHEN product_flat.sku LIKE ? THEN 200
    //                     WHEN REPLACE(REPLACE(REPLACE(product_flat.sku, "-", ""), " ", ""), "_", "") = ? THEN 100
    //                     WHEN REPLACE(REPLACE(REPLACE(product_flat.sku, "-", ""), " ", ""), "_", "") LIKE ? THEN 50
    //                     ELSE 1
    //                 END
    //                 +
    //                 COALESCE((
    //                     SELECT
    //                         CASE
    //                             WHEN LOWER(ct_rel.name) = LOWER(?) THEN 500
    //                             WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 400
    //                             WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 300
    //                             ELSE 200
    //                         END
    //                     FROM product_categories pc_rel
    //                     INNER JOIN category_translations ct_rel ON pc_rel.category_id = ct_rel.category_id
    //                     WHERE pc_rel.product_id = product_flat.product_id
    //                     AND ct_rel.locale = "en"
    //                     AND LOWER(ct_rel.name) LIKE LOWER(?)
    //                     ORDER BY
    //                         CASE
    //                             WHEN LOWER(ct_rel.name) = LOWER(?) THEN 1
    //                             WHEN LOWER(ct_rel.name) LIKE LOWER(?) THEN 2
    //                             ELSE 3
    //                         END
    //                     LIMIT 1
    //                 ), 0)
    //                 -
    //                 CASE
    //                     WHEN LOWER(product_flat.name) LIKE LOWER(?) THEN 100
    //                     ELSE 0
    //                 END
    //             ) as relevance_score', [
	// 			$searchQuery,
	// 			$searchQuery . ' %',
	// 			'% ' . $searchQuery . ' %',
	// 			'% ' . $searchQuery,
	// 			$searchQuery . '%',
	// 			'% ', $searchQuery, '%',
	// 			'%' . $searchQuery . '%',
	// 			$searchQuery,
	// 			$searchQuery . '%',
	// 			'%' . $searchQuery . '%',
	// 			$cleanSearchQuery,
	// 			$cleanSearchQuery . '%',
	// 			$searchQuery,
	// 			$searchQuery . '%',
	// 			'%' . $searchQuery . '%',
	// 			'%' . $searchQuery . '%',
	// 			$searchQuery,
	// 			$searchQuery . '%',
	// 			'%assembly%'
	// 			]);
	// 	}

	// 	if ($categorySlug && $categorySlug !== '') {
	// 		// Handle category slug with ID suffix (e.g., "riding-apparel-279")
	// 		// Try to extract ID from the end of the slug
	// 		if (preg_match('/^(.+)-(\d+)$/', $categorySlug, $matches)) {
	// 			$possibleSlug = $matches[1];
	// 			$categoryId = $matches[2];

	// 			// Try to find category by ID first (more reliable)
	// 			$category = Category::find($categoryId);

	// 			// If not found by ID, try by full slug
	// 			if (!$category) {
	// 				$category = Category::whereHas('translations', function ($q) use ($categorySlug) {
	// 					$q->where('slug', $categorySlug);
	// 				})->first();
	// 			}
	// 		} else {
	// 			// No ID suffix, search by slug only
	// 			$category = Category::whereHas('translations', function ($q) use ($categorySlug) {
	// 				$q->where('slug', $categorySlug);
	// 			})->first();
	// 		}

	// 		if ($category) {
	// 			$categoryIds = [$category->id];

	// 			$childCategories = Category::where('parent_id', $category->id)->pluck('id')->toArray();
	// 			if (!empty($childCategories)) {
	// 				$categoryIds = array_merge($categoryIds, $childCategories);

	// 				$grandchildCategories = Category::whereIn('parent_id', $childCategories)->pluck('id')->toArray();
	// 				if (!empty($grandchildCategories)) {
	// 					$categoryIds = array_merge($categoryIds, $grandchildCategories);
	// 				}
	// 			}

	// 			$query->whereHas('product.categories', function ($q) use ($categoryIds) {
	// 				$q->whereIn('categories.id', $categoryIds);
	// 			});
	// 		} else {
	// 			// Category not found - return no results by adding impossible condition
	// 			$query->whereRaw('1 = 0');
	// 		}
	// 	}

	// 	if (!empty($categories)) {
	// 		$query->whereHas('product.categories', function ($q) use ($categories) {
	// 			$q->whereIn('category_id', $categories);
	// 		});
	// 	}

	// 	if (!empty($brands)) {
	// 		// FIX: Get attribute_option IDs, not manufacturer IDs
	// 		// product_attribute_values.text_value stores attribute_option.id, not manufacturer_id
	// 		$brandOptionIds = DB::table('attribute_options')
	// 			->where('attribute_id', 25)
	// 			->whereIn('admin_name', $brands)
	// 			->pluck('id')
	// 			->toArray();

	// 		if (!empty($brandOptionIds)) {
	// 			$query->whereHas('product.attribute_values', function ($q) use ($brandOptionIds) {
	// 				$q->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
	// 			});
	// 		}
	// 	}

	// 	if ($minPrice) {
	// 		$query->where('price', '>=', $minPrice);
	// 	}

	// 	if ($maxPrice) {
	// 		$query->where('price', '<=', $maxPrice);
	// 	}

	// 	if ($inStock) {
	// 		$query->whereHas('product.inventories', function ($q) {
	// 			$q->where('qty', '>', 0);
	// 		});
	// 	}

	// 	if ($vehicleType && $vehicleMake && $vehicleModel && $vehicleYear) {
	// 		$tmmyId = DB::table('ds_type_make_model_year')
	// 			->where('vehicle_type_id', $vehicleType)
	// 			->where('make_id', $vehicleMake)
	// 			->where('model_id', $vehicleModel)
	// 			->where('year_id', $vehicleYear)
	// 			->value('tmmy_id');

	// 		if ($tmmyId) {
	// 			$query->whereHas('product', function ($q) use ($tmmyId) {
	// 				$q->whereIn('id', function ($subQuery) use ($tmmyId) {
	// 					$subQuery->select('product_id')
	// 						->from('product_vehicle_fitment')
	// 						->where('tmmy_id', $tmmyId);
	// 				});
	// 			});
	// 		}
	// 	}

	// 	if ($searchQuery && $sortBy === 'name_az') {
	// 		$query->orderBy('relevance_score', 'desc')->orderBy('name', 'asc');
	// 	} else {
	// 		switch ($sortBy) {
	// 			case 'price_low':
	// 				$query->orderBy('price', 'asc');
	// 				break;
	// 			case 'price_high':
	// 				$query->orderBy('price', 'desc');
	// 				break;
	// 			case 'name_az':
	// 				$query->orderBy('name', 'asc');
	// 				break;
	// 			case 'name_za':
	// 				$query->orderBy('name', 'desc');
	// 				break;
	// 			case 'newest':
	// 				$query->orderBy('created_at', 'desc');
	// 				break;
	// 			case 'oldest':
	// 				$query->orderBy('created_at', 'asc');
	// 				break;
	// 			default:
	// 				if ($searchQuery) {
	// 					$query->orderBy('relevance_score', 'desc')->orderBy('name', 'asc');
	// 				} else {
	// 					$query->orderBy('name', 'asc');
	// 				}
	// 		}
	// 	}

	// 	return $query;
	// }

private function buildProductQuery(Request $request)
	{
	return \App\Http\Controllers\Shop\SearchControllerEnhanced::buildTagBasedProductQuery($request);
	}
	private function getFilterData($searchQuery = null, $categorySlug = null, $request = null)
	{
		// Cache filter data for 30 minutes (1800 seconds)
		// Product prices are cached separately and updated via dropshipper API
		$tempRequest = $request ? clone $request : new Request();
		if ($searchQuery) {
			$tempRequest->merge(['q' => $searchQuery]);
		}
		if ($categorySlug) {
			$tempRequest->merge(['category' => $categorySlug]);
		}

		$cacheKey = 'search_filters_' . md5($searchQuery . $categorySlug . serialize($tempRequest->except(['_token', 'page', 'brands', 'categories', 'min_price', 'max_price', 'in_stock'])));
		$cacheDuration = 1800; // 30 minutes

		return Cache::remember($cacheKey, $cacheDuration, function() use ($tempRequest, $searchQuery, $categorySlug) {
			return $this->calculateSearchFilterData($searchQuery, $categorySlug, $tempRequest);
		});
	}

	private function calculateSearchFilterData($searchQuery = null, $categorySlug = null, $request = null)
	{
		try {
			$tempRequest = $request ? clone $request : new Request();
			if ($searchQuery) {
				$tempRequest->merge(['q' => $searchQuery]);
			}
			if ($categorySlug) {
				$tempRequest->merge(['category' => $categorySlug]);
			}

			$cacheKey = 'search_filters_' . md5($searchQuery . $categorySlug . serialize($tempRequest->except(['_token', 'page', 'brands', 'categories', 'min_price', 'max_price', 'in_stock'])));
			$cacheDuration = 1800;

			return Cache::remember($cacheKey, $cacheDuration, function() use ($tempRequest, $searchQuery, $categorySlug) {
				$baseQuery = \App\Http\Controllers\Shop\SearchControllerEnhanced::buildTagBasedProductQuery($tempRequest);

				$baseQueryClone = clone $baseQuery;
				$filteredProductIds = $baseQueryClone
					->select('product_flat.product_id')
					->distinct()
					->reorder()
					->limit(5000)
					->pluck('product_id')
					->toArray();

				\Log::info('Search Filter Debug', [
					'search_query' => $searchQuery,
					'category_slug' => $categorySlug,
					'filtered_product_ids_count' => \count($filteredProductIds),
					'sample_ids' => \array_slice($filteredProductIds, 0, 10)
				]);

				if (empty($filteredProductIds)) {
					\Log::warning('No products found for search filter calculation', [
						'search_query' => $searchQuery,
						'category_slug' => $categorySlug
					]);
					return [
						'brands' => collect(),
						'price_range' => (object)['min_price' => 0, 'max_price' => 0],
						'categories' => collect(),
						'vehicles' => true
					];
				}

				$productIdChunks = array_chunk($filteredProductIds, 1000);

				$brands = collect();
				foreach ($productIdChunks as $chunk) {
					$chunkBrands = DB::table('product_attribute_values as pav')
						->join('attribute_options as ao', 'pav.text_value', '=', 'ao.id')
						->select('ao.admin_name as brand', DB::raw('count(DISTINCT pav.product_id) as product_count'))
						->where('pav.attribute_id', 25)
						->where('ao.attribute_id', 25)
						->whereIn('pav.product_id', $chunk)
						->whereNotNull('pav.text_value')
						->where('pav.text_value', '!=', '')
						->groupBy('ao.admin_name')
						->get();

					foreach ($chunkBrands as $chunkBrand) {
						$existing = $brands->firstWhere('brand', $chunkBrand->brand);
						if ($existing) {
							$existing->product_count += $chunkBrand->product_count;
						} else {
							$brands->push($chunkBrand);
						}
					}
				}

				$brands = $brands->sortByDesc('product_count')->take(20)->values();

				$priceRange = (object)['min_price' => 0, 'max_price' => 0];
				$minPrice = PHP_FLOAT_MAX;
				$maxPrice = 0;

				foreach ($productIdChunks as $chunk) {
					$chunkPriceRange = DB::table('product_flat')
						->whereIn('product_id', $chunk)
						->where('channel', 'maddparts')
						->where('locale', 'en')
						->selectRaw('MIN(price) as min_price, MAX(price) as max_price')
						->first();

					if ($chunkPriceRange) {
						$minPrice = min($minPrice, $chunkPriceRange->min_price ?? PHP_FLOAT_MAX);
						$maxPrice = max($maxPrice, $chunkPriceRange->max_price ?? 0);
					}
				}

				$priceRange->min_price = round($minPrice === PHP_FLOAT_MAX ? 0 : $minPrice, 2);
				$priceRange->max_price = round($maxPrice, 2);

				$categories = collect();
				foreach ($productIdChunks as $chunk) {
					$chunkCategories = DB::table('product_categories as pc')
						->join('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
						->select('ct.category_id as id', 'ct.name', DB::raw('COUNT(DISTINCT pc.product_id) as product_count'))
						->where('ct.locale', 'en')
						->whereIn('pc.product_id', $chunk)
						->groupBy('ct.category_id', 'ct.name')
						->get();

					foreach ($chunkCategories as $chunkCategory) {
						$existing = $categories->firstWhere('id', $chunkCategory->id);
						if ($existing) {
							$existing->product_count += $chunkCategory->product_count;
						} else {
							$categories->push((object)[
								'id' => $chunkCategory->id,
								'name' => $chunkCategory->name,
								'product_count' => $chunkCategory->product_count
							]);
						}
					}
				}

				$categories = $categories->sortByDesc('product_count')->take(20)->values();

				return [
					'brands' => $brands,
					'price_range' => $priceRange,
					'categories' => $categories,
					'vehicles' => true
				];
			});
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
		// FIX: Join through attribute_options table since text_value stores option_id, not manufacturer_id
		$brands = DB::table('product_attribute_values as pav')
			->join('attribute_options as ao', 'pav.text_value', '=', 'ao.id')
			->join('product_flat as pf', 'pav.product_id', '=', 'pf.product_id')
			->select('ao.admin_name as brand', DB::raw('count(DISTINCT pav.product_id) as product_count'))
			->where('pav.attribute_id', 25)
			->where('ao.attribute_id', 25)
			->where('pf.channel', 'maddparts')
			->where('pf.locale', 'en')
			->where('pf.status', 1)
			->whereNotNull('pav.text_value')
			->where('pav.text_value', '!=', '')
			->groupBy('ao.admin_name')
			->orderBy('ao.admin_name', 'asc')
			->get();

		return view('brands.index', compact('brands'));
	}

	public function brandProducts(Request $request, $brand)
	{
		// URL decode the brand name (e.g., "100%25" becomes "100%")
		$brand = urldecode($brand);

		if ($request->ajax()) {
			return $this->getBrandFilteredProducts($request, $brand);
		}

		$page = $request->get('page', 1);

		// BRAND PRODUCTS CACHE DISABLED - Dropshipper prices must always be fresh
		$perPage = 12;

		$query = $this->buildBrandProductQuery($request, $brand);
		$countQuery = clone $query;
		$total = $countQuery->select('product_flat.product_id')
			->distinct()
			->count('product_flat.product_id');

		$products = $query->skip(($page - 1) * $perPage)
			->take($perPage)
			->get();

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

		$cachedData = [
			'items' => $items,
			'total' => $total,
			'per_page' => $perPage,
			'current_page' => $page
		];

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
		// URL decode the brand name (e.g., "100%25" becomes "100%")
		$brand = urldecode($brand);

		$inStock = $request->get('in_stock') === 'true';
		$perPage = 12;

		// When filtering by stock, fetch more products than needed
		$fetchMultiplier = $inStock ? 5 : 1;
		$fetchPerPage = $perPage * $fetchMultiplier;

		$products = $this->buildBrandProductQuery($request, $brand)->paginate($fetchPerPage);

		// Optimize: Calculate stock quantities in bulk with a single query
		$productIds = $products->pluck('product_id')->toArray();
		$stockQuantities = DB::table('product_inventories')
			->select('product_id', DB::raw('SUM(qty) as total_qty'))
			->whereIn('product_id', $productIds)
			->groupBy('product_id')
			->pluck('total_qty', 'product_id');

		// Get variant stock for configurable products (ALWAYS calculate, not just when filtering)
		$variantStockQuantities = [];
		$parentProductIds = $products->getCollection()->filter(function($product) {
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

		$products->getCollection()->transform(function ($product) use ($stockQuantities, $variantStockQuantities) {
			$totalQty = $stockQuantities[$product->product_id] ?? 0;

			// For configurable products, check variant stock
			if (strpos($product->sku, '-PARENT') !== false && isset($variantStockQuantities[$product->product_id])) {
				$totalQty = max($totalQty, $variantStockQuantities[$product->product_id]);
			}

			$product->stock_status = $totalQty > 0 ? 'in_stock' : 'out_of_stock';
			$product->stock_quantity = $totalQty;
			return $product;
		});

		// Filter out products with no stock if in_stock filter is active
		if ($inStock) {
			$filteredCollection = $products->getCollection()->filter(function($product) {
				return $product->stock_quantity > 0;
			})->values()->take($perPage);

			$products->setCollection($filteredCollection);
		}

		$filterData = $this->getBrandFilterData($brand, $request);

		$productsArray = $products->map(function($product) {
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

			// Calculate minimum variant price for configurable products
			$displayPrice = $product->price;
			if (strpos($product->sku, '-PARENT') !== false) {
				$baseSku = str_replace('-PARENT', '', $product->sku);

				$minVariantPrice = DB::table('product_flat')
					->where('sku', 'LIKE', $baseSku . '%')
					->where('sku', '!=', $product->sku)
					->where('channel', 'maddparts')
					->where('locale', 'en')
					->where('type', 'simple')
					->whereRaw('CAST(price AS DECIMAL(10,2)) > 0')
					->min('price');

				if ($minVariantPrice) {
					$displayPrice = $minVariantPrice;
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

		// FIX: Get attribute_option ID, not manufacturer ID
		// product_attribute_values.text_value stores attribute_option.id, not manufacturer_id
		$brandOptionId = DB::table('attribute_options')
			->where('attribute_id', 25)
			->where('admin_name', $brand)
			->value('id');

		if (!$brandOptionId) {
			return ProductFlat::where('channel', 'maddparts')->whereRaw('1=0');
		}

		$productIdsWithBrand = DB::table('product_attribute_values')
			->where('attribute_id', 25)
			->where('text_value', $brandOptionId)
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
			// Filter will be applied after fetching - see below where stock_quantities are calculated
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
				// Filter will be applied after fetching - see below where stock_quantities are calculated
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
