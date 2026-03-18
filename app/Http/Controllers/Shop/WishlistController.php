<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Webkul\Customer\Repositories\WishlistRepository;
use Webkul\Product\Repositories\ProductRepository;

class WishlistController extends Controller
{
    protected $wishlistRepository;
    protected $productRepository;

    public function __construct(
        WishlistRepository $wishlistRepository,
        ProductRepository $productRepository
    ) {
        $this->wishlistRepository = $wishlistRepository;
        $this->productRepository = $productRepository;
    }

    public function index()
    {
        if (!auth()->guard('customer')->check()) {
            return redirect()->route('customer.session.index')
                ->with('error', 'Please login to view your wishlist');
        }

        $customer = auth()->guard('customer')->user();
        $customerId = $customer->id;

        $ordersCount = \DB::table('orders')
            ->where(function ($query) use ($customer) {
                $query->where('customer_id', $customer->id)
                      ->where(function ($subQuery) use ($customer) {
                          $subQuery->where('customer_type', 'Webkul\\Customer\\Models\\Customer')
                                   ->orWhere('is_guest', 0)
                                   ->orWhere('customer_email', $customer->email);
                      });
            })
            ->count();

        $wishlistItems = $this->wishlistRepository->findWhere([
            'customer_id' => $customerId
        ]);

        $wishlistWithProducts = $wishlistItems->map(function ($item) {
            $product = $this->productRepository->with('images')->find($item->product_id);
            if ($product) {
                // Get product_flat data for display information
                $productFlat = \DB::table('product_flat')
                    ->where('product_id', $product->id)
                    ->where('channel', 'maddparts')
                    ->where('locale', 'en')
                    ->first();

                // Get product image
                $imageUrl = asset('themes/maddparts/images/product-placeholder.png');
                if ($product->images->count() > 0) {
                    $imageUrl = $product->images->first()->url;
                }

                // Create a simple array with all needed properties for JSON serialization
                if ($productFlat) {
                    // Check if product is configurable (has variants)
                    $isConfigurable = strpos($productFlat->sku, '-PARENT') !== false;

                    $item->product = (object) [
                        'id' => $product->id,
                        'sku' => $productFlat->sku,
                        'name' => $productFlat->name,
                        'url_key' => $productFlat->url_key,
                        'price' => $productFlat->price,
                        'special_price' => $productFlat->special_price,
                        'image_url' => $imageUrl,
                        'type' => $product->type,
                        'is_configurable' => $isConfigurable
                    ];
                }
            }
            return $item;
        })->filter(function ($item) {
            return isset($item->product);
        });

        return view('customers.account.wishlist', compact('wishlistWithProducts', 'ordersCount'));
    }

    public function toggle(Request $request)
    {
        try {
            if (!auth()->guard('customer')->check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please login to add products to wishlist',
                    'redirect' => route('customer.session.index')
                ], 401);
            }

            $request->validate([
                'product_id' => 'required|integer'
            ]);

            $product = $this->productRepository->find($request->product_id);

            if (!$product) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Product not found'
                ], 404);
            }

            $customerId = auth()->guard('customer')->id();

            $wishlistItem = $this->wishlistRepository->findWhere([
                'customer_id' => $customerId,
                'product_id' => $request->product_id
            ])->first();

            if ($wishlistItem) {
                $this->wishlistRepository->delete($wishlistItem->id);
                $message = 'Product removed from wishlist!';
                $action = 'removed';
            } else {
                $this->wishlistRepository->create([
                    'customer_id' => $customerId,
                    'product_id' => $request->product_id,
                    'channel_id' => core()->getCurrentChannel()->id
                ]);
                $message = 'Product added to wishlist!';
                $action = 'added';
            }

            return response()->json([
                'status' => 'success',
                'message' => $message,
                'action' => $action
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error updating wishlist: ' . $e->getMessage()
            ], 500);
        }
    }

    public function clearAll()
    {
        try {
            if (!auth()->guard('customer')->check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Please login to clear wishlist'
                ], 401);
            }

            $customerId = auth()->guard('customer')->id();

            $this->wishlistRepository->deleteWhere([
                'customer_id' => $customerId
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Wishlist cleared successfully!'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error clearing wishlist: ' . $e->getMessage()
            ], 500);
        }
    }
   
    public function getWishlistCount()
    {
        try {
            if (!auth()->guard('customer')->check()) {
                return response()->json([
                    'status' => 'success',
                    'count' => 0
                ]);
            }

            $customerId = auth()->guard('customer')->id();

            $count = $this->wishlistRepository->findWhere([
                'customer_id' => $customerId
            ])->count();

            return response()->json([
                'status' => 'success',
                'count' => $count
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error fetching wishlist count: ' . $e->getMessage(),
                'count' => 0
            ], 500);
        }
    }

    public function getWishlistItems()
    {
        try {
            if (!auth()->guard('customer')->check()) {
                return response()->json([
                    'success' => true,
                    'product_ids' => []
                ]);
            }

            $customerId = auth()->guard('customer')->id();

            $productIds = $this->wishlistRepository->findWhere([
                'customer_id' => $customerId
            ])->pluck('product_id')->toArray();

            return response()->json([
                'success' => true,
                'product_ids' => $productIds
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error fetching wishlist items: ' . $e->getMessage(),
                'product_ids' => []
            ], 500);
        }
    }
}