<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\AriController;
use App\Http\Controllers\CategoryProductController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Shop\HomeController;
use App\Http\Controllers\Shop\CategoryController;
use App\Http\Controllers\Shop\ProductController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\WishlistController;
use App\Http\Controllers\Shop\ReviewController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\SearchController;
use App\Http\Controllers\Shop\VehicleFitmentController;
use App\Http\Controllers\Customer\ForgotPasswordController;
use App\Http\Controllers\Customer\ResetPasswordController;
use App\Http\Controllers\Customer\AddressController;


Route::group(['middleware' => ['web', 'shop']], function () {
    Route::get('/', [HomeController::class, 'index'])->name('shop.home.index');
    Route::get('/api/home/featured-products', [HomeController::class, 'getFeaturedProducts'])->name('api.home.featured');

    Route::get('/contact', [HomeController::class, 'contact'])->name('shop.home.contact');

    Route::post('/contact/send', [ContactController::class, 'send'])
        ->middleware(['throttle:3,60', 'contact.spam'])
        ->name('shop.home.contact.send');
});

Route::group(['middleware' => ['web']], function () {
    Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
    Route::post('/cart/remove', [CartController::class, 'remove'])->name('cart.remove');
    Route::get('/cart/get', [CartController::class, 'getCart'])->name('cart.get');
    Route::post('/cart/update-supplier', [CartController::class, 'updateSupplier'])->name('cart.update-supplier');

    Route::post('/cart/ari/ajax/add', [CartController::class, 'addAriViaAjax'])->name('cart.ari.ajax.add');
    Route::post('/cart/ari/remove', [CartController::class, 'removeAriItem'])->name('cart.ari.remove');
    Route::post('/cart/ari/update', [CartController::class, 'updateAriItem'])->name('cart.ari.update');
    Route::post('/cart/ari/clear', [CartController::class, 'clearAri'])->name('cart.ari.clear');

    Route::post('/cart/coupon/apply', [CartController::class, 'applyCoupon'])->name('cart.coupon.apply');
    Route::post('/cart/coupon/remove', [CartController::class, 'removeCoupon'])->name('cart.coupon.remove');

    Route::post('/wishlist/toggle', [WishlistController::class, 'toggle'])->name('wishlist.toggle');
    Route::post('/wishlist/clear', [WishlistController::class, 'clearAll'])->name('wishlist.clear');
    Route::get('/wishlist/count', [WishlistController::class, 'getWishlistCount'])->name('wishlist.count');
    Route::get('/api/wishlist/items', [WishlistController::class, 'getWishlistItems'])->name('wishlist.items');

    Route::post('/reviews/add', [ReviewController::class, 'store'])->name('reviews.add');
});

// OEM Parts route
Route::get('/oem-parts', [AriController::class, 'showPartStream'])->name('ari.partstream');

// Kawasaki Products routes
Route::get('/kawasaki-products', [\App\Http\Controllers\KawasakiProductController::class, 'index'])->name('kawasaki.products');
Route::post('/kawasaki-products/filter', [\App\Http\Controllers\KawasakiProductController::class, 'filter'])->name('kawasaki.products.filter');
// Alternative route for JavaScript-constructed URL
Route::post('/category/kawasaki-products/filter', [\App\Http\Controllers\KawasakiProductController::class, 'filter']);

// WPS Image Proxy Route
Route::get('/storage/wps/{filename}', function ($filename) {
    $mapping = DB::table('wps_image_urls')->where('path', 'wps/' . $filename)->first();
    if ($mapping) {
        return redirect($mapping->source_url);
    }
    abort(404);
})->name('wps.image.proxy');

// Customer Authentication Routes
Route::group(['prefix' => 'customer', 'middleware' => ['web']], function () {
    // Login routes
    Route::get('/login', function () {
        if (auth()->guard('customer')->check()) {
            return redirect('/')->with('success', 'You are already logged in!');
        }
        return view('customers.sign-in');
    })->name('customer.session.index');

    Route::post('/login', function (Request $request) {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (auth()->guard('customer')->attempt($request->only('email', 'password'), $request->has('remember'))) {
            return redirect()->intended('/')->with('success', 'Welcome back!');
        }

        return back()->withErrors(['email' => 'Invalid credentials. Please check your email and password.'])->withInput($request->only('email'));
    })->name('customer.session.create');

    // Registration routes
    Route::get('/register', function () {
        if (auth()->guard('customer')->check()) {
            return redirect('/')->with('success', 'You are already logged in!');
        }
        return view('customers.sign-up');
    })->name('customer.register.index');

    Route::post('/register', function (Request $request) {
        try {
            $validated = $request->validate([
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'email' => 'required|email|unique:customers,email',
                'password' => 'required|min:6|confirmed',
                'terms' => 'required|accepted'
            ], [
                'terms.accepted' => 'You must agree to the terms and conditions.',
                'password.confirmed' => 'Password confirmation does not match.',
                'email.unique' => 'An account with this email already exists.',
                'first_name.required' => 'First name is required.',
                'last_name.required' => 'Last name is required.',
                'email.required' => 'Email address is required.',
                'password.min' => 'Password must be at least 6 characters long.'
            ]);
            $channel = core()->getCurrentChannel();
            $defaultGroup = $channel->default_group;
            if (!$defaultGroup) {
                $defaultGroup = \Webkul\Customer\Models\CustomerGroup::first();
                if (!$defaultGroup) {
                    $defaultGroup = \Webkul\Customer\Models\CustomerGroup::create([
                        'name' => 'General',
                        'code' => 'general',
                        'is_user_defined' => 0
                    ]);
                }
            }
            $customer = new \Webkul\Customer\Models\Customer();
            $customer->first_name = $validated['first_name'];
            $customer->last_name = $validated['last_name'];
            $customer->email = $validated['email'];
            $customer->password = bcrypt($validated['password']);
            $customer->channel_id = $channel->id;
            $customer->customer_group_id = $defaultGroup->id;
            $customer->is_verified = 1;
            $customer->status = 1;
            $customer->save();

            try {
                Mail::send(new \App\Mail\CustomerRegistration($customer));
                \Log::info('Welcome email sent to new customer', [
                    'customer_id' => $customer->id,
                    'email' => $customer->email
                ]);
            } catch (\Exception $emailError) {
                \Log::error('Failed to send welcome email', [
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                    'error' => $emailError->getMessage()
                ]);
            }

            return redirect()->route('customer.session.index')->with('success', 'Registration successful! Please login to continue.');

        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($request->except('password', 'password_confirmation'));
        } catch (\Exception $e) {
            \Log::error('Registration error: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());

            return back()->withErrors(['error' => 'Registration failed: ' . $e->getMessage()])->withInput($request->except('password', 'password_confirmation'));
        }
    })->name('customer.register.create');

    // Logout routes
    Route::post('/logout', function () {
        auth()->guard('customer')->logout();
        return redirect('/')->with('success', 'Logged out successfully!');
    })->name('customer.session.destroy');

    Route::get('/logout', function () {
        auth()->guard('customer')->logout();
        return redirect('/')->with('success', 'Logged out successfully!');
    });

    // Forgot password routes
    Route::get('/forgot-password', function () {
        return view('customers.forgot-password');
    })->name('customer.forgot.password.create');

    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('shop.customers.forgot_password.store');

    Route::get('/reset-password/{token}', function ($token) {
        return view('customers.reset-password')->with([
            'token' => $token,
            'email' => request('email'),
        ]);
    })->name('shop.customers.reset_password.create');

    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('shop.customers.reset_password.store');
});

// Customer account routes (protected)
Route::group(['prefix' => 'customer', 'middleware' => 'customer.auth'], function () {
    // Account dashboard
    Route::get('/account', function () {
        $customer = auth()->guard('customer')->user();

        $ordersCount = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->where('is_guest', 0)
            ->count();

        $wishlistCount = DB::table('wishlist_items')
            ->where('customer_id', $customer->id)
            ->count();

        $addressesCount = DB::table('addresses')
            ->where('customer_id', $customer->id)
            ->count();

        $reviewsCount = DB::table('product_reviews')
            ->where('customer_id', $customer->id)
            ->count();

        return view('customers.account.index', compact(
            'ordersCount',
            'wishlistCount',
            'addressesCount',
            'reviewsCount'
        ));
    })->name('customer.account.index');

    // Orders
    Route::get('/orders', function () {
        $customer = auth()->guard('customer')->user();

        $ordersCount = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->where('is_guest', 0)
            ->count();

        $orders = DB::table('orders')
            ->select(
                'orders.id',
                'orders.increment_id',
                'orders.status',
                'orders.created_at',
                'orders.updated_at',
                'orders.grand_total',
                'orders.order_currency_code',
                'orders.customer_email',
                'orders.customer_first_name',
                'orders.customer_last_name',
                'orders.is_guest'
            )
            ->where('orders.customer_id', $customer->id)
            ->where('orders.is_guest', 0)
            ->orderBy('orders.created_at', 'desc')
            ->paginate(10);

        foreach ($orders as $order) {
            $orderItems = DB::table('order_items')
                ->where('order_id', $order->id)
                ->whereNull('parent_id')
                ->get();

            $order->items = $orderItems->map(function($item) {
                $productUrl = null;
                $productImage = asset('themes/maddparts/images/placeholder.jpg');

                if ($item->product_id) {
                    $productFlat = DB::table('product_flat')
                        ->where('product_id', $item->product_id)
                        ->where('channel', 'maddparts')
                        ->where('locale', 'en')
                        ->first();

                    if ($productFlat && $productFlat->url_key) {
                        $productUrl = url($productFlat->url_key);
                    }

                    $image = DB::table('product_images')
                        ->where('product_id', $item->product_id)
                        ->orderBy('position')
                        ->first();

                    if ($image) {
                        $productImage = asset('storage/' . $image->path);
                    }
                }

                $item->product_url = $productUrl;
                $item->product_image = $productImage;

                return $item;
            });

            $ariItems = DB::table('ari_partstream_order_items')
                ->where('order_id', $order->id)
                ->get();

            $order->ari_items = $ariItems->map(function($item) {
                $productUrl = null;
                $productImage = $item->image_url ?? asset('themes/maddparts/images/placeholder.jpg');

                return (object)[
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'brand' => $item->brand ?? null,
                    'price' => $item->price,
                    'quantity' => $item->quantity,
                    'total' => $item->total,
                    'product_url' => $productUrl,
                    'product_image' => $productImage
                ];
            });

            $order->items_count = count($orderItems) + count($ariItems);

            $order->oem_items = DB::table('order_items')
                ->where('order_id', $order->id)
                ->whereNull('parent_id')
                ->where(function($query) {
                    $query->where('type', 'oem')
                          ->orWhere('sku', 'like', 'OEM-%')
                          ->orWhere('name', 'like', '%OEM%');
                })
                ->get()
                ->map(function($item) {
                    $productUrl = null;
                    $productImage = asset('themes/maddparts/images/placeholder.jpg');

                    if ($item->product_id) {
                        $productFlat = DB::table('product_flat')
                            ->where('product_id', $item->product_id)
                            ->where('channel', 'maddparts')
                            ->where('locale', 'en')
                            ->first();

                        if ($productFlat && $productFlat->url_key) {
                            $productUrl = url($productFlat->url_key);
                        }

                        $image = DB::table('product_images')
                            ->where('product_id', $item->product_id)
                            ->orderBy('position')
                            ->first();

                        if ($image) {
                            $productImage = asset('storage/' . $image->path);
                        }
                    }

                    return (object)[
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'price' => $item->price,
                        'quantity' => $item->qty_ordered,
                        'product_url' => $productUrl,
                        'product_image' => $productImage
                    ];
                });
        }

        return view('customers.account.orders', compact('orders', 'ordersCount'));
    })->name('customer.orders.index');

    Route::get('/orders/{id}', function ($id) {
        $customer = auth()->guard('customer')->user();

        $order = DB::table('orders')
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->where('is_guest', 0)
            ->first();

        if (!$order) {
            abort(404);
        }

        $order->items = DB::table('order_items')
            ->where('order_id', $order->id)
            ->whereNull('parent_id')
            ->get()
            ->map(function($item) {
                $productUrl = null;
                $productImage = asset('themes/maddparts/images/placeholder.jpg');

                if ($item->product_id) {
                    $productFlat = DB::table('product_flat')
                        ->where('product_id', $item->product_id)
                        ->where('channel', 'maddparts')
                        ->where('locale', 'en')
                        ->first();

                    if ($productFlat && $productFlat->url_key) {
                        $productUrl = url($productFlat->url_key);
                    }

                    $image = DB::table('product_images')
                        ->where('product_id', $item->product_id)
                        ->orderBy('position')
                        ->first();

                    if ($image) {
                        $productImage = asset('storage/' . $image->path);
                    }
                }

                $item->product_url = $productUrl;
                $item->product_image = $productImage;

                return $item;
            });

        $ariItems = DB::table('ari_partstream_order_items')
            ->where('order_id', $order->id)
            ->get();

        $ordersCount = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->where('is_guest', 0)
            ->count();

        return view('customers.account.orders.view', compact('order', 'ariItems', 'ordersCount'));
    })->name('customer.orders.view');

    // Wishlist
    Route::get('/wishlist', [WishlistController::class, 'index'])->name('customer.wishlist.index');

    // Addresses
    Route::get('/addresses', [AddressController::class, 'index'])->name('customer.addresses.index');
    Route::post('/addresses', [AddressController::class, 'store'])->name('customer.addresses.store');
    Route::put('/addresses/{id}', [AddressController::class, 'update'])->name('customer.addresses.update');
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy'])->name('customer.addresses.destroy');

    Route::get('/profile/edit', function () {
        $customer = auth()->guard('customer')->user();

        $ordersCount = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->where('is_guest', 0)
            ->count();

        return view('customers.account.profile', compact('ordersCount'));
    })->name('customer.profile.edit');

    Route::put('/profile/update', function (Request $request) {
        $customer = auth()->guard('customer')->user();

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date'
        ]);

        $customer->update($request->only(['first_name', 'last_name', 'phone', 'date_of_birth']));

        return back()->with('success', 'Profile updated successfully!');
    })->name('customer.profile.update');

    Route::put('/password/update', function (Request $request) {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:6|confirmed'
        ]);

        $customer = auth()->guard('customer')->user();

        if (!Hash::check($request->current_password, $customer->password)) {
            return back()->withErrors(['current_password' => 'Current password is incorrect']);
        }

        $customer->update(['password' => bcrypt($request->new_password)]);

        return back()->with('success', 'Password changed successfully!');
    })->name('customer.password.update');

    Route::get('/reviews', function () {
        $customer = auth()->guard('customer')->user();

        $ordersCount = DB::table('orders')
            ->where('customer_id', $customer->id)
            ->where('is_guest', 0)
            ->count();

        $reviews = DB::table('product_reviews')
            ->where('customer_id', $customer->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        foreach ($reviews as $review) {
            $product = DB::table('product_flat')
                ->where('product_id', $review->product_id)
                ->where('channel', 'maddparts')
                ->where('locale', 'en')
                ->first();

            if ($product) {
                $productImage = DB::table('product_images')
                    ->where('product_id', $review->product_id)
                    ->orderBy('position', 'asc')
                    ->first();

                $product->image_path = $productImage ? $productImage->path : null;
            }

            $review->product = $product;
        }

        return view('customers.account.my-reviews', compact('reviews', 'ordersCount'));
    })->name('customer.reviews.index');

    Route::delete('/reviews/{id}', function ($id) {
        $customer = auth()->guard('customer')->user();

        $review = DB::table('product_reviews')
            ->where('id', $id)
            ->where('customer_id', $customer->id)
            ->first();

        if (!$review) {
            return response()->json([
                'status' => 'error',
                'message' => 'Review not found or unauthorized'
            ], 404);
        }

        DB::table('product_reviews')->where('id', $id)->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Review deleted successfully'
        ]);
    })->name('customer.reviews.destroy');
});

// Cart routes - Updated to handle both URL structures
Route::get('/cart', [CartController::class, 'index'])->name('shop.checkout.cart.index');
Route::get('/checkout/cart', [CartController::class, 'index'])->name('shop.checkout.cart.bagisto'); // ARI uses this URL
Route::post('/cart/update', [CartController::class, 'update'])->name('shop.checkout.cart.update');
Route::get('/cart/remove/{productId}', [CartController::class, 'removeItem'])->name('shop.checkout.cart.remove');
Route::post('/cart/clear', function () {
    session()->forget('cart_items');
    session()->forget('ari_cart_items');
    return redirect()->route('shop.checkout.cart.index')->with('success', 'Cart cleared successfully!');
})->name('cart.clear');

// WPS Checkout Routes
Route::group(['middleware' => ['web']], function () {
    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::post('/checkout/shipping', [CheckoutController::class, 'processShipping'])->name('checkout.process-shipping');
    Route::get('/checkout/shipping-method', [CheckoutController::class, 'shippingMethod'])->name('checkout.shipping_method');
    Route::post('/checkout/shipping-method', [CheckoutController::class, 'processShippingMethod'])->name('checkout.shipping_method.process');
    Route::get('/checkout/payment', [CheckoutController::class, 'payment'])->name('checkout.payment');
    Route::post('/checkout/payment', [CheckoutController::class, 'processPayment'])->name('checkout.process-payment');
    Route::post('/checkout/create-paypal-order', [CheckoutController::class, 'createPaypalOrder'])->name('checkout.create-paypal-order');
    Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');
    Route::post('/checkout/order-status', [CheckoutController::class, 'getOrderStatus'])->name('checkout.order-status');
    Route::post('/checkout/calculate-tax', [CheckoutController::class, 'calculateTaxAjax'])->name('checkout.calculate-tax');
    Route::post('/checkout/log-error', [CheckoutController::class, 'logError'])->name('checkout.log-error');
    Route::get('/wps/warehouses/{itemId}', [CheckoutController::class, 'getWpsWarehouses'])->name('wps.warehouses');
    Route::post('/store-warehouse-selections', [CheckoutController::class, 'storeWarehouseSelections']);

    Route::get('/checkout/debug-test', [\App\Http\Controllers\Shop\CheckoutDebugController::class, 'testLog'])->name('checkout.debug.test');
    Route::post('/checkout/payment-debug', [\App\Http\Controllers\Shop\CheckoutDebugController::class, 'processPaymentDebug'])->name('checkout.payment.debug');
});

// Stripe Webhook (no CSRF protection)
Route::post('/stripe/webhook', [CheckoutController::class, 'stripeWebhook'])
    ->name('stripe.webhook');

// ShipStation Webhook (no CSRF protection)
Route::post('/webhooks/shipstation', [\App\Http\Controllers\Webhook\ShipStationWebhookController::class, 'handle'])
    ->name('shipstation.webhook');

// Stripe Webhook (no CSRF protection)
Route::post('/webhooks/stripe', [\App\Http\Controllers\Webhook\StripeWebhookController::class, 'handleWebhook'])
    ->name('stripe.invoice.webhook');

Route::get('/api/header-categories', [HomeController::class, 'getHeaderCategories'])->name('header.categories');
Route::get('/api/mega-menu-categories', [HomeController::class, 'getMegaMenuCategories'])->name('mega.menu.categories');
Route::get('/api/search-categories', [HomeController::class, 'getSearchCategories'])->name('search.categories');

Route::get('/api/mega-menu/accessories', [HomeController::class, 'getAccessoriesMegaMenu'])->name('mega.menu.accessories');
Route::get('/api/mega-menu/gear', [HomeController::class, 'getGearMegaMenu'])->name('mega.menu.gear');
Route::get('/api/mega-menu/maintenance', [HomeController::class, 'getMaintenanceMegaMenu'])->name('mega.menu.maintenance');
Route::get('/api/mega-menu/tires', [HomeController::class, 'getTiresMegaMenu'])->name('mega.menu.tires');
Route::get('/api/mega-menu/dirt-bike', [HomeController::class, 'getDirtBikeMegaMenu'])->name('mega.menu.dirt-bike');
Route::get('/api/mega-menu/street', [HomeController::class, 'getStreetMegaMenu'])->name('mega.menu.street');
Route::get('/api/mega-menu/atv', [HomeController::class, 'getAtvMegaMenu'])->name('mega.menu.atv');
Route::get('/api/mega-menu/utv', [HomeController::class, 'getUtvMegaMenu'])->name('mega.menu.utv');
Route::get('/api/mega-menu/watercraft', [HomeController::class, 'getWatercraftMegaMenu'])->name('mega.menu.watercraft');

Route::get('/api/oem-discount/settings', [\App\Http\Controllers\Api\OemDiscountController::class, 'getSettings'])->name('api.oem-discount.settings');

// Simple autocomplete endpoint that returns empty results (search is disabled)
Route::get('/api/search/autocomplete', function () {
    return response()->json(['success' => true, 'products' => []]);
});

// Route::get('/search', [SearchController::class, 'index'])->name('shop.search');
// Route::get('/search/autocomplete', [SearchController::class, 'autocomplete'])->name('shop.search.autocomplete');
// Route::post('/search/upload', [SearchController::class, 'upload'])->name('shop.search.upload');

// Route::get('/products', [SearchController::class, 'allProducts'])->name('shop.products.all');

// Route::get('/brands', [SearchController::class, 'brands'])->name('shop.brands');
// Route::get('/brands/{brand}', [SearchController::class, 'brandProducts'])->where('brand', '.*')->name('shop.brands.products');

// AJAX filter routes
// Route::post('/category/{slug}/filter', [CategoryController::class, 'getFilteredProducts'])->name('category.filter');
// Route::post('/search/filter', [SearchController::class, 'getFilteredProducts'])->name('search.filter');
// Route::post('/brands/{brand}/filter', [SearchController::class, 'getBrandFilteredProducts'])->where('brand', '.*')->name('brands.filter');
// Route::post('/search-by-vehicle/filter', [VehicleFitmentController::class, 'filterByVehicle'])->name('vehicle.filter');

// Product dropshipper check AJAX endpoint (rate limited: 60 requests per minute per IP)
Route::get('/api/product/{productId}/check-dropshippers', [ProductController::class, 'checkDropshippersAjax'])
    ->middleware('throttle:60,1')
    ->name('api.product.check_dropshippers');

// Vehicle fitment routes
Route::get('/api/vehicle-types', [VehicleFitmentController::class, 'getTypes'])->name('api.vehicle.types');
Route::get('/api/makes', [VehicleFitmentController::class, 'getMakes'])->name('api.vehicle.makes');
Route::get('/api/models', [VehicleFitmentController::class, 'getModels'])->name('api.vehicle.models');
Route::get('/api/years', [VehicleFitmentController::class, 'getYears'])->name('api.vehicle.years');
Route::get('/api/search-by-vehicle', [VehicleFitmentController::class, 'searchByVehicle'])->name('api.vehicle.search');
Route::get('/search-by-vehicle', [VehicleFitmentController::class, 'showSearchPage'])->name('vehicle.search.page');

// Admin routes (protected by admin middleware in Bagisto)
Route::group(['prefix' => 'admin', 'middleware' => ['web', 'admin']], function () {
    Route::get('/settings/oem-discount', [\App\Http\Controllers\Admin\OemDiscountController::class, 'index'])->name('admin.oem-discount.index');
    Route::put('/settings/oem-discount', [\App\Http\Controllers\Admin\OemDiscountController::class, 'update'])->name('admin.oem-discount.update');
    
    Route::get('/settings/free-shipping', [\App\Http\Controllers\Admin\FreeShippingController::class, 'index'])->name('admin.free-shipping.index');
    Route::put('/settings/free-shipping', [\App\Http\Controllers\Admin\FreeShippingController::class, 'update'])->name('admin.free-shipping.update');
});


// Dynamic slug route - Redirect all product/category pages to OEM Parts (except Kawasaki)
Route::get('/{slug}', function ($slug) {
    $skipPaths = ['api', 'admin', 'storage', 'cart', 'customer', 'checkout', 'wishlist', 'oem-parts', 'contact', 'page', 'kawasaki-products'];
    if (in_array($slug, $skipPaths)) {
        abort(404);
    }

    try {
        // Check if this is a Kawasaki product URL - FIXED: Use INNER JOIN instead of LEFT JOIN
        $kawasakiProduct = DB::table('product_flat')
            ->where('url_key', $slug)
            ->where('product_flat.status', 1)
            ->where('product_flat.channel', 'maddparts')
            ->where('product_flat.locale', 'en')
            ->join('product_attribute_values as brand_attr', function($join) {
                $join->on('product_flat.product_id', '=', 'brand_attr.product_id')
                    ->where('brand_attr.attribute_id', 25)
                    ->where('brand_attr.channel', 'maddparts')
                    ->where('brand_attr.locale', 'en')
                    ->where('brand_attr.text_value', 'Kawasaki');
            })
            ->first();

        // If it's a Kawasaki product, show the product page
        if ($kawasakiProduct) {
            return app(\App\Http\Controllers\Shop\ProductController::class)->view($slug);
        }
    } catch (\Exception $e) {
        \Log::error('Error in catch-all route for slug: ' . $slug, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    // Redirect all other product/category attempts to OEM parts page
    return redirect('/oem-parts')->with('info', 'Product pages are no longer available. Browse our OEM parts instead!');
})->name('shop.product_or_category.view');

