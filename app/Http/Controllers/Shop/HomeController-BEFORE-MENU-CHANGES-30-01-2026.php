<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductFlat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Webkul\Theme\Repositories\ThemeCustomizationRepository;
use App\Models\CmsHomeContent;

class HomeController extends Controller
{
    const STATUS = 1;

    protected $themeCustomizationRepository;

    public function __construct(ThemeCustomizationRepository $themeCustomizationRepository)
    {
        $this->themeCustomizationRepository = $themeCustomizationRepository;
    }

    public function index()
    {
        $start = microtime(true);
        $categories = collect();
        $bestSellers = collect();
        $topRated = collect();
        $oemBrands = [];
        $customizations = collect();
        $cmsContent = collect()->keyBy('section_key');

        try {
            visitor()->visit();
        } catch (\Exception $e) {
            \Log::error('Visitor tracking error: ' . $e->getMessage());
        }
        \Log::info('Visitor: ' . round((microtime(true) - $start) * 1000, 2) . 'ms');

        $t1 = microtime(true);
        try {
            $customizations = $this->themeCustomizationRepository->orderBy('sort_order')->findWhere([
                'status'     => self::STATUS,
                'channel_id' => core()->getCurrentChannel()->id,
                'theme_code' => core()->getCurrentChannel()->theme,
            ]);
        } catch (\Exception $e) {
            \Log::error('Homepage customizations error: ' . $e->getMessage());
        }

        $t2 = microtime(true);
        try {
            $categories = Cache::remember('homepage_categories', 1800, function () {
                return $this->getCachedCategories();
            });
        } catch (\Exception $e) {
            \Log::error('Homepage categories error: ' . $e->getMessage());
        }

        $t3 = microtime(true);
        try {
            $bestSellers = Cache::remember('homepage_bestsellers', 1800, function () {
                return $this->getCachedBestSellers();
            });
        } catch (\Exception $e) {
            \Log::error('Homepage bestsellers error: ' . $e->getMessage());
        }

        $t4 = microtime(true);
        try {
            $topRated = Cache::remember('homepage_toprated', 1800, function () {
                return $this->getCachedTopRated();
            });
        } catch (\Exception $e) {
            \Log::error('Homepage toprated error: ' . $e->getMessage());
        }

        try {
            $oemBrands = $this->getOemBrands();
        } catch (\Exception $e) {
            \Log::error('Homepage OEM brands error: ' . $e->getMessage());
        }

        try {
            $cmsContent = CmsHomeContent::getAllActive();
        } catch (\Exception $e) {
            \Log::error('Homepage CMS content error: ' . $e->getMessage());
            $cmsContent = collect()->keyBy('section_key');
        }

        $t5 = microtime(true);
        $response = view('home.index', compact('customizations', 'categories', 'bestSellers', 'topRated', 'oemBrands', 'cmsContent'));

        return $response;
    }

    /**
     * Get OEM brands for PartStream integration
     * Based on Appendix A from PartStream documentation
     */
    private function getOemBrands()
    {
        return [
            ['name' => 'CFMOTO', 'code' => 'CFMTO', 'logo' => 'CFMTO.png'],
            ['name' => 'Honda Powersports', 'code' => 'HOM', 'logo' => 'HOM.png'],
            ['name' => 'Kawasaki Motorcycle', 'code' => 'KUS', 'logo' => 'KUS.png'],
            ['name' => 'Polaris', 'code' => 'POL', 'logo' => 'POL.png'],
            ['name' => 'Ski-Doo / Sea-Doo / Can-Am', 'code' => 'BRP', 'logo' => 'BRP.png'],
            ['name' => 'Suzuki Motor of America, Inc. – Marine', 'code' => 'SZM', 'logo' => 'SZM.png'],
            ['name' => 'Yamaha', 'code' => 'YAM', 'logo' => 'YAM.png'],
        ];
    }

    public function getFeaturedProducts()
    {
        try {
            $cacheKey = 'homepage_featured_' . date('YmdH') . floor(date('i') / 15);

            $products = Cache::remember($cacheKey, 900, function () {
                $result = \DB::table('product_flat as pf')
                    ->join('product_images as pi', 'pf.product_id', '=', 'pi.product_id')
                    ->where('pf.channel', 'maddparts')
                    ->where('pf.locale', 'en')
                    ->where('pf.status', 1)
                    ->where('pf.visible_individually', 1)
                    ->whereNotNull('pi.path')
                    ->where('pi.path', '!=', '')
                    ->where('pi.path', 'NOT LIKE', '%placeholder%')
                    ->select(
                        'pf.product_id',
                        'pf.sku',
                        'pf.name',
                        'pf.price',
                        'pf.special_price',
                        'pf.url_key',
                        'pf.type',
                        \DB::raw('MIN(pi.path) as image_path')
                    )
                    ->groupBy('pf.product_id', 'pf.sku', 'pf.name', 'pf.price', 'pf.special_price', 'pf.url_key', 'pf.type')
                    ->orderBy('pf.product_id', 'desc')
                    ->limit(12)
                    ->get();

                $disk = config('filesystems.default');

                return $result->map(function ($product) use ($disk) {
                    $imageUrl = null;
                    if ($product->image_path) {
                        if ($disk === 's3') {
                            $imageUrl = Storage::disk('s3')->url($product->image_path);
                        } else {
                            $imageUrl = Storage::url($product->image_path);
                        }
                    }

                    return [
                        'product_id' => $product->product_id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'price' => $product->price,
                        'special_price' => $product->special_price,
                        'url_key' => $product->url_key,
                        'type' => $product->type,
                        'image_url' => $imageUrl,
                    ];
                });
            });

            return response()->json([
                'success' => true,
                'products' => $products
            ]);
        } catch (\Exception $e) {
            \Log::error('Featured products AJAX error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'products' => []
            ]);
        }
    }

    public function getCachedCategories()
    {
        try {
            $excludedCategories = ['Spreaders', 'Plows', 'DVDs', 'Mower', 'Displays'];
            $locale = 'en';

            $rootCategoryId = core()->getCurrentChannel()->root_category_id;

            return Category::where('status', 1)
                ->where('parent_id', 1)
                ->with(['translations' => function ($query) use ($locale) {
                    $query->where('locale', $locale);
                }])
                ->orderBy('position')
                ->limit(12)
                ->get()
                ->filter(function ($category) use ($locale, $excludedCategories) {
                    $translation = $category->translations->first();
                    $categoryName = $translation->name ?? '';

                    foreach ($excludedCategories as $excluded) {
                        if (strcasecmp($categoryName, $excluded) === 0) {
                            return false;
                        }
                    }

                    return true;
                });
        } catch (\Exception $e) {
            \Log::error('Home categories cache error: ' . $e->getMessage());
            return collect();
        }
    }

    public function getCachedBestSellers()
    {
        try {
            $bestSellerIds = \DB::table('order_items')
                ->select('order_items.product_id', \DB::raw('SUM(order_items.qty_ordered) as total_sold'))
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.status', '!=', 'canceled')
                ->where('orders.status', '!=', 'closed')
                ->groupBy('order_items.product_id')
                ->orderBy('total_sold', 'desc')
                ->limit(4)
                ->pluck('product_id');

            if ($bestSellerIds->isEmpty()) {
                return collect();
            }

            return ProductFlat::where('channel', 'maddparts')
                ->where('locale', 'en')
                ->where('status', 1)
                ->whereIn('product_id', $bestSellerIds)
                ->with(['product.images' => function($query) {
                    $query->whereNotNull('path')
                          ->where('path', '!=', '')
                          ->where('path', '!=', 'product-image-placeholder.png')
                          ->limit(1);
                }])
                ->get()
                ->map(function ($product) {
                    $firstImage = $product->product->images->first();
                    $product->image_url = $firstImage ? $firstImage->url : null;
                    return $product;
                });
        } catch (\Exception $e) {
            \Log::error('Best sellers cache error: ' . $e->getMessage());
            return collect();
        }
    }

    public function getCachedTopRated()
    {
        try {
            $topRatedIds = \DB::table('product_reviews')
                ->select('product_reviews.product_id', \DB::raw('AVG(product_reviews.rating) as avg_rating'), \DB::raw('COUNT(product_reviews.id) as review_count'))
                ->where('product_reviews.status', 'approved')
                ->groupBy('product_reviews.product_id')
                ->having('review_count', '>=', 1)
                ->orderBy('avg_rating', 'desc')
                ->orderBy('review_count', 'desc')
                ->limit(4)
                ->pluck('product_id');

            if ($topRatedIds->isEmpty()) {
                return collect();
            }

            return ProductFlat::where('channel', 'maddparts')
                ->where('locale', 'en')
                ->where('status', 1)
                ->whereIn('product_id', $topRatedIds)
                ->with(['product.images' => function($query) {
                    $query->whereNotNull('path')
                          ->where('path', '!=', '')
                          ->where('path', '!=', 'product-image-placeholder.png')
                          ->limit(1);
                }])
                ->get()
                ->map(function ($product) {
                    $firstImage = $product->product->images->first();
                    $product->image_url = $firstImage ? $firstImage->url : null;
                    return $product;
                });
        } catch (\Exception $e) {
            \Log::error('Top rated cache error: ' . $e->getMessage());
            return collect();
        }
    }

    public function contact()
    {
        return view('home.contact');
    }

    public function getHeaderCategories()
    {
        return Cache::remember('header_categories', 86400, function () {
            $locale = core()->getCurrentLocale()->code;

               $menuItems = [
                ['name' => 'OEM PARTS', 'url' => '/oem-parts'],
                ['name' => 'ACCESSORIES', 'slug' => 'accessories'],
                ['name' => 'GEAR', 'slug' => 'gear'],
                ['name' => 'MAINTENANCE', 'slug' => 'maintenance'],
                ['name' => 'TIRES', 'slug' => 'tires'],
                ['name' => 'DIRT BIKE', 'slug' => 'dirt-bike'],
                ['name' => 'STREET', 'slug' => 'street'],
                ['name' => 'ATV', 'slug' => 'atv'],
                ['name' => 'UTV', 'slug' => 'utv'],
                ['name' => 'WATERCRAFT', 'id' => 1374],
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
                if (isset($item['url'])) {
                    $categories[] = [
                        'id' => null,
                        'name' => $item['name'],
                        'slug' => '',
                        'url' => url($item['url']),
                        'children' => []
                    ];
                } elseif (isset($item['id'])) {
                    $category = Category::where('id', $item['id'])
                        ->where('status', 1)
                        ->with([
                            'translations' => function ($query) use ($locale) {
                                $query->where('locale', $locale);
                            },
                            'children' => function ($query) use ($locale) {
                                $query->where('status', 1)
                                    ->with(['translations' => function ($q) use ($locale) {
                                        $q->where('locale', $locale);
                                    }]);
                            }
                        ])
                        ->first();

                    if ($category && $category->translations->isNotEmpty()) {
                        $translation = $category->translations->first();
                        $children = $category->children->map(function ($child) use ($locale) {
                            $childTranslation = $child->translations->first();
                            if (!$childTranslation) return null;
                            return [
                                'id' => $child->id,
                                'name' => $childTranslation->name,
                                'slug' => $childTranslation->slug,
                                'url' => url($childTranslation->slug)
                            ];
                        })->filter()->values();

                        $categories[] = [
                            'id' => $category->id,
                            'name' => $item['name'],
                            'slug' => $translation->slug ?? '',
                            'url' => url($translation->slug ?? ''),
                            'children' => $children
                        ];
                    }
                } elseif (isset($item['slug'])) {
                    $category = $categoryMap->get($item['slug']);

                    if ($category && $category->translations->isNotEmpty()) {
                        $translation = $category->translations->first();
                        $categories[] = [
                            'id' => $category->id,
                            'name' => $item['name'],
                            'slug' => $translation->slug ?? '',
                            'url' => url($translation->slug ?? ''),
                            'children' => []
                        ];
                    } else {
                        $categories[] = [
                            'id' => null,
                            'name' => $item['name'],
                            'slug' => '',
                            'url' => route('shop.search', ['q' => strtolower($item['name'])]),
                            'children' => []
                        ];
                    }
                }
            }

            return response()->json([
                'success' => true,
                'categories' => $categories
            ]);
        });
    }

    public function getMegaMenuCategories()
    {
        return Cache::remember('mega_menu_categories', 86400, function () {
            $locale = core()->getCurrentLocale()->code;
            $excludedCategories = ['Spreaders', 'Plows', 'DVDs', 'Mower', 'Displays', 'Accessories', 'Gear', 'Maintenance', 'Tires', 'Dirt Bike', 'Street', 'ATV', 'UTV', 'Watercraft'];

            $rootCategoryId = core()->getCurrentChannel()->root_category_id;
            $rootCategory = Category::find($rootCategoryId);

            if (!$rootCategory) {
                return response()->json([
                    'success' => false,
                    'categories' => []
                ]);
            }

            $excludedLower = array_map('strtolower', $excludedCategories);

            $categories = Category::where('status', 1)
                ->where('parent_id', $rootCategory->id)
                ->whereNotIn('id', function($query) use ($locale, $excludedLower) {
                    $query->select('category_id')
                        ->from('category_translations')
                        ->whereRaw('LOWER(name) IN ("' . implode('","', $excludedLower) . '")');
                })
                ->with([
                    'translations' => function ($query) use ($locale) {
                        $query->where('locale', $locale);
                    },
                    'children' => function ($query) use ($locale) {
                        $query->where('status', 1)
                            ->orderBy('position')
                            ->with(['translations' => function ($q) use ($locale) {
                                $q->where('locale', $locale);
                            }]);
                    }
                ])
                ->orderBy('position')
                ->get()
                ->map(function ($category) {
                    $translation = $category->translations->first();
                    if (!$translation) {
                        return null;
                    }

                    $subcategories = $category->children
                        ->map(function ($child) {
                            $childTranslation = $child->translations->first();
                            if (!$childTranslation) {
                                return null;
                            }
                            return [
                                'id' => $child->id,
                                'name' => $childTranslation->name,
                                'slug' => $childTranslation->slug,
                                'url' => url($childTranslation->slug)
                            ];
                        })
                        ->filter()
                        ->values();

                    return [
                        'id' => $category->id,
                        'name' => $translation->name,
                        'slug' => $translation->slug,
                        'url' => url($translation->slug),
                        'subcategories' => $subcategories
                    ];
                })
                ->filter()
                ->values();

            return response()->json([
                'success' => true,
                'categories' => $categories
            ]);
        });
    }

    public function getSearchCategories()
    {
        $categories = Cache::remember('search_categories', 86400, function () {
            $locale = core()->getCurrentLocale()->code;
            $excludedCategories = ['Spreaders', 'Plows', 'DVDs', 'Mower', 'Displays'];

            $rootCategoryId = core()->getCurrentChannel()->root_category_id;
            $rootCategory = Category::find($rootCategoryId);

            if (!$rootCategory) {
                return [];
            }

            $excludedLower = array_map('strtolower', $excludedCategories);

            return Category::where('status', 1)
                ->where('parent_id', $rootCategory->id)
                ->whereNotIn('id', function($query) use ($locale, $excludedLower) {
                    $query->select('category_id')
                        ->from('category_translations')
                        ->whereRaw('LOWER(name) IN ("' . implode('","', $excludedLower) . '")');
                })
                ->with(['translations' => function ($query) use ($locale) {
                    $query->where('locale', $locale);
                }])
                ->orderBy('position')
                ->get()
                ->map(function ($category) {
                    $translation = $category->translations->first();
                    if (!$translation) {
                        return null;
                    }
                    return [
                        'id' => $category->id,
                        'name' => $translation->name,
                        'slug' => $translation->slug
                    ];
                })
                ->filter()
                ->values();
        });

        return response()->json([
            'success' => true,
            'categories' => $categories
        ]);
    }

    private function getCategoryBySlug($slug)
    {
        $locale = core()->getCurrentLocale()->code;
        return Category::whereHas('translations', function ($query) use ($slug, $locale) {
            $query->where('slug', $slug)->where('locale', $locale);
        })
        ->where('status', 1)
        ->with([
            'translations' => function ($query) use ($locale) {
                $query->where('locale', $locale);
            },
            'children' => function ($query) use ($locale) {
                $query->where('status', 1)
                    ->orderBy('position')
                    ->limit(12)
                    ->with(['translations' => function ($q) use ($locale) {
                        $q->where('locale', $locale);
                    }]);
            }
        ])
        ->first();
    }

    private function formatMegaMenuCategories($parentCategory)
    {
        if (!$parentCategory) {
            return [];
        }

        $translation = $parentCategory->translations->first();
        if (!$translation) {
            return [];
        }

        $columns = [];
        $subcategories = $parentCategory->children;

        foreach ($subcategories as $index => $child) {
            $childTranslation = $child->translations->first();
            if (!$childTranslation) {
                continue;
            }

            $columns[] = [
                'id' => $child->id,
                'name' => $childTranslation->name,
                'slug' => $childTranslation->slug,
                'url' => url($childTranslation->slug),
                'subcategories' => []
            ];
        }

        return $columns;
    }

    private function getTopCategoriesForMegaMenu($excludeCategories = [])
    {
        $locale = core()->getCurrentLocale()->code;
        $rootCategoryId = core()->getCurrentChannel()->root_category_id;

        $excludedLower = array_map('strtolower', $excludeCategories);

        return Category::where('status', 1)
            ->where('parent_id', $rootCategoryId)
            ->whereNotIn('id', function($query) use ($locale, $excludedLower) {
                $query->select('category_id')
                    ->from('category_translations')
                    ->whereRaw('LOWER(name) IN ("' . implode('","', $excludedLower) . '")');
            })
            ->with([
                'translations' => function ($query) use ($locale) {
                    $query->where('locale', $locale);
                },
                'children' => function ($query) use ($locale) {
                    $query->where('status', 1)
                        ->orderBy('position')
                        ->limit(8)
                        ->with(['translations' => function ($q) use ($locale) {
                            $q->where('locale', $locale);
                        }]);
                }
            ])
            ->orderBy('position')
            ->limit(12)
            ->get()
            ->map(function ($category) {
                $translation = $category->translations->first();
                if (!$translation || $category->children->isEmpty()) {
                    return null;
                }

                $subcategories = $category->children
                    ->map(function ($child) {
                        $childTranslation = $child->translations->first();
                        if (!$childTranslation) {
                            return null;
                        }
                        return [
                            'id' => $child->id,
                            'name' => $childTranslation->name,
                            'slug' => $childTranslation->slug,
                            'url' => url($childTranslation->slug)
                        ];
                    })
                    ->filter()
                    ->values();

                return [
                    'id' => $category->id,
                    'name' => $translation->name,
                    'slug' => $translation->slug,
                    'url' => url($translation->slug),
                    'subcategories' => $subcategories
                ];
            })
            ->filter()
            ->values();
    }

    private function getCategoriesByNames($categoryNames)
    {
        $locale = core()->getCurrentLocale()->code;
        $rootCategoryId = core()->getCurrentChannel()->root_category_id;

        return Category::where('status', 1)
            ->where('parent_id', $rootCategoryId)
            ->whereIn('id', function($query) use ($locale, $categoryNames) {
                $namesLower = array_map('strtolower', $categoryNames);
                $query->select('category_id')
                    ->from('category_translations')
                    ->whereRaw('LOWER(name) IN ("' . implode('","', $namesLower) . '")')
                    ->where('locale', $locale);
            })
            ->with([
                'translations' => function ($query) use ($locale) {
                    $query->where('locale', $locale);
                },
                'children' => function ($query) use ($locale) {
                    $query->where('status', 1)
                        ->orderBy('position')
                        ->limit(8)
                        ->with(['translations' => function ($q) use ($locale) {
                            $q->where('locale', $locale);
                        }]);
                }
            ])
            ->orderBy('position')
            ->get()
            ->map(function ($category) {
                $translation = $category->translations->first();
                if (!$translation || $category->children->isEmpty()) {
                    return null;
                }

                $subcategories = $category->children
                    ->map(function ($child) {
                        $childTranslation = $child->translations->first();
                        if (!$childTranslation) {
                            return null;
                        }
                        return [
                            'id' => $child->id,
                            'name' => $childTranslation->name,
                            'slug' => $childTranslation->slug,
                            'url' => url($childTranslation->slug)
                        ];
                    })
                    ->filter()
                    ->values();

                return [
                    'id' => $category->id,
                    'name' => $translation->name,
                    'slug' => $translation->slug,
                    'url' => url($translation->slug),
                    'subcategories' => $subcategories
                ];
            })
            ->filter()
            ->values();
    }

    public function getAccessoriesMegaMenu()
    {
        $categoryNames = ['Body', 'Windshield', 'Luggage', 'Graphics', 'Seats', 'Security & Covers', 'Trailers & Ramps', 'Skis', 'Track Systems', 'Snow Accessories'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getGearMegaMenu()
    {
        $categoryNames = ['Riding Apparel', 'Casual Wear', 'Helmets', 'Protection', 'Eyewear', 'Footwear'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getMaintenanceMegaMenu()
    {
        $categoryNames = ['Fuel & Air', 'Tools', 'Chemical', 'Engine', 'Brake', 'Drive', 'Shop'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getTiresMegaMenu()
    {
        $locale = core()->getCurrentLocale()->code;
        $rootCategoryId = core()->getCurrentChannel()->root_category_id;

        $tiresCategory = Category::where('status', 1)
            ->where('parent_id', $rootCategoryId)
            ->whereIn('id', function($query) use ($locale) {
                $query->select('category_id')
                    ->from('category_translations')
                    ->where('name', 'Tires & Wheels')
                    ->where('locale', $locale);
            })
            ->with([
                'translations' => function ($query) use ($locale) {
                    $query->where('locale', $locale);
                },
                'children' => function ($query) use ($locale) {
                    $query->where('status', 1)
                        ->orderBy('position')
                        ->with([
                            'translations' => function ($q) use ($locale) {
                                $q->where('locale', $locale);
                            },
                            'children' => function ($q) use ($locale) {
                                $q->where('status', 1)
                                    ->orderBy('position')
                                    ->limit(8)
                                    ->with(['translations' => function ($qt) use ($locale) {
                                        $qt->where('locale', $locale);
                                    }]);
                            }
                        ]);
                }
            ])
            ->first();

        $columns = [];
        if ($tiresCategory && $tiresCategory->children) {
            foreach ($tiresCategory->children as $child) {
                $childTranslation = $child->translations->first();
                if (!$childTranslation || $child->children->isEmpty()) {
                    continue;
                }

                $subcategories = $child->children->map(function ($subcat) {
                    $subcatTranslation = $subcat->translations->first();
                    if (!$subcatTranslation) {
                        return null;
                    }
                    return [
                        'id' => $subcat->id,
                        'name' => $subcatTranslation->name,
                        'slug' => $subcatTranslation->slug,
                        'url' => url($subcatTranslation->slug)
                    ];
                })->filter()->values();

                $columns[] = [
                    'id' => $child->id,
                    'name' => $childTranslation->name,
                    'slug' => $childTranslation->slug,
                    'url' => url($childTranslation->slug),
                    'subcategories' => $subcategories
                ];
            }
        }

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getDirtBikeMegaMenu()
    {
        $categoryNames = ['Pistons', 'Sprockets', 'Gaskets & Seals', 'Seats', 'Brake', 'Fuel & Air', 'Clutch & Components', 'Bodywork', 'Exhaust'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getStreetMegaMenu()
    {
        $categoryNames = ['Sprockets', 'Windshield', 'Fuel & Air', 'Brake', 'Exhaust', 'Clutch & Components', 'Cables', 'Electrical', 'Body'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getAtvMegaMenu()
    {
        $categoryNames = ['Pistons', 'Clutch & Components', 'Fuel & Air', 'Sprockets', 'Gaskets & Seals', 'Brake', 'Seats', 'Tires & Wheels', 'Exhaust'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getUtvMegaMenu()
    {
        $categoryNames = ['Clutch & Components', 'Drive', 'Wheels', 'Brake', 'Pistons', 'Exhaust', 'Cabs & Accessories', 'Windshield', 'Fuel & Air', 'Tires & Wheels'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }

    public function getWatercraftMegaMenu()
    {
        $categoryNames = ['Pistons', 'Propulsion', 'Gaskets & Seals', 'Security & Covers', 'Driveline', 'Crankcase Components', 'Covers & Tops', 'Body', 'Starters', 'Engine'];
        $columns = $this->getCategoriesByNames($categoryNames);

        return response()->json([
            'success' => true,
            'columns' => $columns
        ]);
    }
}
