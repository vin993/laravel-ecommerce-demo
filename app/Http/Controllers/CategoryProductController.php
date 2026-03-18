<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Webkul\Category\Models\Category;
use Webkul\Product\Models\ProductFlat;

class CategoryProductController extends Controller
{
    public function show($slug)
    {
        $category = Category::whereHas('translations', function ($query) use ($slug) {
            $query->where('slug', $slug);
        })->first();

        if (! $category) {
            abort(404, 'Category not found');
        }

        $products = ProductFlat::leftJoin('product_categories', 'product_flat.product_id', '=', 'product_categories.product_id')
            ->where('product_categories.category_id', $category->id)
            ->where('product_flat.status', 1)
            ->where('product_flat.visible_individually', 1)
            ->where('product_flat.channel', 'maddparts')
            ->where('product_flat.locale', 'en')
            ->with(['product.images']) 
            ->select('product_flat.*')
            ->paginate(12);

        $products->getCollection()->transform(function ($product) {
            $productImage = $product->product->images->first();
            $product->image_url = $productImage 
                ? asset('storage/' . $productImage->path) 
                : asset('themes/maddparts/images/placeholder.jpg');
            return $product;
        });

        return view('shop::products.list', compact('category', 'products'));
    }
}