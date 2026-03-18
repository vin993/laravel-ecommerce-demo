<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductFlat;
use Webkul\Theme\Repositories\ThemeCustomizationRepository;

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
        try {
            visitor()->visit();
        } catch (\Exception $e) {
            \Log::error('Visitor error: ' . $e->getMessage());
        }

        $customizations = $this->themeCustomizationRepository->orderBy('sort_order')->findWhere([
            'status'     => self::STATUS,
            'channel_id' => core()->getCurrentChannel()->id,
            'theme_code' => core()->getCurrentChannel()->theme,
        ]);

        $categories = collect();
        $bestSellers = collect();
        $topRated = collect();

        try {
            $excludedCategories = ['Spreaders', 'Plows', 'DVDs', 'Mower', 'Displays'];
            $locale = 'en';

            $rootCategory = Category::where('status', 1)
                ->whereNull('parent_id')
                ->first();

            if ($rootCategory) {
                $categories = Category::where('status', 1)
                    ->where('parent_id', $rootCategory->id)
                    ->select(['id', 'position', 'status', 'logo_path', 'banner_path', 'parent_id', '_lft', '_rgt'])
                    ->with(['translations'])
                    ->orderBy('position')
                    ->limit(12)
                    ->get()
                    ->filter(function ($category) use ($locale, $excludedCategories) {
                        $translation = $category->translate($locale);
                        $categoryName = $translation->name ?? '';

                        foreach ($excludedCategories as $excluded) {
                            if (strcasecmp($categoryName, $excluded) === 0) {
                                return false;
                            }
                        }

                        return true;
                    });
            }
        } catch (\Exception $e) {
            \Log::error('Categories error: ' . $e->getMessage());
        }

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

            if ($bestSellerIds->isNotEmpty()) {
                $bestSellers = ProductFlat::where('channel', 'maddparts')
                    ->where('locale', 'en')
                    ->where('status', 1)
                    ->whereIn('product_id', $bestSellerIds)
                    ->with(['product.images'])
                    ->limit(4)
                    ->get()
                    ->map(function ($product) {
                        $firstImage = $product->product->images->where('path', '!=', '')
                                                              ->where('path', '!=', 'product-image-placeholder.png')
                                                              ->first();
                        if ($firstImage) {
                            $product->image_url = asset('storage/' . $firstImage->path);
                        } else {
                            $product->image_url = null;
                        }
                        return $product;
                    });
            }
        } catch (\Exception $e) {
            \Log::error('Best sellers error: ' . $e->getMessage());
        }

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

            if ($topRatedIds->isNotEmpty()) {
                $topRated = ProductFlat::where('channel', 'maddparts')
                    ->where('locale', 'en')
                    ->where('status', 1)
                    ->whereIn('product_id', $topRatedIds)
                    ->with(['product.images'])
                    ->limit(4)
                    ->get()
                    ->map(function ($product) {
                        $firstImage = $product->product->images->where('path', '!=', '')
                                                              ->where('path', '!=', 'product-image-placeholder.png')
                                                              ->first();
                        if ($firstImage) {
                            $product->image_url = asset('storage/' . $firstImage->path);
                        } else {
                            $product->image_url = null;
                        }
                        return $product;
                    });
            }
        } catch (\Exception $e) {
            \Log::error('Top rated error: ' . $e->getMessage());
        }

        return view('home.index', compact('customizations', 'categories', 'bestSellers', 'topRated'));
    }

    public function contact()
    {
        return view('home.contact');
    }
}
