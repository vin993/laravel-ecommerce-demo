<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Webkul\Checkout\Repositories\CartRepository;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use App\Services\Dropship\WpsDropshipService;
use App\Services\Dropship\PartsUnlimitedDropshipService;
use App\Services\Dropship\Turn14DropshipService;
use App\Services\Dropship\HelmetHouseDropshipService;
use Exception;

class CartController extends Controller
{
    protected $cartRepository;
    protected $productRepository;
    protected $customerRepository;
    protected $wpsService;
    protected $partsUnlimitedService;
    protected $turn14Service;
    protected $helmetHouseService;

    public function __construct(
        CartRepository $cartRepository,
        ProductRepository $productRepository,
        CustomerRepository $customerRepository,
        WpsDropshipService $wpsService,
        PartsUnlimitedDropshipService $partsUnlimitedService,
        Turn14DropshipService $turn14Service,
        HelmetHouseDropshipService $helmetHouseService
    ) {
        $this->cartRepository = $cartRepository;
        $this->productRepository = $productRepository;
        $this->customerRepository = $customerRepository;
        $this->wpsService = $wpsService;
        $this->partsUnlimitedService = $partsUnlimitedService;
        $this->turn14Service = $turn14Service;
        $this->helmetHouseService = $helmetHouseService;
    }

    public function index(Request $request)
    {
        // Disable response caching for cart page (user-specific content)
        $request->attributes->set('responsecache.doNotCache', true);
        
        if ($request->has('arisku')) {
            $this->handleAriCartItem($request);

            $ariItems = session('ari_cart_items', []);

            if (!empty($ariItems)) {
                return redirect()->route('shop.checkout.cart.index')
                    ->with('success', 'ARI item added to cart successfully!');
            }
        }

        $cartItems = session('cart_items', []);
        $ariItems = session('ari_cart_items', []);

        if (!empty($cartItems)) {
            // DISABLED: Dropshipper check for Kawasaki products
            // $cartItems = $this->checkDropshippersForCartItems($cartItems);
            $cartItems = $this->updateCartItemPricesFromCache($cartItems);
            session(['cart_items' => $cartItems]);
        }

        if (!empty($ariItems)) {
            // Apply discount to existing ARI items that don't have it
            $ariItems = $this->applyDiscountToExistingAriItems($ariItems);
            // DISABLED: Dropshipper check for Kawasaki products
            // $ariItems = $this->checkDropshippersForAriItems($ariItems);
            $ariItems = $this->updateAriItemPricesFromCache($ariItems);
            session(['ari_cart_items' => $ariItems]);
        }

        $cartDebugData = [];
        if (env('DROPSHIPPER_DEBUG_PRODUCT_DETAIL', false)) {
            foreach ($cartItems as $item) {
                $cartDebugData[$item['sku']] = [
                    'name' => $item['name'],
                    'suppliers' => $item['suppliers'] ?? []
                ];
            }
            foreach ($ariItems as $item) {
                $cartDebugData[$item['sku']] = [
                    'name' => $item['name'],
                    'suppliers' => $item['suppliers'] ?? []
                ];
            }
        }

        return view('checkout.cart.index', compact('cartDebugData'));
    }

    private function handleAriCartItem(Request $request)
    {
        $price = (float) $request->get('ariprice', 0);
        $originalPrice = (float) $request->get('arioriginalprice', 0);
        
        // Get discount settings to check if discount is already applied
        $discountSettings = \App\Models\OemDiscountSetting::current();
        
        // SECURITY: Validate that the price matches expected discount calculation
        if ($discountSettings->enabled && $originalPrice > 0) {
            $expectedDiscountedPrice = $originalPrice * (1 - ($discountSettings->percentage / 100));
            
            // Allow small floating point differences (within 1 cent)
            if (abs($price - $expectedDiscountedPrice) > 0.01) {
                \Log::warning('Price manipulation attempt detected', [
                    'sku' => $request->get('arisku'),
                    'submitted_price' => $price,
                    'original_price' => $originalPrice,
                    'expected_price' => $expectedDiscountedPrice,
                    'ip' => $request->ip()
                ]);
                
                // Use the server-calculated price instead of trusting client
                $price = $expectedDiscountedPrice;
            }
        }
        
        $ariItem = [
            'sku' => $request->get('arisku'),
            'quantity' => (int) $request->get('ariqty', 1),
            'price' => $price,
            'brand' => $request->get('aribrand', ''),
            'brand_code' => $request->get('aribrandcode', ''),
            'description' => $request->get('aridescription', ''),
            'return_url' => urldecode($request->get('arireturnurl', '')),
            'name' => $this->generateAriProductName($request),
            'type' => 'ari_product',
            'image_url' => asset('themes/maddparts/images/placeholder.jpg'),
            // Mark that discount is already applied by JavaScript
            'discount_already_applied' => $discountSettings->enabled,
            'original_price' => $originalPrice > 0 ? $originalPrice : $price,
            'discount_percentage' => $discountSettings->enabled ? $discountSettings->percentage : 0
        ];

        $this->addAriItemToSession($ariItem);

        // Sync to database cart for abandoned cart tracking
        $this->syncAriToAbandonedCart($ariItem);
    }

    private function generateAriProductName($request)
    {
        $brand = $request->get('aribrand', 'Unknown Brand');
        $sku = $request->get('arisku', 'Unknown SKU');
        return $brand . ' - ' . $sku;
    }

    private function addAriItemToSession($ariItem)
    {
        $ariItems = session()->get('ari_cart_items', []);
        $itemKey = $ariItem['sku'];

        if (isset($ariItems[$itemKey])) {
            $ariItems[$itemKey]['quantity'] += $ariItem['quantity'];
        } else {
            $ariItems[$itemKey] = $ariItem;
        }

        session()->put('ari_cart_items', $ariItems);
        session()->save();
    }

    public function add(Request $request)
    {
        try {
            $request->validate([
                'product_id' => 'required|integer|exists:products,id',
                'quantity' => 'required|integer|min:1'
            ]);

            $product = $this->productRepository->find($request->product_id);

            if (!$product || $product->status != 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Product not found or unavailable'
                ], 404);
            }

            $actualProduct = $product;
            $parentProduct = null;

            if ($product->parent_id) {
                $parentProduct = $this->productRepository->find($product->parent_id);
            }
            elseif ($product->type === 'configurable' && $request->has('wps_variant_id')) {
                $variantProduct = $this->productRepository->find($request->wps_variant_id);
                if ($variantProduct && $variantProduct->parent_id === $product->id) {
                    $actualProduct = $variantProduct;
                    $parentProduct = $product;
                }
            }

            $inventory = \DB::table('product_inventories')
                ->where('product_id', $actualProduct->id)
                ->first();

            $totalStock = $actualProduct->inventories->sum('qty');

            if ($inventory && $inventory->virtual_inventory && $inventory->virtual_qty_base > 0) {
                $totalStock = $inventory->virtual_qty_base;
            }

            $existingCartQuantity = 0;
            $cartItems = session()->get('cart_items', []);
            foreach ($cartItems as $item) {
                if ($item['product_id'] == $actualProduct->id) {
                    $existingCartQuantity += $item['quantity'];
                }
            }

            $totalRequestedQuantity = $existingCartQuantity + $request->quantity;

            if ($totalStock < $totalRequestedQuantity) {
                $availableToAdd = max(0, $totalStock - $existingCartQuantity);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient stock. Only ' . $totalStock . ' items available total (' . $existingCartQuantity . ' already in cart, ' . $availableToAdd . ' more can be added).'
                ], 400);
            }

            $cartItems = session()->get('cart_items', []);
            $productKey = $actualProduct->id;

            if ($product->type === 'configurable' && $request->has('super_attribute') && !$request->has('wps_variant_id')) {
                $productKey = $request->product_id . '_' . implode('_', $request->super_attribute);
            }

            $price = $actualProduct->special_price && $actualProduct->special_price < $actualProduct->price
                ? $actualProduct->special_price
                : $actualProduct->price;

            $productImage = $actualProduct->images->first();
            $imageUrl = $productImage
                ? $productImage->url
                : asset('themes/maddparts/images/placeholder.jpg');

            $cartItem = [
                'product_id' => $actualProduct->id,
                'parent_product_id' => $parentProduct ? $parentProduct->id : null,
                'sku' => $actualProduct->sku,
                'name' => $actualProduct->name,
                'quantity' => $request->quantity,
                'price' => $price,
                'image_url' => $imageUrl,
                'type' => 'regular',
                'suppliers' => [
                    'ari_stock' => [
                        'available' => true,
                        'price' => $price,
                        'source' => 'ari_datastream'
                    ]
                ],
                'selected_supplier' => 'ari_stock',
                'product_data' => [
                    'id' => $actualProduct->id,
                    'name' => $actualProduct->name,
                    'sku' => $actualProduct->sku,
                    'price' => $actualProduct->price,
                    'special_price' => $actualProduct->special_price,
                    'url_key' => $parentProduct ? $parentProduct->url_key : $actualProduct->url_key,
                    'type' => $actualProduct->type,
                    'status' => $actualProduct->status,
                    'image_url' => $imageUrl,
                    'parent_name' => $parentProduct ? $parentProduct->name : null,
                    'is_variant' => $parentProduct ? true : false
                ],
                'super_attribute' => $request->super_attribute ?? null,
                'wps_variant_id' => $request->wps_variant_id ?? null
            ];

            // Check WPS availability with cache
            $wpsAvailability = $this->getCachedOrCheck($actualProduct->sku, 'wps');

            if ($wpsAvailability && $wpsAvailability['available']) {
                $cartItem['suppliers']['wps'] = [
                    'available' => true,
                    'price' => $wpsAvailability['price'],
                    'inventory' => $wpsAvailability['inventory'] ?? 0,
                    'source' => $wpsAvailability['source'] ?? 'wps_cached',
                    'wps_item_id' => $wpsAvailability['wps_item_id'] ?? null
                ];
            }

            // Check Parts Unlimited availability with cache
            $partsUnlimitedAvailability = $this->getCachedOrCheck($actualProduct->sku, 'parts_unlimited');

            if ($partsUnlimitedAvailability && $partsUnlimitedAvailability['available']) {
                $cartItem['suppliers']['parts_unlimited'] = [
                    'available' => true,
                    'price' => $partsUnlimitedAvailability['price'],
                    'base_price' => $partsUnlimitedAvailability['base_price'] ?? $partsUnlimitedAvailability['price'],
                    'dropship_fee' => $partsUnlimitedAvailability['dropship_fee'] ?? 0,
                    'inventory' => $partsUnlimitedAvailability['inventory'] ?? 0,
                    'source' => $partsUnlimitedAvailability['source'] ?? 'parts_unlimited_cached',
                    'parts_unlimited_sku' => $partsUnlimitedAvailability['parts_unlimited_sku'] ?? null,
                    'fee_note' => 'Includes $' . number_format($partsUnlimitedAvailability['dropship_fee'] ?? 0, 2) . ' dropship fee'
                ];
            }

            // Check Turn14 availability with cache
            $turn14Availability = $this->getCachedOrCheck($actualProduct->sku, 'turn14');

            if ($turn14Availability && $turn14Availability['available']) {
                $cartItem['suppliers']['turn14'] = [
                    'available' => true,
                    'price' => $turn14Availability['price'] ?? 0,
                    'inventory' => $turn14Availability['inventory'] ?? 0,
                    'source' => $turn14Availability['source'] ?? 'turn14_cached',
                    'turn14_item_id' => $turn14Availability['turn14_item_id'] ?? null
                ];
            }
            
            // Priority: Use lowest dropshipper price if available, otherwise use ARI database price
            $dropshipperSupplier = $this->selectDropshipperSupplier($cartItem['suppliers']);
            if ($dropshipperSupplier) {
                // Dropshipper found - use lowest dropshipper price
                $cartItem['selected_supplier'] = $dropshipperSupplier['supplier'];
                $cartItem['price'] = $dropshipperSupplier['price'];
            }

            if (isset($cartItems[$productKey])) {
                $cartItems[$productKey]['quantity'] += $request->quantity;
            } else {
                $cartItems[$productKey] = $cartItem;
            }

            session()->put('cart_items', $cartItems);

            // Sync to database cart for abandoned cart tracking
            $this->syncToAbandonedCart($actualProduct, $request->quantity, $price);

            $totalItems = array_sum(array_column($cartItems, 'quantity'));
            $totalPrice = array_sum(array_map(function ($item) {
                $selectedSupplier = $item['selected_supplier'] ?? 'ari_stock';
                $supplierPrice = $item['suppliers'][$selectedSupplier]['price'] ?? $item['price'];
                return $supplierPrice * $item['quantity'];
            }, $cartItems));

            return response()->json([
                'status' => 'success',
                'message' => 'Product added to cart successfully!',
                'data' => [
                    'items_count' => $totalItems,
                    'cart_count' => $totalItems,
                    'grand_total' => $totalPrice,
                    'formatted_grand_total' => core()->formatPrice($totalPrice),
                    'cart_total' => core()->formatPrice($totalPrice),
                    'suppliers_available' => count($cartItem['suppliers'])
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'An error occurred while adding the product to cart. Please try again.'
            ], 500);
        }
    }

    private function addToSessionCart($cartItem)
    {
        $cartItems = session()->get('cart_items', []);
        $itemKey = $cartItem['sku'];

        if (isset($cartItems[$itemKey])) {
            $cartItems[$itemKey]['quantity'] += $cartItem['quantity'];
        } else {
            $cartItems[$itemKey] = $cartItem;
        }

        session()->put('cart_items', $cartItems);
        session()->save();
    }

    private function getProductName($product)
    {
        $nameAttribute = $product->attribute_values->where('attribute_id', 2)->first();
        return $nameAttribute ? $nameAttribute->text_value : 'Unknown Product';
    }

    private function getProductPrice($product)
    {
        $priceAttribute = $product->attribute_values->where('attribute_id', 11)->first();
        return $priceAttribute ? (float) $priceAttribute->float_value : 0;
    }

    private function getProductImage($product)
    {
        $image = $product->images->first();
        return $image ? $image->url : asset('themes/maddparts/images/placeholder.jpg');
    }

    /**
     * Add ARI item to cart via AJAX (no page redirect)
     * This method is called when PartStream uses AJAX integration
     */
    public function addAriViaAjax(Request $request)
    {
        try {
            // Validate incoming request
            $request->validate([
                'arisku' => 'required|string',
                'ariqty' => 'nullable|integer|min:1',
                'ariprice' => 'nullable|numeric|min:0',
            ]);

            // Get request data
            $data = $request->all();

            // Create ARI item with discount
            $originalPrice = (float) ($data['ariprice'] ?? 0);
            $discountedPrice = $this->applyOemDiscount($originalPrice);

            $ariItem = [
                'sku' => $data['arisku'],
                'quantity' => (int) ($data['ariqty'] ?? 1),
                'price' => $discountedPrice,
                'original_price' => $originalPrice,
                'discount_percentage' => \App\Models\OemDiscountSetting::current()->percentage,
                'brand' => $data['aribrand'] ?? '',
                'brand_code' => $data['aribrandcode'] ?? '',
                'description' => $data['aridescription'] ?? '',
                'return_url' => $data['arireturnurl'] ?? '',
                'name' => $this->generateAriProductNameFromData($data),
                'type' => 'ari_product',
                'image_url' => asset('themes/maddparts/images/placeholder.jpg'),
                'supplier' => 'ARI Stock',
                'suppliers' => [
                    'ARI Stock' => [
                        'available' => true,
                        'price' => $discountedPrice,
                        'original_price' => $originalPrice,
                        'inventory' => 1
                    ]
                ]
            ];

            // Add to session
            $this->addAriItemToSession($ariItem);

            // Sync to database cart for abandoned cart tracking
            $this->syncAriToAbandonedCart($ariItem);

            // Get updated cart totals
            $cartItems = session('cart_items', []);
            $ariItems = session('ari_cart_items', []);

            $totalCartItems = array_sum(array_column($cartItems, 'quantity'));
            $totalAriItems = array_sum(array_column($ariItems, 'quantity'));
            $totalItems = $totalCartItems + $totalAriItems;

            $cartTotal = array_sum(array_map(function ($item) {
                return $item['price'] * $item['quantity'];
            }, $cartItems));

            $ariTotal = array_sum(array_map(function ($item) {
                return $item['price'] * $item['quantity'];
            }, $ariItems));

            $grandTotal = $cartTotal + $ariTotal;

            // Return JSON response
            return response()->json([
                'success' => true,
                'message' => 'OEM part added to cart successfully!',
                'cart_count' => $totalItems,
                'ari_count' => $totalAriItems,
                'cart_total' => $cartTotal,
                'ari_total' => $ariTotal,
                'grand_total' => $grandTotal,
                'formatted_grand_total' => core()->formatPrice($grandTotal),
                'item' => $ariItem
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid product data',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('ARI AJAX Cart Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to add item to cart. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate ARI product name from request data
     */
    private function generateAriProductNameFromData($data)
    {
        $brand = $data['aribrand'] ?? 'Unknown Brand';
        $sku = $data['arisku'] ?? 'Unknown SKU';
        $description = $data['aridescription'] ?? '';

        if (!empty($description)) {
            return $description;
        }

        return $brand . ' - ' . $sku;
    }

    /**
     * Apply OEM discount to price
     * Applies configurable discount percentage to OEM parts
     * 
     * @param float $price Original price from PartStream
     * @return float Discounted price
     */
    private function applyOemDiscount($price)
    {
        // Get settings from database
        $settings = \App\Models\OemDiscountSetting::current();
        
        // Check if discount is enabled
        if (!$settings->enabled) {
            return $price;
        }
        
        // Calculate and return discounted price
        return $price * (1 - ($settings->percentage / 100));
    }

    /**
     * Apply discount to existing ARI items in cart
     * This handles items that were added before discount code was deployed
     * 
     * @param array $ariItems Existing ARI cart items
     * @return array Updated ARI items with discount applied
     */
    private function applyDiscountToExistingAriItems($ariItems)
    {
        foreach ($ariItems as $sku => &$item) {
            // Skip if discount is already applied (from JavaScript on OEM parts page)
            if (isset($item['discount_already_applied']) && $item['discount_already_applied']) {
                continue;
            }
            
            // Check if item doesn't have discount applied yet
            if (!isset($item['original_price']) || !isset($item['discount_percentage'])) {
                // Store current price as original price
                $originalPrice = $item['price'];
                
                // Apply discount
                $discountedPrice = $this->applyOemDiscount($originalPrice);
                
                // Update item with discount info
                $item['original_price'] = $originalPrice;
                $item['price'] = $discountedPrice;
                $item['discount_percentage'] = \App\Models\OemDiscountSetting::current()->percentage;
                
                // Also update supplier prices if they exist
                if (isset($item['suppliers']['ARI Stock'])) {
                    $item['suppliers']['ARI Stock']['original_price'] = $originalPrice;
                    $item['suppliers']['ARI Stock']['price'] = $discountedPrice;
                }
            }
        }
        
        return $ariItems;
    }

    public function removeAriItem(Request $request)
    {
        try {
            $sku = $request->input('sku');
            $ariItems = session()->get('ari_cart_items', []);

            if (isset($ariItems[$sku])) {
                unset($ariItems[$sku]);
                session()->put('ari_cart_items', $ariItems);
                session()->save(); // Force session to persist immediately

                // Update abandoned cart timestamp or deactivate if empty
                if (empty($ariItems) && empty(session('cart_items', []))) {
                    $this->handleEmptyAbandonedCart();
                } else {
                    $this->updateAbandonedCartTimestamp();
                }

                if ($request->ajax()) {
                    return response()->json([
                        'status' => 'success',
                        'message' => 'ARI product removed from cart!'
                    ]);
                }

                return redirect()->route('shop.checkout.cart.index')->with('success', 'ARI product removed from cart!');
            }

            return response()->json([
                'status' => 'error',
                'message' => 'ARI product not found in cart'
            ], 404);

        } catch (\Exception $e) {
            if ($request->ajax()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Error removing ARI product: ' . $e->getMessage()
                ], 500);
            }

            return redirect()->route('shop.checkout.cart.index')->with('error', 'Error removing ARI product');
        }
    }
    
    private function selectBestSupplier($suppliers)
    {
        $availableSuppliers = [];
        
        foreach ($suppliers as $supplierName => $supplier) {
            if ($supplier['available']) {
                $availableSuppliers[] = [
                    'supplier' => $supplierName,
                    'price' => $supplier['price'],
                    'inventory' => $supplier['inventory'] ?? 1
                ];
            }
        }
        
        if (empty($availableSuppliers)) {
            return null;
        }
        
        // Sort by price (lowest first)
        usort($availableSuppliers, function($a, $b) {
            return $a['price'] <=> $b['price'];
        });
        
        return $availableSuppliers[0];
    }

    private function selectDropshipperSupplier($suppliers)
    {
        $dropshippers = [];
        
        // Collect only dropshipper suppliers (not ARI stock)
        foreach ($suppliers as $supplierName => $supplier) {
            if ($supplierName !== 'ari_stock' && ($supplier['available'] ?? false)) {
                $dropshippers[] = [
                    'supplier' => $supplierName,
                    'price' => $supplier['price'],
                    'inventory' => $supplier['inventory'] ?? 1
                ];
            }
        }
        
        if (empty($dropshippers)) {
            return null;
        }
        
        // Sort by price (lowest first) among dropshippers
        usort($dropshippers, function($a, $b) {
            return $a['price'] <=> $b['price'];
        });
        
        return $dropshippers[0];
    }

    public function updateAriItem(Request $request)
    {
        try {
            $sku = $request->input('sku');
            $quantity = (int) $request->input('quantity', 1);
            $ariItems = session()->get('ari_cart_items', []);

            if (isset($ariItems[$sku])) {
                if ($quantity > 0) {
                    $ariItems[$sku]['quantity'] = $quantity;
                } else {
                    unset($ariItems[$sku]);
                }

                session()->put('ari_cart_items', $ariItems);

                // Update abandoned cart timestamp
                $this->updateAbandonedCartTimestamp();

                if ($request->ajax()) {
                    $cartItems = session('cart_items', []);
                    $regularSubtotal = array_sum(array_map(function ($item) {
                        return $item['price'] * $item['quantity'];
                    }, $cartItems));

                    $ariSubtotal = array_sum(array_map(function ($item) {
                        return $item['price'] * $item['quantity'];
                    }, $ariItems));

                    $subtotal = $regularSubtotal + $ariSubtotal;
                    $totalItems = array_sum(array_column($cartItems, 'quantity')) + array_sum(array_column($ariItems, 'quantity'));

                    $taxRate = 0.08;
                    $couponDiscount = session('coupon_discount', 0);
                    $taxAmount = ($subtotal - $couponDiscount) * $taxRate;
                    $shippingCost = ($subtotal - $couponDiscount) >= 75 ? 0 : 9.99;
                    $grandTotal = $subtotal - $couponDiscount + $taxAmount + $shippingCost;

                    return response()->json([
                        'success' => true,
                        'message' => 'ARI product updated successfully!',
                        'data' => [
                            'items_count' => $totalItems,
                            'subtotal' => number_format($subtotal, 2),
                            'tax_amount' => number_format($taxAmount, 2),
                            'shipping_cost' => number_format($shippingCost, 2),
                            'grand_total' => number_format($grandTotal, 2),
                            'coupon_discount' => number_format($couponDiscount, 2),
                        ]
                    ]);
                }

                return redirect()->route('shop.checkout.cart.index')->with('success', 'ARI product updated successfully!');
            }

            return response()->json([
                'status' => 'error',
                'message' => 'ARI product not found in cart'
            ], 404);

        } catch (\Exception $e) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error updating ARI product: ' . $e->getMessage()
                ], 500);
            }

            return redirect()->route('shop.checkout.cart.index')->with('error', 'Error updating ARI product');
        }
    }

    public function clearAri(Request $request)
    {
        try {
            session()->forget('ari_cart_items');

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'ARI items cleared successfully!'
                ]);
            }

            return redirect()->route('shop.checkout.cart.index')->with('success', 'ARI items cleared successfully!');

        } catch (\Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error clearing ARI items'
                ], 500);
            }

            return redirect()->route('shop.checkout.cart.index')->with('error', 'Error clearing ARI items');
        }
    }

    public function update(Request $request)
    {
        try {
            $cartItems = session('cart_items', []);
            $quantities = $request->input('qty', []);

            foreach ($quantities as $productId => $quantity) {
                if (isset($cartItems[$productId]) && $quantity > 0) {
                    $cartItems[$productId]['quantity'] = (int) $quantity;
                }
            }

            session(['cart_items' => $cartItems]);

            // Update abandoned cart timestamp
            $this->updateAbandonedCartTimestamp();

            // Handle ARI items update
            $ariQuantities = $request->input('ari_qty', []);
            $ariItems = session('ari_cart_items', []);

            foreach ($ariQuantities as $sku => $quantity) {
                if (isset($ariItems[$sku]) && $quantity > 0) {
                    $ariItems[$sku]['quantity'] = (int) $quantity;
                }
            }

            session(['ari_cart_items' => $ariItems]);

            if ($request->ajax() || $request->wantsJson()) {
                $regularSubtotal = 0;
                $regularTotalItems = 0;

                foreach ($cartItems as $item) {
                    $itemTotal = $item['price'] * $item['quantity'];
                    $regularSubtotal += $itemTotal;
                    $regularTotalItems += $item['quantity'];
                }

                $ariSubtotal = 0;
                $ariTotalItems = 0;

                foreach ($ariItems as $item) {
                    $itemTotal = $item['price'] * $item['quantity'];
                    $ariSubtotal += $itemTotal;
                    $ariTotalItems += $item['quantity'];
                }

                $subtotal = $regularSubtotal + $ariSubtotal;
                $totalItems = $regularTotalItems + $ariTotalItems;

                $taxRate = 0.08;
                $couponDiscount = session('coupon_discount', 0);
                $taxAmount = ($subtotal - $couponDiscount) * $taxRate;
                $shippingCost = ($subtotal - $couponDiscount) >= 75 ? 0 : 9.99;
                $grandTotal = $subtotal - $couponDiscount + $taxAmount + $shippingCost;

                return response()->json([
                    'success' => true,
                    'message' => 'Cart updated successfully!',
                    'data' => [
                        'items_count' => $totalItems,
                        'subtotal' => number_format($subtotal, 2),
                        'tax_amount' => number_format($taxAmount, 2),
                        'shipping_cost' => number_format($shippingCost, 2),
                        'grand_total' => number_format($grandTotal, 2),
                        'coupon_discount' => number_format($couponDiscount, 2),
                    ]
                ]);
            }

            return redirect()->route('shop.checkout.cart.index')->with('success', 'Cart updated successfully!');

        } catch (\Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error updating cart: ' . $e->getMessage()
                ], 500);
            }

            return redirect()->route('shop.checkout.cart.index')->with('error', 'Error updating cart: ' . $e->getMessage());
        }
    }

    public function getCart()
    {
        try {
            $cartItems = session()->get('cart_items', []);
            $ariItems = session()->get('ari_cart_items', []);

            if (empty($cartItems) && empty($ariItems)) {
                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'items_count' => 0,
                        'grand_total' => 0,
                        'formatted_grand_total' => core()->formatPrice(0),
                        'items' => [],
                        'regular_items' => [],
                        'ari_items' => []
                    ]
                ]);
            }

            $regularTotalItems = array_sum(array_column($cartItems, 'quantity'));
            $regularTotalPrice = array_sum(array_map(function ($item) {
                return $item['price'] * $item['quantity'];
            }, $cartItems));

            $ariTotalItems = array_sum(array_column($ariItems, 'quantity'));
            $ariTotalPrice = array_sum(array_map(function ($item) {
                return $item['price'] * $item['quantity'];
            }, $ariItems));

            $totalItems = $regularTotalItems + $ariTotalItems;
            $totalPrice = $regularTotalPrice + $ariTotalPrice;

            return response()->json([
                'status' => 'success',
                'data' => [
                    'items_count' => $totalItems,
                    'grand_total' => $totalPrice,
                    'formatted_grand_total' => core()->formatPrice($totalPrice),
                    'regular_items' => $cartItems,
                    'ari_items' => $ariItems,
                    'regular_items_count' => $regularTotalItems,
                    'ari_items_count' => $ariTotalItems
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'success',
                'data' => [
                    'items_count' => 0,
                    'grand_total' => 0,
                    'formatted_grand_total' => core()->formatPrice(0),
                    'items' => [],
                    'regular_items' => [],
                    'ari_items' => []
                ]
            ]);
        }
    }

    public function updateSupplier(Request $request)
    {
        try {
            $request->validate([
                'sku' => 'required|string',
                'supplier' => 'required|string|in:ari_stock,wps'
            ]);

            $cartItems = session('cart_items', []);
            $sku = $request->sku;

            if (!isset($cartItems[$sku])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product not found in cart'
                ], 404);
            }

            $supplier = $request->supplier;
            if (!isset($cartItems[$sku]['suppliers'][$supplier])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Supplier not available for this product'
                ], 400);
            }

            $cartItems[$sku]['selected_supplier'] = $supplier;
            session(['cart_items' => $cartItems]);

            return response()->json([
                'success' => true,
                'message' => 'Supplier updated successfully',
                'selected_supplier' => $supplier,
                'new_price' => $cartItems[$sku]['suppliers'][$supplier]['price']
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating supplier: ' . $e->getMessage()
            ], 500);
        }
    }


    public function remove(Request $request)
    {
        try {
            $request->validate([
                'cart_item_id' => 'required'
            ]);

            $cartItems = session()->get('cart_items', []);

            if (isset($cartItems[$request->cart_item_id])) {
                unset($cartItems[$request->cart_item_id]);
                session()->put('cart_items', $cartItems);
                session()->save(); // Force session to persist immediately

                // Update abandoned cart timestamp or deactivate if empty
                if (empty($cartItems) && empty(session('ari_cart_items', []))) {
                    $this->handleEmptyAbandonedCart();
                } else {
                    $this->updateAbandonedCartTimestamp();
                }
            }

            $totalItems = array_sum(array_column($cartItems, 'quantity'));
            $totalPrice = array_sum(array_map(function ($item) {
                return $item['price'] * $item['quantity'];
            }, $cartItems));

            return response()->json([
                'status' => 'success',
                'message' => 'Product removed from cart successfully!',
                'data' => [
                    'items_count' => $totalItems,
                    'cart_count' => $totalItems,
                    'grand_total' => $totalPrice,
                    'formatted_grand_total' => core()->formatPrice($totalPrice),
                    'cart_total' => core()->formatPrice($totalPrice)
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error removing product from cart'
            ], 500);
        }
    }

    public function removeItem($productId)
    {
        try {
            $cartItems = session('cart_items', []);

            if (isset($cartItems[$productId])) {
                unset($cartItems[$productId]);
                session(['cart_items' => $cartItems]);

                return redirect()->route('shop.checkout.cart.index')->with('success', 'Item removed from cart!');
            }

            return redirect()->route('shop.checkout.cart.index')->with('warning', 'Item not found in cart.');

        } catch (\Exception $e) {
            return redirect()->route('shop.checkout.cart.index')->with('error', 'Error removing item: ' . $e->getMessage());
        }
    }

    public function clear(Request $request)
    {
        try {
            session()->forget('cart_items');
            session()->forget('coupon_discount');
            session()->forget('coupon_code');

            // Deactivate abandoned cart when cart is cleared
            $ariItems = session('ari_cart_items', []);
            if (empty($ariItems)) {
                $this->handleEmptyAbandonedCart();
            }

            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Cart cleared successfully!'
                ]);
            }

            return redirect()->route('shop.checkout.cart.index')->with('success', 'Cart cleared successfully!');

        } catch (\Exception $e) {
            if ($request->ajax() || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error clearing cart'
                ], 500);
            }

            return redirect()->route('shop.checkout.cart.index')->with('error', 'Error clearing cart');
        }
    }

    private function checkDropshippersForCartItems($cartItems)
    {
        foreach ($cartItems as $productKey => &$item) {
            $sku = $item['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            \Log::info("Dropshipper cache check for cart item: {$sku}");

            // Initialize suppliers if not exists
            if (!isset($item['suppliers'])) {
                $item['suppliers'] = [
                    'ari_stock' => [
                        'available' => true,
                        'price' => $item['price'],
                        'source' => 'ari_datastream'
                    ]
                ];
                $item['selected_supplier'] = 'ari_stock';
            }

            // Check WPS availability with cache
            $wpsAvailability = $this->getCachedOrCheck($sku, 'wps');
            if ($wpsAvailability && $wpsAvailability['available']) {
                $item['suppliers']['wps'] = [
                    'available' => true,
                    'price' => $wpsAvailability['price'],
                    'inventory' => $wpsAvailability['inventory'] ?? 0,
                    'source' => $wpsAvailability['source'] ?? 'wps_cached',
                    'wps_item_id' => $wpsAvailability['wps_item_id'] ?? null
                ];
                \Log::info("WPS available for {$sku}: {$wpsAvailability['source']}");
            }

            // Check Parts Unlimited availability with cache
            $partsUnlimitedAvailability = $this->getCachedOrCheck($sku, 'parts_unlimited');
            if ($partsUnlimitedAvailability && $partsUnlimitedAvailability['available']) {
                $item['suppliers']['parts_unlimited'] = [
                    'available' => true,
                    'price' => $partsUnlimitedAvailability['price'],
                    'base_price' => $partsUnlimitedAvailability['base_price'] ?? $partsUnlimitedAvailability['price'],
                    'dropship_fee' => $partsUnlimitedAvailability['dropship_fee'] ?? 0,
                    'inventory' => $partsUnlimitedAvailability['inventory'] ?? 0,
                    'source' => $partsUnlimitedAvailability['source'] ?? 'parts_unlimited_cached',
                    'parts_unlimited_sku' => $partsUnlimitedAvailability['parts_unlimited_sku'] ?? null,
                    'fee_note' => 'Includes $' . number_format($partsUnlimitedAvailability['dropship_fee'] ?? 0, 2) . ' dropship fee'
                ];
                \Log::info("Parts Unlimited available for {$sku}: {$partsUnlimitedAvailability['source']}");
            }

            // Check Turn14 availability with cache
            $turn14Availability = $this->getCachedOrCheck($sku, 'turn14');
            if ($turn14Availability && $turn14Availability['available']) {
                $item['suppliers']['turn14'] = [
                    'available' => true,
                    'price' => $turn14Availability['price'] ?? 0,
                    'inventory' => $turn14Availability['inventory'] ?? 0,
                    'source' => $turn14Availability['source'] ?? 'turn14_cached',
                    'turn14_item_id' => $turn14Availability['turn14_item_id'] ?? null
                ];
                \Log::info("Turn14 available for {$sku}: {$turn14Availability['source']}");
            }

            // Check Helmet House availability with cache
            $helmetHouseAvailability = $this->getCachedOrCheck($sku, 'helmet_house');
            if ($helmetHouseAvailability && $helmetHouseAvailability['available']) {
                $item['suppliers']['helmet_house'] = [
                    'available' => true,
                    'price' => $helmetHouseAvailability['price'] ?? 0,
                    'inventory' => $helmetHouseAvailability['inventory'] ?? 0,
                    'source' => $helmetHouseAvailability['source'] ?? 'helmet_house_cached',
                    'helmet_house_sku' => $helmetHouseAvailability['helmet_house_sku'] ?? null,
                    'map_price' => $helmetHouseAvailability['map_price'] ?? null,
                    'retail_price' => $helmetHouseAvailability['retail_price'] ?? null
                ];
                \Log::info("Helmet House available for {$sku}: {$helmetHouseAvailability['source']}");
            }

            // Priority: Use lowest dropshipper price if available, otherwise use ARI database price
            $dropshipperSupplier = $this->selectDropshipperSupplier($item['suppliers']);
            if ($dropshipperSupplier) {
                // Dropshipper found - use lowest dropshipper price
                $item['selected_supplier'] = $dropshipperSupplier['supplier'];
                $item['price'] = $dropshipperSupplier['price'];
                \Log::info("Dropshipper selected for {$sku}: {$dropshipperSupplier['supplier']} at {$dropshipperSupplier['price']} (lowest among dropshippers)");

                // Update Bagisto product price and inventory
                $this->updateProductPriceAndInventory($sku, $dropshipperSupplier['price'], $dropshipperSupplier['inventory'] ?? 0);
            } else {
                // No dropshippers available - fallback to ARI database price
                $item['selected_supplier'] = 'ari_stock';
                \Log::info("No dropshipper available for {$sku}, using ARI database price: {$item['price']}");
            }
        }

        return $cartItems;
    }

    private function updateProductPriceAndInventory($sku, $newPrice, $inventory)
    {
        try {
            $product = \DB::table('products')->where('sku', $sku)->first();
            if (!$product) {
                return;
            }

            $currentPrice = $product->price;

            // Only update if price changed
            if (abs($currentPrice - $newPrice) > 0.01) {
                // Update products table
                \DB::table('products')
                    ->where('id', $product->id)
                    ->update([
                        'price' => $newPrice,
                        'special_price' => $newPrice,
                        'updated_at' => now()
                    ]);

                // Update product_flat table
                \DB::table('product_flat')
                    ->where('product_id', $product->id)
                    ->update([
                        'price' => $newPrice,
                        'special_price' => $newPrice,
                        'updated_at' => now()
                    ]);

                \Log::info("Updated product price in database: {$sku} from \${$currentPrice} to \${$newPrice}");
            }

            // Update inventory if available
            if ($inventory > 0) {
                $existingInventory = \DB::table('product_inventories')
                    ->where('product_id', $product->id)
                    ->first();

                if ($existingInventory) {
                    \DB::table('product_inventories')
                        ->where('product_id', $product->id)
                        ->update([
                            'qty' => $inventory,
                            'updated_at' => now()
                        ]);
                } else {
                    \DB::table('product_inventories')->insert([
                        'qty' => $inventory,
                        'product_id' => $product->id,
                        'inventory_source_id' => 1,
                        'vendor_id' => 0,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                \Log::info("Updated product inventory in database: {$sku} to {$inventory} units");
            }
        } catch (\Exception $e) {
            \Log::error("Failed to update product price/inventory for {$sku}: " . $e->getMessage());
        }
    }

    private function checkDropshippersForAriItems($ariItems)
    {
        foreach ($ariItems as $ariSku => &$ariItem) {
            $sku = $ariItem['sku'] ?? null;
            if (!$sku) {
                continue;
            }

            \Log::info("Dropshipper cache check for ARI PartStream item: {$sku}");

            // Initialize suppliers with ARI PartStream as base
            $ariItem['suppliers'] = [
                'ari_partstream' => [
                    'available' => true,
                    'price' => $ariItem['price'] ?? 0,
                    'source' => 'ari_partstream_api'
                ]
            ];
            $ariItem['selected_supplier'] = 'ari_partstream';
            
            // Only set original_price if it doesn't exist (preserve existing value)
            if (!isset($ariItem['original_price'])) {
                $ariItem['original_price'] = $ariItem['price'] ?? 0;
            }

            // Check WPS availability with cache
            $wpsAvailability = $this->getCachedOrCheck($sku, 'wps');
            if ($wpsAvailability && $wpsAvailability['available']) {
                $ariItem['suppliers']['wps'] = [
                    'available' => true,
                    'price' => $wpsAvailability['price'],
                    'inventory' => $wpsAvailability['inventory'] ?? 0,
                    'source' => $wpsAvailability['source'] ?? 'wps_cached',
                    'wps_item_id' => $wpsAvailability['wps_item_id'] ?? null
                ];
                \Log::info("WPS available for ARI item {$sku}: {$wpsAvailability['source']}");
            }

            // Check Parts Unlimited availability with cache
            $partsUnlimitedAvailability = $this->getCachedOrCheck($sku, 'parts_unlimited');
            if ($partsUnlimitedAvailability && $partsUnlimitedAvailability['available']) {
                $ariItem['suppliers']['parts_unlimited'] = [
                    'available' => true,
                    'price' => $partsUnlimitedAvailability['price'],
                    'base_price' => $partsUnlimitedAvailability['base_price'] ?? $partsUnlimitedAvailability['price'],
                    'dropship_fee' => $partsUnlimitedAvailability['dropship_fee'] ?? 0,
                    'inventory' => $partsUnlimitedAvailability['inventory'] ?? 0,
                    'source' => $partsUnlimitedAvailability['source'] ?? 'parts_unlimited_cached',
                    'parts_unlimited_sku' => $partsUnlimitedAvailability['parts_unlimited_sku'] ?? null,
                    'fee_note' => 'Includes $' . number_format($partsUnlimitedAvailability['dropship_fee'] ?? 0, 2) . ' dropship fee'
                ];
                \Log::info("Parts Unlimited available for ARI item {$sku}: {$partsUnlimitedAvailability['source']}");
            }

            // Check Turn14 availability with cache
            $turn14Availability = $this->getCachedOrCheck($sku, 'turn14');
            if ($turn14Availability && $turn14Availability['available']) {
                $ariItem['suppliers']['turn14'] = [
                    'available' => true,
                    'price' => $turn14Availability['price'] ?? 0,
                    'inventory' => $turn14Availability['inventory'] ?? 0,
                    'source' => $turn14Availability['source'] ?? 'turn14_cached',
                    'turn14_item_id' => $turn14Availability['turn14_item_id'] ?? null
                ];
                \Log::info("Turn14 available for ARI item {$sku}: {$turn14Availability['source']}");
            }

            // Priority: Use lowest dropshipper price if available, otherwise keep ARI PartStream price
            $dropshipperSupplier = $this->selectDropshipperSupplierForAri($ariItem['suppliers']);
            if ($dropshipperSupplier) {
                // Dropshipper found - use lowest dropshipper price with discount
                $originalPrice = $dropshipperSupplier['price'];
                $discountedPrice = $this->applyOemDiscount($originalPrice);
                
                $ariItem['selected_supplier'] = $dropshipperSupplier['supplier'];
                $ariItem['original_price'] = $originalPrice;
                $ariItem['price'] = $discountedPrice;
                $ariItem['discount_percentage'] = \App\Models\OemDiscountSetting::current()->percentage;
                
                \Log::info("Dropshipper selected for ARI item {$sku}: {$dropshipperSupplier['supplier']} - Original: {$originalPrice}, Discounted: {$discountedPrice}");
            } else {
                // No dropshippers available - keep ARI PartStream price (already discounted in addAriViaAjax)
                $ariItem['selected_supplier'] = 'ari_partstream';
                \Log::info("No dropshipper available for ARI item {$sku}, using ARI PartStream price: {$ariItem['price']}");
            }
        }

        return $ariItems;
    }

    private function selectDropshipperSupplierForAri($suppliers)
    {
        $dropshippers = [];
        
        // Collect only dropshipper suppliers (not ARI PartStream)
        foreach ($suppliers as $supplierName => $supplier) {
            if ($supplierName !== 'ari_partstream' && ($supplier['available'] ?? false)) {
                $dropshippers[] = [
                    'supplier' => $supplierName,
                    'price' => $supplier['price'],
                    'inventory' => $supplier['inventory'] ?? 1
                ];
            }
        }
        
        if (empty($dropshippers)) {
            return null;
        }
        
        // Sort by price (lowest first) among dropshippers
        usort($dropshippers, function($a, $b) {
            return $a['price'] <=> $b['price'];
        });
        
        return $dropshippers[0];
    }

    private function updateCartItemPricesFromCache($cartItems)
    {
        foreach ($cartItems as $productKey => &$item) {
            $dropshipperSupplier = $this->selectDropshipperSupplier($item['suppliers'] ?? []);
            if ($dropshipperSupplier) {
                $item['selected_supplier'] = $dropshipperSupplier['supplier'];
                $item['price'] = $dropshipperSupplier['price'];
                \Log::info("Cart price updated from cache for {$item['sku']}: {$dropshipperSupplier['supplier']} at {$dropshipperSupplier['price']}");
            }
        }
        return $cartItems;
    }

    private function updateAriItemPricesFromCache($ariItems)
    {
        foreach ($ariItems as $ariSku => &$ariItem) {
            $dropshipperSupplier = $this->selectDropshipperSupplierForAri($ariItem['suppliers'] ?? []);
            if ($dropshipperSupplier) {
                // Get the original price from dropshipper
                $originalPrice = $dropshipperSupplier['price'];
                
                // Apply OEM discount
                $discountedPrice = $this->applyOemDiscount($originalPrice);
                
                // Update item with discount info
                $ariItem['selected_supplier'] = $dropshipperSupplier['supplier'];
                $ariItem['original_price'] = $originalPrice;
                $ariItem['price'] = $discountedPrice;
                $ariItem['discount_percentage'] = \App\Models\OemDiscountSetting::current()->percentage;
                
                \Log::info("ARI cart price updated from cache for {$ariItem['sku']}: {$dropshipperSupplier['supplier']} - Original: {$originalPrice}, Discounted: {$discountedPrice}");
            }
        }
        return $ariItems;
    }

    private function getCachedOrCheck($sku, $supplier)
    {
        $cached = \DB::table('supplier_cache')
            ->where('sku', $sku)
            ->where('supplier', $supplier)
            ->where('expires_at', '>', now())
            ->first();

        if ($cached) {
            return [
                'available' => $cached->is_available,
                'price' => $cached->price,
                'inventory' => $cached->inventory,
                'source' => $supplier . '_cached',
                'wps_item_id' => $cached->dropshipper_item_id,
                'parts_unlimited_sku' => $cached->dropshipper_item_id,
                'turn14_item_id' => $cached->dropshipper_item_id,
            ];
        }

        $service = match($supplier) {
            'wps' => $this->wpsService,
            'parts_unlimited' => $this->partsUnlimitedService,
            'turn14' => $this->turn14Service,
            'helmet_house' => $this->helmetHouseService,
            default => null
        };

        if (!$service) {
            return null;
        }

        try {
            $result = $service->checkAvailability($sku);

            if ($result) {
                \DB::table('supplier_cache')->updateOrInsert(
                    ['sku' => $sku, 'supplier' => $supplier],
                    [
                        'is_available' => $result['available'] ?? false,
                        'price' => $result['price'] ?? null,
                        'inventory' => $result['inventory'] ?? 0,
                        'dropshipper_item_id' => $result['wps_item_id'] ?? $result['parts_unlimited_sku'] ?? $result['turn14_item_id'] ?? null,
                        'cached_at' => now(),
                        'expires_at' => now()->addHours(24)
                    ]
                );
            }

            return $result;
        } catch (\Exception $e) {
            \Log::error("Dropshipper cache check failed for {$supplier}:{$sku}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function applyCoupon(Request $request)
    {
        try {
            \Log::info('Coupon application started', ['input' => $request->all()]);

            $request->validate([
                'coupon_code' => 'required|string'
            ]);

            $couponCode = trim($request->coupon_code);
            \Log::info('Looking up coupon', ['code' => $couponCode]);

            $coupon = \DB::table('cart_rule_coupons')->where('code', $couponCode)->first();
            \Log::info('Coupon lookup result', ['found' => !is_null($coupon), 'coupon' => $coupon]);

            if (!$coupon) {
                \Log::warning('Coupon not found', ['code' => $couponCode]);
                return redirect()->back()
                    ->with('error', 'Invalid coupon code.');
            }

            $cartRule = \DB::table('cart_rules')->where('id', $coupon->cart_rule_id)->first();
            \Log::info('Cart rule lookup', ['found' => !is_null($cartRule), 'rule' => $cartRule]);

            if (!$cartRule || $cartRule->status != 1) {
                \Log::warning('Cart rule inactive or not found', ['cart_rule' => $cartRule]);
                return redirect()->back()
                    ->with('error', 'This coupon is not active.');
            }

            if ($cartRule->starts_from && $cartRule->starts_from > now()) {
                return redirect()->back()
                    ->with('error', 'This coupon is not yet available.');
            }

            if ($cartRule->ends_till && $cartRule->ends_till < now()) {
                return redirect()->back()
                    ->with('error', 'This coupon has expired.');
            }

            if ($coupon->usage_limit && $coupon->times_used >= $coupon->usage_limit) {
                return redirect()->back()
                    ->with('error', 'This coupon has reached its usage limit.');
            }

            $customerId = auth()->guard('customer')->id();

            if ($coupon->usage_per_customer && $customerId) {
                $customerUsage = \DB::table('cart_rule_coupon_usage')
                    ->where('cart_rule_coupon_id', $coupon->id)
                    ->where('customer_id', $customerId)
                    ->first();

                if ($customerUsage && $customerUsage->times_used >= $coupon->usage_per_customer) {
                    return redirect()->back()
                        ->with('error', 'You have reached the usage limit for this coupon.');
                }
            }

            $customerGroupIds = \DB::table('cart_rule_customer_groups')
                ->where('cart_rule_id', $cartRule->id)
                ->pluck('customer_group_id')
                ->toArray();

            if (!empty($customerGroupIds)) {
                if (!$customerId) {
                    return redirect()->back()
                        ->with('error', 'Please login to use this coupon.');
                }

                $customer = \DB::table('customers')->where('id', $customerId)->first();
                if (!$customer || !in_array($customer->customer_group_id, $customerGroupIds)) {
                    return redirect()->back()
                        ->with('error', 'This coupon is not available for your customer group.');
                }
            }

            $cartItems = session('cart_items', []);
            $ariItems = session('ari_cart_items', []);
            $subtotal = 0;

            foreach ($cartItems as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }

            foreach ($ariItems as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }

            $conditions = json_decode($cartRule->conditions ?? '[]', true);
            if (!empty($conditions)) {
                $minAmount = null;
                foreach ($conditions as $condition) {
                    if (isset($condition['attribute']) && $condition['attribute'] === 'base_sub_total') {
                        $minAmount = $condition['value'] ?? null;
                        break;
                    }
                }

                if ($minAmount && $subtotal < $minAmount) {
                    return redirect()->back()
                        ->with('error', 'Minimum cart amount of $' . number_format($minAmount, 2) . ' required for this coupon.');
                }
            }

            $discount = 0;

            switch ($cartRule->action_type) {
                case 'by_percent':
                    $discount = $subtotal * ($cartRule->discount_amount / 100);
                    break;
                case 'by_fixed':
                case 'cart_fixed':
                    $discount = $cartRule->discount_amount;
                    break;
            }

            // Check if discount is greater than subtotal
            if ($discount > $subtotal) {
                return redirect()->back()
                    ->with('error', 'This coupon discount ($' . number_format($discount, 2) . ') exceeds your cart total ($' . number_format($subtotal, 2) . '). Please add more items to use this coupon.');
            }

            $discount = min($discount, $subtotal);

            session([
                'coupon_code' => $couponCode,
                'coupon_discount' => $discount,
                'coupon_type' => $cartRule->action_type,
                'coupon_amount' => $cartRule->discount_amount,
                'coupon_rule_id' => $cartRule->id
            ]);

            \Log::info('Coupon applied successfully', [
                'code' => $couponCode,
                'discount' => $discount,
                'subtotal' => $subtotal
            ]);

            return redirect()->back()
                ->with('success', 'Coupon applied successfully!');

        } catch (\Exception $e) {
            \Log::error('Coupon application error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()
                ->with('error', 'Error applying coupon: ' . $e->getMessage());
        }
    }

    public function removeCoupon(Request $request)
    {
        try {
            session()->forget(['coupon_code', 'coupon_discount', 'coupon_type', 'coupon_amount']);

            if ($request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Coupon removed successfully!'
                ]);
            }

            return redirect()->back()
                ->with('success', 'Coupon removed successfully!');

        } catch (\Exception $e) {
            if ($request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error removing coupon'
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'Error removing coupon');
        }
    }

    /**
     * Sync session cart to database cart for abandoned cart tracking
     */
    private function syncToAbandonedCart($product, $quantity, $price)
    {
        try {
            \Log::info('🔵 syncToAbandonedCart called', [
                'product_id' => $product->id ?? 'N/A',
                'sku' => $product->sku ?? 'N/A',
                'quantity' => $quantity,
                'price' => $price
            ]);

            // Only sync if customer is logged in
            if (!auth()->guard('customer')->check()) {
                \Log::info('🔴 Customer not logged in - skipping sync');
                return;
            }

            $customer = auth()->guard('customer')->user();
            \Log::info('🟢 Customer logged in', [
                'customer_id' => $customer->id,
                'email' => $customer->email
            ]);

            // Find or create cart for this customer
            $cart = $this->cartRepository->findOneWhere([
                'customer_id' => $customer->id,
                'is_active' => 1,
                'channel_id' => core()->getCurrentChannel()->id,
            ]);

            if (!$cart) {
                \Log::info('🟡 Creating new cart for customer');
                // Create new cart
                $cart = $this->cartRepository->create([
                    'customer_id' => $customer->id,
                    'customer_first_name' => $customer->first_name,
                    'customer_last_name' => $customer->last_name,
                    'customer_email' => $customer->email,
                    'is_guest' => 0,
                    'is_active' => 1,
                    'channel_id' => core()->getCurrentChannel()->id,
                    'global_currency_code' => core()->getBaseCurrencyCode(),
                    'base_currency_code' => core()->getBaseCurrencyCode(),
                    'channel_currency_code' => core()->getCurrentCurrencyCode(),
                    'cart_currency_code' => core()->getCurrentCurrencyCode(),
                    'items_count' => 0,
                    'items_qty' => 0,
                ]);
                \Log::info('✅ Cart created successfully', ['cart_id' => $cart->id]);
            } else {
                \Log::info('🟢 Found existing cart', ['cart_id' => $cart->id]);
            }

            // Check if item already exists in cart
            $cartItem = \DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->whereNull('parent_id')
                ->first();

            if ($cartItem) {
                // Update existing item
                \DB::table('cart_items')
                    ->where('id', $cartItem->id)
                    ->update([
                        'quantity' => $cartItem->quantity + $quantity,
                        'total' => ($cartItem->quantity + $quantity) * $price,
                        'base_total' => ($cartItem->quantity + $quantity) * $price,
                        'updated_at' => now(),
                    ]);
            } else {
                // Add new item
                \DB::table('cart_items')->insert([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => $quantity,
                    'price' => $price,
                    'base_price' => $price,
                    'total' => $quantity * $price,
                    'base_total' => $quantity * $price,
                    'weight' => $product->weight ?? 0,
                    'total_weight' => ($product->weight ?? 0) * $quantity,
                    'base_total_weight' => ($product->weight ?? 0) * $quantity,
                    'type' => $product->type,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Update cart totals
            $allCartItems = \DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->whereNull('parent_id')
                ->get();

            $itemsCount = $allCartItems->count();
            $itemsQty = $allCartItems->sum('quantity');
            $subTotal = $allCartItems->sum('base_total');

            \DB::table('cart')->where('id', $cart->id)->update([
                'items_count' => $itemsCount,
                'items_qty' => $itemsQty,
                'sub_total' => $subTotal,
                'base_sub_total' => $subTotal,
                'grand_total' => $subTotal,
                'base_grand_total' => $subTotal,
                'updated_at' => now(),
            ]);

            \Log::info('✅ Cart totals updated', [
                'cart_id' => $cart->id,
                'items_count' => $itemsCount,
                'grand_total' => $subTotal
            ]);

            // Fire the event for abandoned cart tracking
            if (core()->getConfigData('abandon_cart.settings.general.status')) {
                \Log::info('🔔 Firing abandoned cart event');
                $cart = $this->cartRepository->find($cart->id);
                event('checkout.cart.add.after', $cart);
                \Log::info('✅ Event fired successfully');
            } else {
                \Log::warning('⚠️ Abandoned cart extension is disabled - event not fired');
            }

        } catch (\Exception $e) {
            // Log error but don't break the cart add functionality
            \Log::error('❌ Abandoned cart sync failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Sync ARI session cart items to database cart for abandoned cart tracking
     */
    private function syncAriToAbandonedCart($ariItem)
    {
        try {
            \Log::info('🔵 syncAriToAbandonedCart called', [
                'sku' => $ariItem['sku'] ?? 'N/A',
                'quantity' => $ariItem['quantity'] ?? 'N/A',
                'price' => $ariItem['price'] ?? 'N/A'
            ]);

            // Only sync if customer is logged in
            if (!auth()->guard('customer')->check()) {
                \Log::info('🔴 Customer not logged in - skipping ARI sync');
                return;
            }

            $customer = auth()->guard('customer')->user();
            \Log::info('🟢 Customer logged in for ARI', [
                'customer_id' => $customer->id,
                'email' => $customer->email
            ]);

            // Find or create cart for this customer
            $cart = $this->cartRepository->findOneWhere([
                'customer_id' => $customer->id,
                'is_active' => 1,
                'channel_id' => core()->getCurrentChannel()->id,
            ]);

            if (!$cart) {
                \Log::info('🟡 Creating new cart for ARI item');
                // Create new cart
                $cart = $this->cartRepository->create([
                    'customer_id' => $customer->id,
                    'customer_first_name' => $customer->first_name,
                    'customer_last_name' => $customer->last_name,
                    'customer_email' => $customer->email,
                    'is_guest' => 0,
                    'is_active' => 1,
                    'channel_id' => core()->getCurrentChannel()->id,
                    'global_currency_code' => core()->getBaseCurrencyCode(),
                    'base_currency_code' => core()->getBaseCurrencyCode(),
                    'channel_currency_code' => core()->getCurrentCurrencyCode(),
                    'cart_currency_code' => core()->getCurrentCurrencyCode(),
                    'items_count' => 0,
                    'items_qty' => 0,
                ]);
                \Log::info('✅ ARI cart created successfully', ['cart_id' => $cart->id]);
            } else {
                \Log::info('🟢 Found existing cart for ARI', ['cart_id' => $cart->id]);
            }

            // For ARI items, we need to create a virtual product entry or use a special product_id
            // Check if this ARI SKU already exists as a cart item
            $cartItem = \DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->where('sku', $ariItem['sku'])
                ->whereNull('parent_id')
                ->first();

            $quantity = (int) $ariItem['quantity'];
            $price = (float) $ariItem['price'];

            if ($cartItem) {
                // Update existing ARI item
                \DB::table('cart_items')
                    ->where('id', $cartItem->id)
                    ->update([
                        'quantity' => $cartItem->quantity + $quantity,
                        'total' => ($cartItem->quantity + $quantity) * $price,
                        'base_total' => ($cartItem->quantity + $quantity) * $price,
                        'updated_at' => now(),
                    ]);
            } else {
                // Add new ARI item - use product_id = 0 to indicate ARI product
                \DB::table('cart_items')->insert([
                    'cart_id' => $cart->id,
                    'product_id' => 0, // Special marker for ARI products
                    'sku' => $ariItem['sku'],
                    'name' => $ariItem['name'],
                    'quantity' => $quantity,
                    'price' => $price,
                    'base_price' => $price,
                    'total' => $quantity * $price,
                    'base_total' => $quantity * $price,
                    'weight' => 0,
                    'total_weight' => 0,
                    'base_total_weight' => 0,
                    'type' => 'ari_product',
                    'additional' => json_encode([
                        'brand' => $ariItem['brand'] ?? '',
                        'brand_code' => $ariItem['brand_code'] ?? '',
                        'description' => $ariItem['description'] ?? '',
                        'is_ari_product' => true,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Update cart totals
            $allCartItems = \DB::table('cart_items')
                ->where('cart_id', $cart->id)
                ->whereNull('parent_id')
                ->get();

            $itemsCount = $allCartItems->count();
            $itemsQty = $allCartItems->sum('quantity');
            $subTotal = $allCartItems->sum('base_total');

            \DB::table('cart')->where('id', $cart->id)->update([
                'items_count' => $itemsCount,
                'items_qty' => $itemsQty,
                'sub_total' => $subTotal,
                'base_sub_total' => $subTotal,
                'grand_total' => $subTotal,
                'base_grand_total' => $subTotal,
                'updated_at' => now(),
            ]);

            \Log::info('✅ ARI cart totals updated', [
                'cart_id' => $cart->id,
                'items_count' => $itemsCount,
                'grand_total' => $subTotal
            ]);

            // Fire the event for abandoned cart tracking
            if (core()->getConfigData('abandon_cart.settings.general.status')) {
                \Log::info('🔔 Firing abandoned cart event for ARI');
                $cart = $this->cartRepository->find($cart->id);
                event('checkout.cart.add.after', $cart);
                \Log::info('✅ ARI event fired successfully');
            } else {
                \Log::warning('⚠️ Abandoned cart extension is disabled - ARI event not fired');
            }

        } catch (\Exception $e) {
            // Log error but don't break the cart add functionality
            \Log::error('❌ ARI abandoned cart sync failed: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    private function updateAbandonedCartTimestamp()
    {
        try {
            if (!auth()->guard('customer')->check()) {
                return;
            }

            $customer = auth()->guard('customer')->user();
            $cart = $this->cartRepository->findOneWhere([
                'customer_id' => $customer->id,
                'is_active' => 1,
                'channel_id' => core()->getCurrentChannel()->id,
            ]);

            if ($cart) {
                \DB::table('cart')->where('id', $cart->id)->update([
                    'updated_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to update abandoned cart timestamp: ' . $e->getMessage());
        }
    }

    private function handleEmptyAbandonedCart()
    {
        try {
            if (!auth()->guard('customer')->check()) {
                return;
            }

            $customer = auth()->guard('customer')->user();
            $cart = $this->cartRepository->findOneWhere([
                'customer_id' => $customer->id,
                'is_active' => 1,
                'channel_id' => core()->getCurrentChannel()->id,
            ]);

            if ($cart) {
                \DB::table('cart')->where('id', $cart->id)->update([
                    'is_active' => 0,
                    'updated_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Failed to deactivate abandoned cart: ' . $e->getMessage());
        }
    }
}