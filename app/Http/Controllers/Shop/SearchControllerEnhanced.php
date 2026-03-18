<?php

namespace App\Http\Controllers\Shop;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\ProductFlat;

class SearchControllerEnhanced
{
    public static function buildTagBasedProductQuery(Request $request)
    {
        $searchQuery = trim($request->input('q') ?? $request->input('query') ?? '');
        $searchQuery = $searchQuery ? preg_replace('/\s+/', ' ', $searchQuery) : null;
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

        $searchTerms = [];
        if ($searchQuery) {
            $allTerms = array_values(array_filter(array_map('trim', explode(' ', strtolower($searchQuery)))));
            $searchTerms = array_filter($allTerms, function($term) {
                return strlen($term) > 1;
            });
            $searchTerms = array_values($searchTerms);
        }

        $baseQuery = ProductFlat::where('product_flat.channel', 'maddparts')
            ->where('product_flat.locale', 'en')
            ->where('product_flat.status', 1)
            ->where('product_flat.visible_individually', 1)
            ->whereHas('product', function($q) {
                $q->whereNull('parent_id');
            })
            ->with([
                'product.images' => function($query) {
                    $query->select('id', 'path', 'product_id')->orderBy('position', 'asc')->limit(1);
                },
                'product.inventories' => function($query) {
                    $query->select('product_id', 'qty');
                },
                'product.variants' => function($variantQuery) {
                    $variantQuery->with(['images' => function($imgQuery) {
                        $imgQuery->select('id', 'path', 'product_id')->orderBy('position', 'asc')->limit(1);
                    }])
                    ->with(['product_flats' => function($flatQuery) {
                        $flatQuery->where('channel', 'maddparts')->where('locale', 'en');
                    }]);
                }
            ]);

        if (!empty($searchTerms)) {
            $tagTableExists = DB::select("SHOW TABLES LIKE 'product_search_tags'");

            if (!empty($tagTableExists)) {
                $productIdsFromTags = [];

                $phrasesToMatch = [];
                $phrasesToMatch[] = implode(' ', $searchTerms);

                if (count($searchTerms) >= 2) {
                    for ($i = 0; $i < count($searchTerms) - 1; $i++) {
                        $phrasesToMatch[] = $searchTerms[$i] . ' ' . $searchTerms[$i + 1];
                    }
                }

                $phrasesToMatch = array_merge($phrasesToMatch, $searchTerms);

                $expandedTerms = [];
                foreach ($phrasesToMatch as $term) {
                    $expandedTerms[] = $term;
                    if ($term === 'tire') $expandedTerms[] = 'tires';
                    if ($term === 'tires') $expandedTerms[] = 'tire';
                    if ($term === 'helmet') $expandedTerms[] = 'helmets';
                    if ($term === 'helmets') $expandedTerms[] = 'helmet';
                    if ($term === 'wheel') $expandedTerms[] = 'wheels';
                    if ($term === 'wheels') $expandedTerms[] = 'wheel';
                    if ($term === 'brake') $expandedTerms[] = 'brakes';
                    if ($term === 'brakes') $expandedTerms[] = 'brake';
                }

                $phrasesToMatch = array_unique($expandedTerms);

                $limitedPhrasesToMatch = array_slice($phrasesToMatch, 0, 100);

                $productIdsFromTags = DB::table('product_search_tags')
                    ->select('product_id')
                    ->whereIn('tag_value', $limitedPhrasesToMatch)
                    ->groupBy('product_id')
                    ->havingRaw('COUNT(DISTINCT tag_value) >= ?', [1])
                    ->pluck('product_id')
                    ->toArray();

                $productIdsFromName = DB::table('product_flat')
                    ->select('product_id')
                    ->where('locale', 'en')
                    ->where('status', 1)
                    ->where('visible_individually', 1)
                    ->where(function($q) use ($searchTerms) {
                        foreach ($searchTerms as $term) {
                            $q->where('name', 'like', "%{$term}%");
                        }
                    })
                    ->pluck('product_id')
                    ->toArray();

                $productIdsFromSku = DB::table('product_flat')
                    ->select('product_id')
                    ->where('locale', 'en')
                    ->where('status', 1)
                    ->where('visible_individually', 1)
                    ->where(function($q) use ($searchTerms) {
                        foreach ($searchTerms as $term) {
                            $q->where('sku', 'like', "%{$term}%");
                        }
                    })
                    ->pluck('product_id')
                    ->toArray();

                // Also search for child product SKUs and return their parents
                // Use the original search query (not split terms) for SKU matching
                $productIdsFromChildSku = [];
                if ($searchQuery) {
                    $productIdsFromChildSku = DB::table('product_flat AS child')
                        ->join('products AS child_prod', 'child.product_id', '=', 'child_prod.id')
                        ->join('products AS parent_prod', 'child_prod.parent_id', '=', 'parent_prod.id')
                        ->select('parent_prod.id as product_id')
                        ->where('child.locale', 'en')
                        ->where('child.status', 1)
                        ->whereNotNull('child_prod.parent_id')
                        ->where('child.sku', 'like', "%{$searchQuery}%")
                        ->pluck('product_id')
                        ->toArray();
                }

                // Prioritize exact name/SKU matches before tag matches
                $productIds = array_unique(array_merge($productIdsFromName, $productIdsFromSku, $productIdsFromChildSku, $productIdsFromTags));

                if (empty($productIds)) {
                    $baseQuery->whereRaw('1 = 0');
                } else {
                    $maxProductIdsInQuery = 5000;

                    if (count($productIds) > $maxProductIdsInQuery) {
                        $productIds = array_slice($productIds, 0, $maxProductIdsInQuery);
                    }

                    $baseQuery->whereIn('product_flat.product_id', $productIds);

                    // CRITICAL FIX: Limit scoring terms to prevent "too many placeholders" error
                    // MySQL has a limit of 65,535 placeholders per prepared statement
                    // We use scoringTerms 3 times in the query, so max 10 terms = 30 placeholders + 3 extra = 33 total
                    $scoringTerms = array_slice($phrasesToMatch, 0, 10);
                    $placeholders = implode(',', array_fill(0, count($scoringTerms), '?'));

                    $baseQuery->selectRaw("product_flat.*,
                        (SELECT COALESCE(SUM(CASE
                            WHEN pst.tag_type = 'part_category' THEN pst.weight * 2.0
                            WHEN pst.tag_type = 'part_brand' THEN pst.weight * 1.8
                            WHEN pst.tag_type = 'vehicle_brand' THEN pst.weight * 1.8
                            WHEN pst.tag_type = 'vehicle_type' THEN pst.weight * 1.5
                            WHEN pst.tag_type = 'feature' THEN pst.weight * 1.0
                            WHEN pst.tag_type = 'application' THEN pst.weight * 1.0
                            ELSE pst.weight
                        END), 0)
                        FROM product_search_tags pst
                        WHERE pst.product_id = product_flat.product_id
                        AND pst.tag_value IN ({$placeholders})
                        ) +
                        (SELECT CASE
                            WHEN EXISTS(
                                SELECT 1 FROM product_search_tags pb
                                WHERE pb.product_id = product_flat.product_id
                                AND pb.tag_type = 'part_brand'
                                AND pb.tag_value IN ({$placeholders})
                            ) AND EXISTS(
                                SELECT 1 FROM product_search_tags pc
                                WHERE pc.product_id = product_flat.product_id
                                AND pc.tag_type = 'part_category'
                                AND pc.tag_value IN ('tire', 'tires')
                            ) AND NOT EXISTS(
                                SELECT 1 FROM product_search_tags pw
                                WHERE pw.product_id = product_flat.product_id
                                AND pw.tag_type = 'part_category'
                                AND pw.tag_value IN ('wheel', 'wheels', 'bikes', 'bike')
                            ) THEN 2500
                            WHEN EXISTS(
                                SELECT 1 FROM product_search_tags pb
                                WHERE pb.product_id = product_flat.product_id
                                AND pb.tag_type = 'part_brand'
                                AND pb.tag_value IN ({$placeholders})
                            ) AND EXISTS(
                                SELECT 1 FROM product_search_tags pc
                                WHERE pc.product_id = product_flat.product_id
                                AND pc.tag_type = 'part_category'
                                AND pc.tag_value IN ('helmet', 'helmets', 'oil', 'brake', 'brakes', 'exhaust', 'suspension', 'wheel', 'wheels')
                            ) AND NOT EXISTS(
                                SELECT 1 FROM product_search_tags pw
                                WHERE pw.product_id = product_flat.product_id
                                AND pw.tag_type = 'part_category'
                                AND pw.tag_value IN ('bikes', 'bike')
                            ) THEN 2000
                            ELSE 0
                        END
                        ) +
                        (CASE WHEN LOWER(product_flat.sku) = ? THEN 1000 ELSE 0 END) +
                        (CASE WHEN LOWER(product_flat.name) = ? THEN 5000 ELSE 0 END) +
                        (CASE WHEN LOWER(product_flat.name) LIKE ? THEN 500 ELSE 0 END) -
                        (SELECT CASE
                            WHEN EXISTS(
                                SELECT 1 FROM product_search_tags penalty
                                WHERE penalty.product_id = product_flat.product_id
                                AND penalty.tag_type = 'part_category'
                                AND penalty.tag_value IN ('bikes', 'bike')
                            ) THEN 500
                            ELSE 0
                        END)
                        as relevance_score",
                        array_merge(
                            $scoringTerms,
                            $scoringTerms,
                            $scoringTerms,
                            [strtolower($searchQuery)],
                            [strtolower($searchQuery)],
                            ['%' . strtolower($searchQuery) . '%']
                        )
                    );

                    $baseQuery->orderBy('relevance_score', 'desc');
                }

            } else {
                $baseQuery->where(function($q) use ($searchTerms, $searchQuery) {
                    foreach ($searchTerms as $term) {
                        $q->where(function($subQuery) use ($term) {
                            $subQuery->where('name', 'like', "%{$term}%")
                                    ->orWhere('sku', 'like', "%{$term}%")
                                    ->orWhere('description', 'like', "%{$term}%")
                                    ->orWhere('short_description', 'like', "%{$term}%");
                        });
                    }
                });

                $baseQuery->orderByRaw('
                    CASE
                        WHEN LOWER(name) = ? THEN 1000
                        WHEN LOWER(sku) = ? THEN 900
                        WHEN LOWER(name) LIKE ? THEN 800
                        ELSE 100
                    END DESC',
                    [strtolower($searchQuery), strtolower($searchQuery), '%' . strtolower($searchQuery) . '%']
                );
            }
        }

        if ($categorySlug && $categorySlug !== '') {
            $category = \Webkul\Category\Models\Category::whereHas('translations', function ($q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            })->first();

            if ($category) {
                $categoryIds = [$category->id];

                $childCategories = \Webkul\Category\Models\Category::where('parent_id', $category->id)->pluck('id')->toArray();
                if (!empty($childCategories)) {
                    $categoryIds = array_merge($categoryIds, $childCategories);
                }

                $baseQuery->whereHas('product.categories', function ($q) use ($categoryIds) {
                    $q->whereIn('categories.id', $categoryIds);
                });
            }
        }

        if (!empty($brands)) {
            $brandOptionIds = DB::table('attribute_options')
                ->where('attribute_id', 25)
                ->whereIn('admin_name', $brands)
                ->pluck('id')
                ->toArray();

            if (!empty($brandOptionIds)) {
                $baseQuery->whereHas('product.attribute_values', function($attrQuery) use ($brandOptionIds) {
                    $attrQuery->where('attribute_id', 25)->whereIn('text_value', $brandOptionIds);
                });
            }
        }

        if (!empty($categories)) {
            $baseQuery->whereHas('product.categories', function($catQuery) use ($categories) {
                $catQuery->whereIn('category_id', $categories);
            });
        }

        if ($minPrice || $maxPrice) {
            if ($minPrice) {
                $baseQuery->where('price', '>=', $minPrice);
            }
            if ($maxPrice) {
                $baseQuery->where('price', '<=', $maxPrice);
            }
        }

        if ($inStock == '1' || $inStock === true) {
            $baseQuery->whereHas('product.inventories', function($invQuery) {
                $invQuery->where('qty', '>', 0);
            });
        }

        switch ($sortBy) {
            case 'name_az':
                $baseQuery->orderBy('name', 'asc');
                break;
            case 'name_za':
                $baseQuery->orderBy('name', 'desc');
                break;
            case 'price_low':
            case 'price_low_high':
                $baseQuery->orderByRaw('CASE
                    WHEN special_price IS NOT NULL AND special_price > 0 AND special_price < price
                    THEN special_price
                    ELSE price
                END ASC');
                break;
            case 'price_high':
            case 'price_high_low':
                $baseQuery->orderByRaw('CASE
                    WHEN special_price IS NOT NULL AND special_price > 0 AND special_price < price
                    THEN special_price
                    ELSE price
                END DESC');
                break;
            case 'newest':
                $baseQuery->orderBy('created_at', 'desc');
                break;
            case 'oldest':
                $baseQuery->orderBy('created_at', 'asc');
                break;
            default:
                if (empty($searchTerms)) {
                    $baseQuery->orderBy('name', 'asc');
                }
                break;
        }

        return $baseQuery;
    }
}
