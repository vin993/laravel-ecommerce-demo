<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\WpsApiService;
use App\Services\StripePaymentService;
use App\Services\PaypalPaymentService;
use App\Services\ShipStationService;
use App\Services\Dropship\WpsDropshipService;
use App\Services\Dropship\PartsUnlimitedDropshipService;
use App\Services\Dropship\Turn14DropshipService;
use App\Services\Dropship\HelmetHouseDropshipService;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Sales\Repositories\OrderRepository;
use Webkul\Customer\Repositories\CustomerRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\CurrencyRepository;
use Webkul\Tax\Repositories\TaxCategoryRepository;
use Webkul\Tax\Facades\Tax;
use Illuminate\Support\Facades\Mail;
use App\Mail\CustomerOrderConfirmation;
use App\Mail\AdminOrderNotification;

class CheckoutController extends Controller
{
    protected $wpsService;
    protected $productRepository;
    protected $stripeService;
    protected $paypalService;
    protected $orderRepository;
    protected $customerRepository;
    protected $channelRepository;
    protected $currencyRepository;
    protected $shipStationService;
    protected $wpsDropshipService;
    protected $partsUnlimitedDropshipService;
    protected $turn14DropshipService;
    protected $helmetHouseDropshipService;
    protected $taxCategoryRepository;

    private function safeLog($level, $message, $context = [])
    {
        try {
            \Log::$level($message, $context);
        } catch (\Exception $e) {
            // Silently fail if logging has permission issues
        }
    }

    public function __construct(
        WpsApiService $wpsService,
        ProductRepository $productRepository,
        StripePaymentService $stripeService,
        PaypalPaymentService $paypalService,
        OrderRepository $orderRepository,
        CustomerRepository $customerRepository,
        ChannelRepository $channelRepository,
        CurrencyRepository $currencyRepository,
        ShipStationService $shipStationService,
        WpsDropshipService $wpsDropshipService,
        PartsUnlimitedDropshipService $partsUnlimitedDropshipService,
        Turn14DropshipService $turn14DropshipService,
        HelmetHouseDropshipService $helmetHouseDropshipService,
        TaxCategoryRepository $taxCategoryRepository
    ) {
        $this->wpsService = $wpsService;
        $this->productRepository = $productRepository;
        $this->stripeService = $stripeService;
        $this->paypalService = $paypalService;
        $this->orderRepository = $orderRepository;
        $this->customerRepository = $customerRepository;
        $this->channelRepository = $channelRepository;
        $this->currencyRepository = $currencyRepository;
        $this->shipStationService = $shipStationService;
        $this->wpsDropshipService = $wpsDropshipService;
        $this->partsUnlimitedDropshipService = $partsUnlimitedDropshipService;
        $this->turn14DropshipService = $turn14DropshipService;
        $this->helmetHouseDropshipService = $helmetHouseDropshipService;
        $this->taxCategoryRepository = $taxCategoryRepository;
    }
    
    /**
     * Show checkout index page (shipping form)
     */
    public function index()
    {
        $cartItems = session('cart_items', []);
        $ariItems = session('ari_cart_items', []);

        if (empty($cartItems) && empty($ariItems)) {
            return redirect()->route('shop.checkout.cart.index')
                ->with('warning', 'Your cart is empty. Add some products to continue.');
        }

        // Categorize items by fulfillment type
        $categorizedItems = $this->categorizeItemsByFulfillment($cartItems);

        // Calculate preliminary totals (without tax and shipping on initial load)
        $orderTotals = $this->calculateOrderTotalsWithoutTax($cartItems);

        $warehouses = $this->wpsService->getWarehouses();
        $shippingMethods = $this->wpsService->getShippingMethods();

        // Fetch saved addresses for logged in customers
        $savedAddresses = [];
        if (auth()->guard('customer')->check()) {
            $savedAddresses = app(\Webkul\Customer\Repositories\CustomerAddressRepository::class)
                ->findWhere(['customer_id' => auth()->guard('customer')->id()]);
        }

        return view('checkout.index', compact(
            'cartItems',
            'ariItems',
            'categorizedItems',
            'orderTotals',
            'warehouses',
            'shippingMethods',
            'savedAddresses'
        ));
    }
    
    /**
     * Process shipping information
     */
    public function processShipping(Request $request)
    {
        $request->validate([
            'ship_name' => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'ship_address1' => 'required|string|max:255',
            'ship_address2' => 'nullable|string|max:255',
            'ship_address3' => 'nullable|string|max:255',
            'ship_city' => 'required|string|max:255',
            'ship_state' => 'required|string|max:255',
            'ship_zip' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'ship_phone' => 'nullable|string|max:20',
            'order_comments' => 'nullable|string|max:200'
        ]);

        // Map form fields to shipping data structure
        $shippingData = [
            'ship_name' => $request->ship_name,
            'company' => $request->company,
            'ship_address1' => $request->ship_address1,
            'ship_address2' => $request->ship_address2,
            'ship_address3' => $request->ship_address3,
            'ship_city' => $request->ship_city,
            'ship_state' => $request->ship_state,
            'ship_zip' => $request->ship_zip,
            'email' => $request->email,
            'ship_phone' => $request->ship_phone,
            'ship_via' => 'Standard Shipping', // Default, will be updated with selected method
            'order_comments' => $request->order_comments,
        ];
        
        // Store shipping data and warehouse selections in session
        session([
            'checkout_shipping' => $shippingData,
            'warehouse_selections' => $request->input('warehouse', [])
        ]);
        
        return redirect()->route('checkout.shipping_method');
    }
    
    /**
     * Show shipping method selection page (new step)
     */
    public function shippingMethod()
    {
        if (!session('checkout_shipping')) {
            return redirect()->route('checkout.index')
                ->with('error', 'Please fill shipping information first.');
        }

        $cartItems = session('cart_items', []);
        $ariItems = session('ari_cart_items', []);
        $shippingData = session('checkout_shipping');

        // Categorize items by fulfillment type
        $categorizedItems = $this->categorizeItemsByFulfillment($cartItems);

        // If no in-house items, skip to payment
        if (!$categorizedItems['needs_shipping_selection']) {
            return redirect()->route('checkout.payment');
        }

        // Calculate shipping rates for in-house items
        $shippingRates = $this->calculateInHouseShippingRates(
            $categorizedItems['in_house'],
            $shippingData
        );

        // Calculate order totals without shipping first
        $orderTotals = $this->calculateOrderTotals($cartItems);

        return view('checkout.shipping_method', compact(
            'cartItems',
            'ariItems',
            'categorizedItems',
            'shippingData',
            'shippingRates',
            'orderTotals'
        ));
    }
    
    /**
     * Process shipping method selection
     */
    public function processShippingMethod(Request $request)
    {
        if (!session('checkout_shipping')) {
            return redirect()->route('checkout.index')
                ->with('error', 'Please fill shipping information first.');
        }
        
        $cartItems = session('cart_items', []);
        $categorizedItems = $this->categorizeItemsByFulfillment($cartItems);
        
        // Validate shipping method selection if in-house items exist
        if ($categorizedItems['needs_shipping_selection']) {
            $request->validate([
                'shipping_method' => 'required|string',
                'shipping_rate' => 'required|numeric|min:0'
            ]);
            
            // Store selected shipping method in session
            session([
                'selected_shipping_method' => [
                    'code' => $request->shipping_method,
                    'rate' => (float) $request->shipping_rate,
                    'name' => $request->input('shipping_name', 'Standard Shipping')
                ]
            ]);
        }
        
        return redirect()->route('checkout.payment');
    }
    
    /**
     * Calculate tax in real-time via AJAX for state changes
     */
    public function calculateTaxAjax(Request $request)
    {
        $cartItems = session('cart_items', []);
        $ariItems = session('ari_cart_items', []);

        if (empty($cartItems) && empty($ariItems)) {
            return response()->json([
                'success' => false,
                'error' => 'No items in cart'
            ]);
        }

        // Calculate subtotal from regular cart items
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $selectedSupplier = $item['selected_supplier'] ?? 'ari_stock';
            $supplierPrice = $item['suppliers'][$selectedSupplier]['price'] ?? $item['price'];
            $subtotal += $supplierPrice * $item['quantity'];
        }

        // Add ARI items to subtotal
        foreach ($ariItems as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }

        // Create temporary shipping address for tax calculation
        $tempShippingAddress = [
            'ship_state' => $request->input('state'),
            'ship_zip' => $request->input('zip', ''),
        ];

        // Calculate tax dynamically
        $taxAmount = $this->calculateTax($subtotal, $tempShippingAddress);
        $newTotal = $subtotal + $taxAmount;

        return response()->json([
            'success' => true,
            'data' => [
                'subtotal' => number_format($subtotal, 2),
                'tax_amount' => number_format($taxAmount, 2),
                'total' => number_format($newTotal, 2),
                'tax_rate_applied' => $taxAmount > 0 ? round(($taxAmount / $subtotal) * 100, 2) : 0
            ]
        ]);
    }
    
    /**
     * Show payment page
     */
    public function payment()
    {
        if (!session('checkout_shipping')) {
            return redirect()->route('checkout.index')
                ->with('error', 'Please fill shipping information first.');
        }

        $cartItems = session('cart_items', []);
        $ariItems = session('ari_cart_items', []);
        $shippingData = session('checkout_shipping');
        $selectedShippingMethod = session('selected_shipping_method');

        $categorizedItems = $this->categorizeItemsByFulfillment($cartItems);
        $orderTotals = $this->calculateOrderTotals($cartItems, $selectedShippingMethod);

        $warehouses = $this->wpsService->getWarehouses();
        $shippingMethods = $this->wpsService->getShippingMethods();

        $orderData = [
            'total' => $orderTotals['grand_total'],
            'customer_email' => $shippingData['email'],
            'customer_name' => $shippingData['ship_name'],
        ];

        \Log::channel('daily')->info('CREATING STRIPE PAYMENT INTENT', [
            'timestamp' => now()->toDateTimeString(),
            'order_data' => $orderData,
            'cart_items_count' => count($cartItems),
            'ari_items_count' => count($ariItems),
            'session_id' => session()->getId()
        ]);

        $paymentIntent = $this->stripeService->createPaymentIntent($orderData);

        \Log::channel('daily')->info('STRIPE PAYMENT INTENT CREATED', [
            'timestamp' => now()->toDateTimeString(),
            'success' => $paymentIntent['success'],
            'payment_intent_id' => $paymentIntent['payment_intent_id'] ?? null,
            'client_secret_prefix' => isset($paymentIntent['client_secret']) ? substr($paymentIntent['client_secret'], 0, 20) . '...' : null,
            'error' => $paymentIntent['error'] ?? null
        ]);

        if (!$paymentIntent['success']) {
            \Log::channel('daily')->error('PAYMENT INTENT CREATION FAILED', [
                'timestamp' => now()->toDateTimeString(),
                'error' => $paymentIntent['error'] ?? 'unknown',
                'order_data' => $orderData
            ]);
            return redirect()->route('checkout.index')
                ->with('error', 'Unable to initialize payment: ' . $paymentIntent['error']);
        }

        $subtotal = $orderTotals['subtotal'];
        $taxAmount = $orderTotals['tax_amount'];
        $shippingCost = $orderTotals['shipping_cost'];
        $shippingDiscount = $orderTotals['shipping_discount'] ?? 0;
        $total = $orderTotals['grand_total'];

        $paypalClientId = config('services.paypal.client_id');

        return view('checkout.payment', compact(
            'cartItems',
            'ariItems',
            'categorizedItems',
            'shippingData',
            'selectedShippingMethod',
            'orderTotals',
            'subtotal',
            'taxAmount',
            'shippingCost',
            'shippingDiscount',
            'total',
            'warehouses',
            'shippingMethods',
            'paymentIntent',
            'paypalClientId'
        ));
    }

    public function createPaypalOrder(Request $request)
    {
        $shippingData = session('checkout_shipping');
        $cartItems = session('cart_items', []);
        $selectedShippingMethod = session('selected_shipping_method');

        $orderTotals = $this->calculateOrderTotals($cartItems, $selectedShippingMethod);

        $orderData = [
            'total' => $orderTotals['grand_total'],
            'customer_email' => $shippingData['email'],
            'customer_name' => $shippingData['ship_name'],
        ];

        \Log::channel('daily')->info('CREATING PAYPAL ORDER VIA AJAX', [
            'timestamp' => now()->toDateTimeString(),
            'order_data' => $orderData,
        ]);

        $result = $this->paypalService->createOrder($orderData);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'order_id' => $result['order_id']
            ]);
        } else {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Failed to create PayPal order'
            ], 400);
        }
    }

    /**
     * Process payment and create order
     */
    public function processPayment(Request $request)
    {
        \Log::channel('daily')->info('=== PAYMENT PROCESS STARTED ===', [
            'timestamp' => now()->toDateTimeString(),
            'has_payment_intent_id' => $request->has('payment_intent_id'),
            'payment_intent_id' => $request->input('payment_intent_id'),
            'has_checkout_shipping' => session()->has('checkout_shipping'),
            'has_cart_items' => session()->has('cart_items'),
            'cart_items_count' => count(session('cart_items', [])),
            'ari_items_count' => count(session('ari_cart_items', [])),
            'request_method' => $request->method(),
            'url' => $request->fullUrl(),
            'user_agent' => $request->userAgent(),
            'ip_address' => $request->ip(),
            'same_as_shipping' => $request->input('same_as_shipping'),
            'has_billing_address' => $request->has('billing_address1')
        ]);

        // Handle billing address
        $billingData = [];
        if (!$request->has('same_as_shipping')) {
            $request->validate([
                'billing_address1' => 'required|string|max:255',
                'billing_city' => 'required|string|max:255',
                'billing_state' => 'required|string|max:2',
                'billing_zip' => 'required|string|max:20',
            ]);

            $billingData = [
                'billing_address1' => $request->billing_address1,
                'billing_city' => $request->billing_city,
                'billing_state' => $request->billing_state,
                'billing_zip' => $request->billing_zip,
            ];

            session(['checkout_billing' => $billingData]);

            \Log::channel('daily')->info('SEPARATE BILLING ADDRESS PROVIDED', [
                'billing_city' => $billingData['billing_city'],
                'billing_state' => $billingData['billing_state'],
                'billing_zip' => $billingData['billing_zip']
            ]);
        } else {
            session()->forget('checkout_billing');
            \Log::channel('daily')->info('USING SHIPPING ADDRESS AS BILLING ADDRESS');
        }

        // Check submit guard to prevent accidental submissions
        $submitGuard = $request->input('_submit_guard', '0');

        // Log all request data for debugging
        \Log::channel('daily')->info('PAYMENT SUBMIT GUARD CHECK', [
            'timestamp' => now()->toDateTimeString(),
            'submit_guard_value' => $submitGuard,
            'submit_guard_type' => gettype($submitGuard),
            'all_input_keys' => array_keys($request->all()),
            'has_payment_intent' => $request->has('payment_intent_id'),
            'payment_intent_length' => strlen($request->input('payment_intent_id', '')),
            'session_id' => session()->getId()
        ]);

        // TEMPORARILY DISABLED - WILL RE-ENABLE AFTER FIXING JAVASCRIPT CACHING ISSUE
        /*
        if ($submitGuard !== '1') {
            \Log::channel('daily')->error('PAYMENT SUBMIT GUARD FAILED', [
                'timestamp' => now()->toDateTimeString(),
                'submit_guard_value' => $submitGuard,
                'submit_guard_raw' => $request->input('_submit_guard'),
                'all_request_data' => $request->except(['password', 'password_confirmation']),
                'session_id' => session()->getId(),
                'message' => 'Form submitted without JavaScript confirmation - possible bot or JavaScript disabled'
            ]);
            return back()->with('error', 'Payment form was not properly submitted. Please ensure JavaScript is enabled and try again.');
        }
        */

        $paymentMethod = $request->input('payment_method', 'stripe');

        try {
            if ($paymentMethod === 'stripe') {
                $request->validate([
                    'payment_intent_id' => 'required|string|min:10',
                ], [
                    'payment_intent_id.required' => 'Payment confirmation failed. Please ensure your payment was processed correctly and try again.',
                    'payment_intent_id.min' => 'Invalid payment confirmation. Please try again.',
                ]);
            } elseif ($paymentMethod === 'paypal') {
                $request->validate([
                    'paypal_order_id' => 'required|string',
                ], [
                    'paypal_order_id.required' => 'PayPal payment confirmation failed. Please try again.',
                ]);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::channel('daily')->error('PAYMENT VALIDATION FAILED', [
                'timestamp' => now()->toDateTimeString(),
                'payment_method' => $paymentMethod,
                'errors' => $e->errors(),
                'input' => $request->all(),
                'session_id' => session()->getId(),
            ]);
            return back()->withErrors($e->errors())->withInput()
                ->with('error', 'Payment confirmation failed. Please try again.');
        }

        if (!session('checkout_shipping')) {
            \Log::channel('daily')->error('CHECKOUT SHIPPING SESSION MISSING', [
                'timestamp' => now()->toDateTimeString(),
                'has_cart_items' => session()->has('cart_items'),
                'session_id' => session()->getId(),
                'all_session_keys' => array_keys(session()->all())
            ]);
            return redirect()->route('checkout.index')
                ->with('error', 'Please fill shipping information first.');
        }

        $cartItems = session('cart_items', []);
        $ariItems = session('ari_cart_items', []);

        if (empty($cartItems) && empty($ariItems)) {
            \Log::channel('daily')->error('CART EMPTY DURING PAYMENT', [
                'timestamp' => now()->toDateTimeString(),
                'session_id' => session()->getId(),
                'has_cart_items_session' => session()->has('cart_items'),
                'has_ari_items_session' => session()->has('ari_cart_items')
            ]);
            return redirect()->route('shop.checkout.cart.index')
                ->with('error', 'Your cart is empty. Please add items before checkout.');
        }

        try {
            $transactionId = null;
            $paymentStatus = 'paid';

            if ($paymentMethod === 'stripe') {
                \Log::channel('daily')->info('RETRIEVING STRIPE PAYMENT INTENT', [
                    'timestamp' => now()->toDateTimeString(),
                    'payment_intent_id' => $request->payment_intent_id
                ]);

                $paymentResult = $this->stripeService->retrievePaymentIntent($request->payment_intent_id);

                \Log::channel('daily')->info('STRIPE PAYMENT INTENT RETRIEVED', [
                    'timestamp' => now()->toDateTimeString(),
                    'success' => $paymentResult['success'],
                    'status' => $paymentResult['status'] ?? 'unknown',
                    'payment_intent_id' => $request->payment_intent_id,
                ]);

                if (!$paymentResult['success']) {
                    \Log::channel('daily')->error('STRIPE PAYMENT VERIFICATION FAILED', [
                        'timestamp' => now()->toDateTimeString(),
                        'error' => $paymentResult['error'] ?? 'unknown error',
                        'payment_intent_id' => $request->payment_intent_id
                    ]);
                    return back()->with('error', 'Payment verification failed: ' . ($paymentResult['error'] ?? 'Unknown error'));
                }

                if ($paymentResult['status'] !== 'succeeded') {
                    \Log::channel('daily')->error('STRIPE PAYMENT NOT SUCCEEDED', [
                        'timestamp' => now()->toDateTimeString(),
                        'status' => $paymentResult['status'],
                        'payment_intent_id' => $request->payment_intent_id
                    ]);
                    return back()->with('error', 'Payment was not completed successfully. Status: ' . $paymentResult['status']);
                }

                $transactionId = $request->payment_intent_id;

            } elseif ($paymentMethod === 'paypal') {
                \Log::channel('daily')->info('CAPTURING PAYPAL ORDER', [
                    'timestamp' => now()->toDateTimeString(),
                    'paypal_order_id' => $request->paypal_order_id
                ]);

                $paymentResult = $this->paypalService->captureOrder($request->paypal_order_id);

                \Log::channel('daily')->info('PAYPAL ORDER CAPTURED', [
                    'timestamp' => now()->toDateTimeString(),
                    'success' => $paymentResult['success'],
                    'status' => $paymentResult['status'] ?? 'unknown',
                    'paypal_order_id' => $request->paypal_order_id,
                    'capture_id' => $paymentResult['capture_id'] ?? null,
                ]);

                if (!$paymentResult['success']) {
                    \Log::channel('daily')->error('PAYPAL PAYMENT CAPTURE FAILED', [
                        'timestamp' => now()->toDateTimeString(),
                        'error' => $paymentResult['error'] ?? 'unknown error',
                        'paypal_order_id' => $request->paypal_order_id
                    ]);
                    return back()->with('error', 'PayPal payment could not be completed: ' . ($paymentResult['error'] ?? 'Unknown error'));
                }

                $transactionId = $paymentResult['capture_id'];
            }

            \Log::channel('daily')->info('PAYMENT SUCCEEDED - CREATING ORDER', [
                'timestamp' => now()->toDateTimeString(),
                'payment_intent_id' => $request->payment_intent_id
            ]);
            
            $cartItems = session('cart_items', []);
            $shippingData = session('checkout_shipping');
            $warehouseSelections = session('warehouse_selections', []);
            $selectedShippingMethod = session('selected_shipping_method');

            // Update shipping method in shippingData if selected
            if ($selectedShippingMethod && isset($selectedShippingMethod['name'])) {
                $shippingData['ship_via'] = $selectedShippingMethod['name'];
            }

            // Calculate final totals including selected shipping
            $orderTotals = $this->calculateOrderTotals($cartItems, $selectedShippingMethod);
            
            $subtotal = $orderTotals['subtotal'];
            $taxAmount = $orderTotals['tax_amount'];
            $shippingCost = $orderTotals['shipping_cost'];
            $total = $orderTotals['grand_total'];

            // Real-time price verification before payment
            $priceChanges = $this->verifyDropshipperPrices($cartItems);
            if (!empty($priceChanges)) {
                return back()->with('error', 'Prices have changed. Please review your cart: ' . implode(', ', $priceChanges));
            }

            // Group items by supplier for order processing
            $ordersBySupplier = $this->groupItemsBySupplier($cartItems);
            
            \Log::info('Items grouped by supplier', [
                'suppliers' => array_keys($ordersBySupplier),
                'supplier_item_counts' => array_map('count', $ordersBySupplier),
                'total_cart_items' => count($cartItems)
            ]);
            
            // Also group ARI Partstream items by their selected suppliers
            $ariItems = session('ari_cart_items', []);
            foreach ($ariItems as $ariItem) {
                $selectedSupplier = $ariItem['selected_supplier'] ?? 'ari_partstream';
                
                // Convert ari_partstream to ari_stock for ShipStation routing
                if ($selectedSupplier === 'ari_partstream') {
                    $selectedSupplier = 'ari_stock';
                }
                
                if (!isset($ordersBySupplier[$selectedSupplier])) {
                    $ordersBySupplier[$selectedSupplier] = [];
                }
                
                // Convert ARI item to cart item format for supplier processing
                $ordersBySupplier[$selectedSupplier][] = [
                    'id' => 'ari_' . ($ariItem['sku'] ?? uniqid()),
                    'sku' => $ariItem['sku'],
                    'name' => $ariItem['name'],
                    'price' => $ariItem['price'],
                    'quantity' => $ariItem['quantity'],
                    'selected_supplier' => $selectedSupplier,
                    'is_ari_partstream' => true, // Flag to identify ARI items
                    'brand' => $ariItem['brand'] ?? null,
                    'image_url' => $ariItem['image_url'] ?? null,
                    'return_url' => $ariItem['return_url'] ?? null,
                    // Include supplier-specific IDs if available
                    'wps_item_id' => $ariItem['suppliers'][$selectedSupplier]['wps_item_id'] ?? null,
                    'parts_unlimited_sku' => $ariItem['suppliers'][$selectedSupplier]['parts_unlimited_sku'] ?? null,
                    'turn14_item_id' => $ariItem['suppliers'][$selectedSupplier]['turn14_item_id'] ?? null,
                    'helmet_house_sku' => $ariItem['suppliers'][$selectedSupplier]['helmet_house_sku'] ?? null,
                    'turn14_location' => $ariItem['suppliers'][$selectedSupplier]['turn14_location'] ?? '01'
                ];
            }

            // Process orders with each supplier
            $supplierOrders = [];
            foreach ($ordersBySupplier as $supplier => $items) {
                if ($supplier === 'wps') {
                    // Separate regular cart items from ARI Partstream items
                    $regularItems = array_filter($items, fn($item) => !($item['is_ari_partstream'] ?? false));
                    $ariPartstreamItems = array_filter($items, fn($item) => $item['is_ari_partstream'] ?? false);
                    
                    $logItems = [];
                    foreach ($items as $item) {
                        $logItems[] = [
                            'sku' => $item['sku'],
                            'name' => $item['name'] ?? 'Unknown',
                            'quantity' => $item['quantity'],
                            'is_ari_partstream' => $item['is_ari_partstream'] ?? false,
                            'wps_item_id' => $item['wps_item_id'] ?? null
                        ];
                    }
                    
                    \Log::channel('dropship')->info('Processing WPS Order', [
                        'supplier' => $supplier,
                        'total_items_count' => count($items),
                        'regular_items_count' => count($regularItems),
                        'ari_partstream_items_count' => count($ariPartstreamItems),
                        'items' => $logItems,
                        'customer_email' => $shippingData['email'] ?? 'Unknown'
                    ]);
                    
                    $wpsResult = $this->wpsDropshipService->createOrder($items, $shippingData, $warehouseSelections);
                    
                    if ($wpsResult['success']) {
                        $supplierOrders['wps'] = $wpsResult;
                        
                        \Log::channel('dropship')->info('WPS Order Successful', [
                            'po_number' => $wpsResult['po_number'],
                            'wps_order_number' => $wpsResult['order_number'] ?? 'Pending',
                            'regular_items' => count($regularItems),
                            'ari_partstream_items' => count($ariPartstreamItems)
                        ]);
                    } else {
                        \Log::channel('dropship')->error('WPS Order Failed', [
                            'error' => $wpsResult['error'],
                            'items' => $logItems
                        ]);
                    }
                }
                elseif ($supplier === 'parts_unlimited') {
                    // Separate regular cart items from ARI Partstream items
                    $regularItems = array_filter($items, fn($item) => !($item['is_ari_partstream'] ?? false));
                    $ariPartstreamItems = array_filter($items, fn($item) => $item['is_ari_partstream'] ?? false);
                    
                    $logItems = [];
                    foreach ($items as $item) {
                        $logItems[] = [
                            'sku' => $item['sku'],
                            'name' => $item['name'] ?? 'Unknown',
                            'quantity' => $item['quantity'],
                            'is_ari_partstream' => $item['is_ari_partstream'] ?? false,
                            'parts_unlimited_sku' => $item['parts_unlimited_sku'] ?? null
                        ];
                    }
                    
                    \Log::channel('dropship')->info('Processing Parts Unlimited Order', [
                        'supplier' => $supplier,
                        'total_items_count' => count($items),
                        'regular_items_count' => count($regularItems),
                        'ari_partstream_items_count' => count($ariPartstreamItems),
                        'items' => $logItems,
                        'customer_email' => $shippingData['email'] ?? 'Unknown'
                    ]);

                    $puResult = $this->partsUnlimitedDropshipService->createOrder($items, $shippingData);

                    if ($puResult['success']) {
                        $supplierOrders['parts_unlimited'] = $puResult;

                        \Log::channel('dropship')->info('Parts Unlimited Order Successful', [
                            'po_number' => $puResult['po_number'],
                            'reference_number' => $puResult['reference_number'] ?? 'Pending',
                            'status_code' => $puResult['status_code'],
                            'regular_items' => count($regularItems),
                            'ari_partstream_items' => count($ariPartstreamItems)
                        ]);
                    } else {
                        \Log::channel('dropship')->error('Parts Unlimited Order Failed', [
                            'error' => $puResult['error'],
                            'items' => $logItems
                        ]);
                    }
                }
                elseif ($supplier === 'turn14') {
                    // Separate regular cart items from ARI Partstream items
                    $regularItems = array_filter($items, fn($item) => !($item['is_ari_partstream'] ?? false));
                    $ariPartstreamItems = array_filter($items, fn($item) => $item['is_ari_partstream'] ?? false);
                    
                    $logItems = [];
                    foreach ($items as $item) {
                        $logItems[] = [
                            'sku' => $item['sku'],
                            'name' => $item['name'] ?? 'Unknown',
                            'quantity' => $item['quantity'],
                            'is_ari_partstream' => $item['is_ari_partstream'] ?? false,
                            'turn14_item_id' => $item['turn14_item_id'] ?? null
                        ];
                    }
                    
                    \Log::channel('dropship')->info('Processing Turn14 Order', [
                        'supplier' => $supplier,
                        'total_items_count' => count($items),
                        'regular_items_count' => count($regularItems),
                        'ari_partstream_items_count' => count($ariPartstreamItems),
                        'items' => $logItems,
                        'customer_email' => $shippingData['email'] ?? 'Unknown'
                    ]);

                    $turn14Items = [];
                    foreach ($items as $item) {
                        $turn14Items[] = [
                            'item_id' => $item['turn14_item_id'] ?? $item['sku'],
                            'quantity' => $item['quantity'],
                            'location' => $item['turn14_location'] ?? '01',
                            'shipping_code' => 3
                        ];
                    }

                    $turn14Result = $this->turn14DropshipService->createOrder([
                        'po_number' => 'MADD-T14-' . time(),
                        'items' => $turn14Items,
                        'shipping' => [
                            'company' => $shippingData['company'] ?? '',
                            'name' => $shippingData['ship_name'],
                            'address' => $shippingData['ship_address1'],
                            'address_2' => $shippingData['ship_address2'] ?? '',
                            'city' => $shippingData['ship_city'],
                            'state' => $shippingData['ship_state'],
                            'zip' => $shippingData['ship_zip'],
                            'country' => 'US',
                            'phone' => $shippingData['ship_phone'],
                            'email' => $shippingData['email']
                        ]
                    ]);

                    if ($turn14Result['success']) {
                        $supplierOrders['turn14'] = $turn14Result;

                        \Log::channel('dropship')->info('Turn14 Order Successful', [
                            'po_number' => $turn14Result['po_number'],
                            'turn14_order_id' => $turn14Result['order_id'] ?? 'Pending',
                            'test_mode' => $turn14Result['test_mode'] ?? false,
                            'regular_items' => count($regularItems),
                            'ari_partstream_items' => count($ariPartstreamItems)
                        ]);
                    } else {
                        \Log::channel('dropship')->error('Turn14 Order Failed', [
                            'error' => $turn14Result['error'],
                            'items' => $logItems
                        ]);
                    }
                }
                elseif ($supplier === 'helmet_house') {
                    // Separate regular cart items from ARI Partstream items
                    $regularItems = array_filter($items, fn($item) => !($item['is_ari_partstream'] ?? false));
                    $ariPartstreamItems = array_filter($items, fn($item) => $item['is_ari_partstream'] ?? false);
                    
                    $logItems = [];
                    foreach ($items as $item) {
                        $logItems[] = [
                            'sku' => $item['sku'],
                            'name' => $item['name'] ?? 'Unknown',
                            'quantity' => $item['quantity'],
                            'is_ari_partstream' => $item['is_ari_partstream'] ?? false,
                            'helmet_house_sku' => $item['helmet_house_sku'] ?? null
                        ];
                    }
                    
                    \Log::channel('dropship')->info('Processing Helmet House Order', [
                        'supplier' => $supplier,
                        'total_items_count' => count($items),
                        'regular_items_count' => count($regularItems),
                        'ari_partstream_items_count' => count($ariPartstreamItems),
                        'items' => $logItems,
                        'customer_email' => $shippingData['email'] ?? 'Unknown'
                    ]);

                    $hhResult = $this->helmetHouseDropshipService->createOrder($items, $shippingData);

                    if ($hhResult['success']) {
                        $supplierOrders['helmet_house'] = $hhResult;

                        \Log::channel('dropship')->info('Helmet House Order Successful', [
                            'po_number' => $hhResult['po_number'],
                            'reference_number' => $hhResult['reference_number'] ?? 'Pending',
                            'test_mode' => $hhResult['test_mode'] ?? false,
                            'regular_items' => count($regularItems),
                            'ari_partstream_items' => count($ariPartstreamItems)
                        ]);
                    } else {
                        \Log::channel('dropship')->error('Helmet House Order Failed', [
                            'error' => $hhResult['error'],
                            'items' => $logItems
                        ]);
                    }
                }
                elseif ($supplier === 'ari_stock') {
                    // Separate regular cart items from ARI Partstream items
                    $regularItems = array_filter($items, fn($item) => !($item['is_ari_partstream'] ?? false));
                    $ariPartstreamItems = array_filter($items, fn($item) => $item['is_ari_partstream'] ?? false);
                    
                    $logItems = [];
                    foreach ($items as $item) {
                        $logItems[] = [
                            'sku' => $item['sku'] ?? 'NO_SKU',
                            'name' => $item['name'] ?? 'Unknown',
                            'quantity' => $item['quantity'] ?? 0,
                            'price' => $item['price'] ?? 0,
                            'is_ari_partstream' => $item['is_ari_partstream'] ?? false,
                            'selected_supplier' => $item['selected_supplier'] ?? 'NONE'
                        ];
                    }
                    
                    if (empty($items)) {
                        \Log::channel('shipstation')->warning('No items to send to ShipStation - items array is empty', [
                            'supplier' => $supplier,
                            'cart_items_count' => count($cartItems),
                            'ari_items_count' => count($ariItems ?? [])
                        ]);
                    } else {
                        \Log::channel('shipstation')->info('Processing ARI In-House Order via ShipStation', [
                            'supplier' => $supplier,
                            'total_items_count' => count($items),
                            'regular_items_count' => count($regularItems),
                            'ari_partstream_items_count' => count($ariPartstreamItems),
                            'items' => $logItems,
                            'customer_email' => $shippingData['email'] ?? 'Unknown'
                        ]);
                    }
                    
                    $shipStationResult = $this->createShipStationOrder($items, $shippingData, $request->payment_intent_id);
                    
                    if ($shipStationResult['success']) {
                        $supplierOrders['ari_stock'] = $shipStationResult;
                        
                        \Log::channel('shipstation')->info('ShipStation Order Successful', [
                            'order_number' => $shipStationResult['order_number'],
                            'shipstation_order_id' => $shipStationResult['shipstation_order_id'] ?? 'Pending',
                            'regular_items' => count($regularItems),
                            'ari_partstream_items' => count($ariPartstreamItems)
                        ]);
                    } else {
                        \Log::channel('shipstation')->error('ShipStation Order Failed', [
                            'error' => $shipStationResult['error'],
                            'items' => $logItems
                        ]);
                    }
                }
            }
            
            // Create Bagisto order in database
            $ariItems = session('ari_cart_items', []);
            $bagistoOrder = $this->createBagistoOrder([
                'cart_items' => $cartItems,
                'ari_items' => $ariItems,
                'shipping_data' => $shippingData,
                'payment_method' => $paymentMethod,
                'transaction_id' => $transactionId,
                'supplier_orders' => $supplierOrders,
                'shipstation_result' => $shipStationResult ?? null,
                'totals' => [
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'shipping_cost' => $shippingCost,
                    'grand_total' => $total
                ]
            ]);

            $this->saveFulfillmentDetails($bagistoOrder->id, $supplierOrders, $ordersBySupplier);

            $this->safeLog('info', 'Payment successful, Bagisto order created', [
                'payment_method' => $paymentMethod,
                'transaction_id' => $transactionId,
                'bagisto_order_id' => $bagistoOrder->id,
                'increment_id' => $bagistoOrder->increment_id,
                'amount' => $total
            ]);

            event('order.created', $bagistoOrder->id);

            // Send order confirmation emails
            // Get coupon data for emails
            $couponDiscount = session('coupon_discount', 0);
            $couponCode = session('coupon_code', null);

            try {
                // Send email to customer
                Mail::send(new CustomerOrderConfirmation(
                    $bagistoOrder,
                    $cartItems,
                    $ariItems,
                    $shippingData,
                    [
                        'subtotal' => $subtotal,
                        'tax_amount' => $taxAmount,
                        'discount' => $couponDiscount,
                        'coupon_code' => $couponCode,
                        'shipping_cost' => $shippingCost,
                        'grand_total' => $total
                    ]
                ));

                // Send email to admin(s)
                Mail::send(new AdminOrderNotification(
                    $bagistoOrder,
                    $cartItems,
                    $ariItems,
                    $shippingData,
                    [
                        'subtotal' => $subtotal,
                        'tax_amount' => $taxAmount,
                        'discount' => $couponDiscount,
                        'coupon_code' => $couponCode,
                        'shipping_cost' => $shippingCost,
                        'grand_total' => $total
                    ]
                ));

                \Log::info('Order confirmation emails sent successfully', [
                    'order_id' => $bagistoOrder->id,
                    'customer_email' => $bagistoOrder->customer_email
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to send order confirmation emails', [
                    'order_id' => $bagistoOrder->id,
                    'error' => $e->getMessage()
                ]);
                // Don't fail the order if email fails - just log it
            }

            // Get coupon data
            $couponDiscount = session('coupon_discount', 0);
            $couponCode = session('coupon_code', null);

            // Store order confirmation for success page
            session([
                'order_confirmation' => [
                    'order_number' => $bagistoOrder->increment_id,
                    'order_id' => $bagistoOrder->id,
                    'transaction_id' => $transactionId,
                    'items' => $cartItems,
                    'shipping' => $shippingData,
                    'payment' => [
                        'method' => $paymentMethod,
                        'status' => 'paid',
                        'amount' => $total
                    ],
                    'totals' => [
                        'subtotal' => $subtotal,
                        'tax' => $taxAmount,
                        'discount' => $couponDiscount,
                        'coupon_code' => $couponCode,
                        'shipping' => $shippingCost,
                        'total' => $total
                    ],
                    'created_at' => now()
                ]
            ]);
            
            // Clear the cart
            session()->forget('cart_items');
            session()->forget('ari_cart_items');

            return redirect()->route('checkout.success');

        } catch (\Exception $e) {
            \Log::channel('daily')->error('=== CHECKOUT EXCEPTION ===', [
                'timestamp' => now()->toDateTimeString(),
                'exception_class' => get_class($e),
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'payment_intent_id' => $request->input('payment_intent_id'),
                'session_id' => session()->getId(),
                'cart_items_count' => count(session('cart_items', [])),
                'ari_items_count' => count(session('ari_cart_items', [])),
                'has_shipping_data' => session()->has('checkout_shipping')
            ]);
            return back()->with('error', 'An error occurred during checkout: ' . $e->getMessage());
        }
    }
    
    /**
     * Log JavaScript errors from checkout page
     */
    public function logError(Request $request)
    {
        try {
            $errorType = $request->input('error_type', 'unknown');
            $errorDetails = $request->input('error_details', []);

            \Log::channel('daily')->error('=== JAVASCRIPT CHECKOUT ERROR ===', [
                'timestamp' => now()->toDateTimeString(),
                'error_type' => $errorType,
                'error_details' => $errorDetails,
                'session_id' => session()->getId(),
                'user_agent' => $request->userAgent(),
                'ip_address' => $request->ip(),
                'url' => $request->header('referer'),
                'cart_items_count' => count(session('cart_items', [])),
                'ari_items_count' => count(session('ari_cart_items', [])),
                'has_shipping_data' => session()->has('checkout_shipping')
            ]);

            return response()->json(['success' => true]);
        } catch (\Exception $e) {
            \Log::channel('daily')->error('Failed to log JavaScript error', [
                'error' => $e->getMessage()
            ]);
            return response()->json(['success' => false], 500);
        }
    }

    /**
     * Show order confirmation page
     */
    public function success()
    {
        $orderData = session('order_confirmation');

        if (!$orderData) {
            return redirect()->route('shop.home.index')
                ->with('info', 'No recent order found.');
        }

        // Pass additional data for the success template
        $shippingData = $orderData['shipping'] ?? [];
        $warehouses = $this->wpsService->getWarehouses();
        $shippingMethods = $this->wpsService->getShippingMethods();

        return view('checkout.success', compact(
            'orderData',
            'shippingData',
            'warehouses',
            'shippingMethods'
        ));
    }
    
    /**
     * Show order review page (optional step before payment)
     */
    public function review()
    {
        if (!session('checkout_shipping')) {
            return redirect()->route('checkout.index')
                ->with('error', 'Please fill shipping information first.');
        }
        
        $cartItems = session('cart_items', []);
        $ariItems = session('ari_cart_items', []);
        $shippingData = session('checkout_shipping');
        
        // Calculate totals using the new dynamic method
        $selectedShippingMethod = session('selected_shipping_method');
        $orderTotals = $this->calculateOrderTotals($cartItems, $selectedShippingMethod);
        
        return view('checkout.review', compact(
            'cartItems',
            'ariItems',
            'shippingData',
            'orderTotals'
        ));
    }
    
    /**
     * Get order status (AJAX)
     */
    public function getOrderStatus($poNumber)
    {
        $result = $this->wpsService->getOrderStatus($poNumber);
        
        return response()->json($result);
    }
    
    /**
     * Get available warehouses for a WPS item ID
     */
    public function getWpsWarehouses($itemId)
    {
        try {
            $warehouses = $this->wpsService->getAvailableWarehouses($itemId);
            
            return response()->json([
                'success' => true,
                'warehouses' => $warehouses
            ]);
        } catch (\Exception $e) {
            \Log::warning('Failed to fetch WPS warehouses for item: ' . $itemId, [
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Unable to fetch warehouse options',
                'warehouses' => [
                    ['code' => 'ID', 'name' => 'Boise, ID', 'quantity' => 1],
                    ['code' => 'TX', 'name' => 'Midlothian, TX', 'quantity' => 1]
                ]
            ]);
        }
    }
    
    /**
     * Store warehouse selections in session
     */
    public function storeWarehouseSelections(Request $request)
    {
        $warehouseSelections = json_decode($request->warehouse_selections, true);
        
        if ($warehouseSelections) {
            session(['warehouse_selections' => $warehouseSelections]);
            
            return response()->json([
                'success' => true,
                'message' => 'Warehouse selections stored'
            ]);
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Invalid warehouse selections'
        ], 400);
    }
    
    /**
     * Handle Stripe webhook events
     */
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $signature = $request->header('stripe-signature');
        
        if (!$signature) {
            return response()->json(['error' => 'Missing signature'], 400);
        }
        
        $result = $this->stripeService->handleWebhook(
            json_decode($payload, true),
            $signature
        );
        
        if (!$result['success']) {
            return response()->json(['error' => $result['error']], 400);
        }
        
        return response()->json(['status' => 'success']);
    }
    
    /**
     * Create a complete Bagisto order in the database using direct DB operations
     */
    private function createBagistoOrder($data)
    {
        $cartItems = $data['cart_items'];
        $shippingData = $data['shipping_data'];
        $paymentMethod = $data['payment_method'] ?? 'stripe';
        $transactionId = $data['transaction_id'];
        $totals = $data['totals'];
        
        // Find existing customer
        $customerEmail = $shippingData['email'];
        $existingCustomer = $this->customerRepository->findOneByField('email', $customerEmail);
        
        // Calculate additional fields required by Bagisto
        $totalItemCount = count($cartItems);
        $totalQtyOrdered = array_sum(array_column($cartItems, 'quantity'));
        $taxRate = 0.08;
        
        // Calculate tax-inclusive amounts
        $subTotalInclTax = $totals['subtotal'] + $totals['tax_amount'];
        $shippingAmountInclTax = $totals['shipping_cost']; // No tax on shipping for now
        
        // Generate increment_id (order number)
        $incrementId = 'ORD-' . date('Y') . '-' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        
        $couponDiscount = session('coupon_discount', 0);
        $couponCode = session('coupon_code', null);

        $shipstationResult = $data['shipstation_result'] ?? null;
        $shipstationOrderId = null;
        $shipstationOrderKey = null;
        $shipstationOrderNumber = null;

        if ($shipstationResult && isset($shipstationResult['success']) && $shipstationResult['success']) {
            $shipstationOrderId = $shipstationResult['shipstation_order_id'] ?? null;
            $shipstationOrderKey = $shipstationResult['shipstation_order_key'] ?? null;
            $shipstationOrderNumber = $shipstationResult['order_number'] ?? null;
        }

        // Create order directly in database
        $orderData = [
            'increment_id' => $incrementId,
            'status' => 'processing',
            'channel_name' => 'maddparts',
            'is_guest' => $existingCustomer ? 0 : 1,
            'customer_id' => $existingCustomer ? $existingCustomer->id : null,
            'customer_type' => $existingCustomer ? 'Webkul\\Customer\\Models\\Customer' : null,
            'customer_email' => $customerEmail,
            'customer_first_name' => $shippingData['ship_name'],
            'customer_last_name' => '',
            'shipping_method' => 'flatrate_flatrate',
            'shipping_title' => 'Flat Rate - Flat Rate',
            'shipping_description' => 'Flat Rate Shipping',
            'coupon_code' => $couponCode,
            'discount_amount' => $couponDiscount,
            'base_discount_amount' => $couponDiscount,
            'customer_notes' => substr(trim($shippingData['order_comments'] ?? ''), 0, 200),
            'is_gift' => 0,
            'total_item_count' => $totalItemCount,
            'total_qty_ordered' => $totalQtyOrdered,
            'base_currency_code' => 'USD',
            'channel_currency_code' => 'USD',
            'order_currency_code' => 'USD',
            'grand_total' => $totals['grand_total'],
            'base_grand_total' => $totals['grand_total'],
            'sub_total' => $totals['subtotal'],
            'base_sub_total' => $totals['subtotal'],
            'tax_amount' => $totals['tax_amount'],
            'base_tax_amount' => $totals['tax_amount'],
            'shipping_amount' => $totals['shipping_cost'],
            'base_shipping_amount' => $totals['shipping_cost'],
            'shipping_tax_amount' => 0,
            'base_shipping_tax_amount' => 0,
            'sub_total_incl_tax' => $subTotalInclTax,
            'base_sub_total_incl_tax' => $subTotalInclTax,
            'shipping_amount_incl_tax' => $shippingAmountInclTax,
            'base_shipping_amount_incl_tax' => $shippingAmountInclTax,
            'shipstation_order_id' => $shipstationOrderId,
            'shipstation_order_key' => $shipstationOrderKey,
            'shipstation_order_number' => $shipstationOrderNumber,
            'channel_id' => 1,
            'channel_type' => 'Webkul\\Core\\Models\\Channel',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        \Log::info('ATTEMPTING TO CREATE ORDER', [
            'payment_method' => $paymentMethod,
            'transaction_id' => $transactionId,
            'grand_total' => $totals['grand_total'],
            'customer_email' => $customerEmail,
            'increment_id' => $incrementId
        ]);

        // Insert order and get the ID
        $orderId = \DB::table('orders')->insertGetId($orderData);

        if (!$orderId || $orderId === 0) {
            \Log::error('FAILED TO CREATE ORDER - insertGetId returned invalid ID', [
                'order_id' => $orderId,
                'order_data' => $orderData
            ]);
            throw new \Exception('Failed to create order in database. Please try again.');
        }

        \Log::info('Order created with ID: ' . $orderId);

        // Create order items
        foreach ($cartItems as $item) {
            $product = $this->productRepository->find($item['product_id']);
            
            $itemTaxAmount = ($item['price'] * $item['quantity']) * $taxRate;
            $itemPriceInclTax = $item['price'] + ($item['price'] * $taxRate);
            $itemTotalInclTax = $itemPriceInclTax * $item['quantity'];
            
            $orderItemData = [
                'order_id' => $orderId,
                'product_id' => $product->id,
                'product_type' => get_class($product),
                'sku' => $product->sku,
                'type' => $product->type,
                'name' => $product->name,
                'weight' => $product->weight ?? 0,
                'total_weight' => ($product->weight ?? 0) * $item['quantity'],
                'qty_ordered' => $item['quantity'],
                'qty_shipped' => 0,
                'qty_invoiced' => 0,
                'qty_canceled' => 0,
                'qty_refunded' => 0,
                'price' => $item['price'],
                'base_price' => $item['price'],
                'total' => $item['price'] * $item['quantity'],
                'base_total' => $item['price'] * $item['quantity'],
                'tax_percent' => $taxRate * 100,
                'tax_amount' => $itemTaxAmount,
                'base_tax_amount' => $itemTaxAmount,
                'price_incl_tax' => $itemPriceInclTax,
                'base_price_incl_tax' => $itemPriceInclTax,
                'total_incl_tax' => $itemTotalInclTax,
                'base_total_incl_tax' => $itemTotalInclTax,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            
            \DB::table('order_items')->insert($orderItemData);

            \DB::table('product_inventories')
                ->where('product_id', $product->id)
                ->where('qty', '>', 0)
                ->decrement('qty', $item['quantity']);

            \Log::info("Deducted inventory for product {$product->sku}: -{$item['quantity']} units");
        }

        // Create ARI PartStream order items
        $ariItems = $data['ari_items'] ?? [];
        
        // CRITICAL FIX: Delete any existing ARI items for this order_id before inserting new ones
        // This prevents orphaned items from abandoned carts or testing from appearing in new orders
        if (!empty($ariItems)) {
            $deletedCount = \DB::table('ari_partstream_order_items')
                ->where('order_id', $orderId)
                ->delete();
            
            if ($deletedCount > 0) {
                \Log::info("Cleaned up {$deletedCount} orphaned ARI items for order_id {$orderId}");
            }
        }
        
        foreach ($ariItems as $ariItem) {
            $selectedSupplier = $ariItem['selected_supplier'] ?? 'ari_partstream';
            if ($selectedSupplier === 'ari_partstream') {
                $selectedSupplier = 'ari_stock';
            }

            $ariItemTaxAmount = ($ariItem['price'] * $ariItem['quantity']) * $taxRate;

            $ariOrderItemData = [
                'order_id' => $orderId,
                'sku' => $ariItem['sku'],
                'name' => $ariItem['name'],
                'brand' => $ariItem['brand'] ?? null,
                'quantity' => $ariItem['quantity'],
                'price' => $ariItem['price'],
                'total' => $ariItem['price'] * $ariItem['quantity'],
                'tax_amount' => $ariItemTaxAmount,
                'base_tax_amount' => $ariItemTaxAmount,
                'selected_supplier' => $selectedSupplier,
                'image_url' => $ariItem['image_url'] ?? null,
                'return_url' => $ariItem['return_url'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            \DB::table('ari_partstream_order_items')->insert($ariOrderItemData);
        }

        // Create shipping address
        $shippingAddressData = [
            'address_type' => 'order_shipping',
            'order_id' => $orderId,
            'first_name' => $shippingData['ship_name'],
            'last_name' => '',
            'company_name' => '',
            'address' => $shippingData['ship_address1'] . ($shippingData['ship_address2'] ? ', ' . $shippingData['ship_address2'] : ''),
            'city' => $shippingData['ship_city'],
            'state' => $shippingData['ship_state'],
            'country' => 'US',
            'postcode' => $shippingData['ship_zip'],
            'email' => $shippingData['email'],
            'phone' => $shippingData['ship_phone'],
            'default_address' => 0,
            'use_for_shipping' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        
        \DB::table('addresses')->insert($shippingAddressData);

        // Create billing address
        $billingData = session('checkout_billing');
        if ($billingData && !empty($billingData)) {
            $billingAddressData = [
                'address_type' => 'order_billing',
                'order_id' => $orderId,
                'first_name' => $shippingData['ship_name'],
                'last_name' => '',
                'company_name' => '',
                'address' => $billingData['billing_address1'],
                'city' => $billingData['billing_city'],
                'state' => $billingData['billing_state'],
                'country' => 'US',
                'postcode' => $billingData['billing_zip'],
                'email' => $shippingData['email'],
                'phone' => $shippingData['ship_phone'],
                'default_address' => 0,
                'use_for_shipping' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            \Log::info('CREATING SEPARATE BILLING ADDRESS', [
                'billing_city' => $billingData['billing_city'],
                'billing_state' => $billingData['billing_state'],
                'order_id' => $orderId
            ]);
        } else {
            $billingAddressData = $shippingAddressData;
            $billingAddressData['address_type'] = 'order_billing';
            $billingAddressData['use_for_shipping'] = 0;

            \Log::info('USING SHIPPING AS BILLING ADDRESS', ['order_id' => $orderId]);
        }

        \DB::table('addresses')->insert($billingAddressData);
        
        // Create payment record
        $paymentMethodTitle = $paymentMethod === 'stripe' ? 'Credit Card (Stripe)' : 'PayPal';

        $paymentData = [
            'order_id' => $orderId,
            'method' => $paymentMethod,
            'method_title' => $paymentMethodTitle,
            'additional' => json_encode([
                'transaction_id' => $transactionId,
                'status' => 'paid',
                'payment_method' => $paymentMethod
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        \DB::table('order_payment')->insert($paymentData);
        
        // Return a mock order object with the essential data
        $mockOrder = (object) [
            'id' => $orderId,
            'increment_id' => $incrementId,
            'status' => 'processing',
            'grand_total' => $totals['grand_total'],
            'customer_email' => $shippingData['email'],
            'customer_first_name' => $shippingData['ship_name'],
            'customer_notes' => substr(trim($shippingData['order_comments'] ?? ''), 0, 200),
            'payment_method' => $paymentMethod,
            'payment_method_title' => $paymentMethodTitle,
            'transaction_id' => $transactionId,
            'created_at' => now()
        ];
        
        \Log::info('Order creation completed successfully', [
            'order_id' => $orderId,
            'increment_id' => $incrementId
        ]);
        
        return $mockOrder;
    }

    private function groupItemsBySupplier($cartItems)
    {
        $ordersBySupplier = [];

        foreach ($cartItems as $item) {
            $selectedSupplier = $item['selected_supplier'] ?? 'ari_stock';
            
            if (!isset($ordersBySupplier[$selectedSupplier])) {
                $ordersBySupplier[$selectedSupplier] = [];
            }

            $ordersBySupplier[$selectedSupplier][] = $item;
        }

        return $ordersBySupplier;
    }
    
    /**
     * Separate cart items by fulfillment type (dropship vs in-house)
     */
    private function categorizeItemsByFulfillment($cartItems)
    {
        $inHouseItems = [];
        $dropshipItems = [];
        
        // Check regular cart items
        foreach ($cartItems as $item) {
            $selectedSupplier = $item['selected_supplier'] ?? 'ari_stock';
            
            if ($selectedSupplier === 'ari_stock') {
                $inHouseItems[] = $item;
            } else {
                $dropshipItems[] = $item;
            }
        }
        
        // Also check ARI PartStream items - they become ari_stock items
        $ariItems = session('ari_cart_items', []);
        foreach ($ariItems as $ariItem) {
            $selectedSupplier = $ariItem['selected_supplier'] ?? 'ari_partstream';
            
            // ARI Partstream items without dropshipper become in-house (ari_stock)
            if ($selectedSupplier === 'ari_partstream' || $selectedSupplier === 'ari_stock') {
                $inHouseItems[] = $ariItem;
            } else {
                $dropshipItems[] = $ariItem;
            }
        }
        
        return [
            'in_house' => $inHouseItems,
            'dropship' => $dropshipItems,
            'needs_shipping_selection' => !empty($inHouseItems)
        ];
    }
    
    /**
     * Calculate shipping rates for in-house items only
     */
    private function calculateInHouseShippingRates($inHouseItems, $shippingAddress)
    {
        if (empty($inHouseItems)) {
            return [
                'success' => true,
                'rates' => []
            ];
        }

        $shipToAddress = [
            'state' => $shippingAddress['ship_state'] ?? '',
            'postalCode' => $shippingAddress['ship_zip'] ?? '',
            'country' => 'US',
            'city' => $shippingAddress['ship_city'] ?? ''
        ];

        \Log::channel('shipstation')->info('Calculating in-house shipping rates', [
            'address' => $shipToAddress,
            'items_count' => count($inHouseItems),
            'first_item_structure' => !empty($inHouseItems) ? array_keys($inHouseItems[0]) : []
        ]);

        $ratesResult = $this->shipStationService->calculateShippingRates(
            $shipToAddress,
            $inHouseItems
        );

        if ($ratesResult['success'] && !empty($ratesResult['rates'])) {
            \Log::channel('shipstation')->info('Real shipping rates retrieved successfully', [
                'rates_count' => count($ratesResult['rates']),
                'items_count' => count($inHouseItems)
            ]);

            return $ratesResult;
        }

        \Log::channel('shipstation')->error('Real rates API failed - no shipping options available', [
            'error' => $ratesResult['error'] ?? 'Unknown error',
            'shipping_address' => $shipToAddress
        ]);

        return $ratesResult;
    }
    
    /**
     * Calculate order totals without tax for initial checkout page load
     */
    private function calculateOrderTotalsWithoutTax($cartItems)
    {
        $subtotal = 0;
        $categorizedItems = $this->categorizeItemsByFulfillment($cartItems);

        // Calculate subtotal from regular cart items
        foreach ($cartItems as $item) {
            $selectedSupplier = $item['selected_supplier'] ?? 'ari_stock';
            $supplierPrice = $item['suppliers'][$selectedSupplier]['price'] ?? $item['price'];
            $subtotal += $supplierPrice * $item['quantity'];
        }

        // Add ARI items to subtotal
        $ariItems = session('ari_cart_items', []);
        foreach ($ariItems as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }

        return [
            'subtotal' => $subtotal,
            'tax_amount' => 0, // No tax calculated until shipping address provided
            'shipping_cost' => 0, // No shipping calculated until shipping method selected
            'grand_total' => $subtotal,
            'categorized_items' => $categorizedItems
        ];
    }

    /**
     * Calculate total order amount including selected shipping
     */
    private function calculateOrderTotals($cartItems, $selectedShippingMethod = null)
    {
        $subtotal = 0;
        $categorizedItems = $this->categorizeItemsByFulfillment($cartItems);

        // Calculate subtotal from regular cart items
        foreach ($cartItems as $item) {
            $selectedSupplier = $item['selected_supplier'] ?? 'ari_stock';
            $supplierPrice = $item['suppliers'][$selectedSupplier]['price'] ?? $item['price'];
            $subtotal += $supplierPrice * $item['quantity'];
        }

        // Add ARI items to subtotal
        $ariItems = session('ari_cart_items', []);
        foreach ($ariItems as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }

        $couponDiscount = session('coupon_discount', 0);

        $subtotalAfterCoupon = max(0, $subtotal - $couponDiscount);

        // Calculate tax dynamically based on shipping address
        $shippingData = session('checkout_shipping', []);
        $taxAmount = $this->calculateTax($subtotalAfterCoupon, $shippingData);

        $freeShippingSettings = \App\Models\FreeShippingSetting::current();
        $shippingCost = 0;

        if ($freeShippingSettings->flat_rate_enabled) {
            $shippingCost = $freeShippingSettings->flat_rate_amount;
        } else {
            if ($categorizedItems['needs_shipping_selection'] && $selectedShippingMethod) {
                $shippingCost = $selectedShippingMethod['rate'] ?? 0;
            }

            if (!empty($shippingData)) {
                $turn14Shipping = $this->calculateTurn14Shipping($cartItems, $ariItems, $shippingData);
                $shippingCost += $turn14Shipping;
            }
        }

        $shippingDiscount = 0;

        if ($freeShippingSettings->enabled && $subtotal >= $freeShippingSettings->threshold && $shippingCost > 0) {
            $shippingDiscount = $shippingCost;
            $shippingCost = 0;
        }

        $total = $subtotalAfterCoupon + $taxAmount + $shippingCost;

        return [
            'subtotal' => $subtotal,
            'coupon_discount' => $couponDiscount,
            'subtotal_after_coupon' => $subtotalAfterCoupon,
            'tax_amount' => $taxAmount,
            'shipping_cost' => $shippingCost,
            'shipping_discount' => $shippingDiscount,
            'grand_total' => $total,
            'categorized_items' => $categorizedItems
        ];
    }
    
    /**
     * Calculate tax dynamically based on shipping address and Bagisto tax configuration
     */
    private function calculateTax($subtotal, $shippingAddress = [])
    {
        // If no shipping address provided, return 0 tax
        if (empty($shippingAddress) || empty($shippingAddress['ship_state'])) {
            \Log::info('No tax calculated - shipping address or state not provided');
            return 0;
        }
        
        // Create an address object for tax calculation
        $address = new class {
            public $country;
            public $state;
            public $postcode;
        };
        
        $address->country = 'US'; // Assuming US for now
        $address->state = $shippingAddress['ship_state'] ?? '';
        $address->postcode = $shippingAddress['ship_zip'] ?? '';
        
        // Get the first available tax category (you can make this configurable)
        $taxCategory = $this->taxCategoryRepository->first();
        
        if (!$taxCategory) {
            \Log::info('No tax categories found, applying 0% tax');
            return 0;
        }
        
        $taxAmount = 0;
        
        // Use Bagisto's tax calculation system
        Tax::isTaxApplicableInCurrentAddress($taxCategory, $address, function ($taxRate) use ($subtotal, &$taxAmount) {
            $rate = $taxRate->tax_rate / 100; // Convert percentage to decimal
            $taxAmount = $subtotal * $rate;
            
            \Log::info('Dynamic tax calculated', [
                'tax_rate_id' => $taxRate->id,
                'tax_rate_percent' => $taxRate->tax_rate,
                'subtotal' => $subtotal,
                'calculated_tax' => $taxAmount,
                'address_state' => $taxRate->state,
                'address_country' => $taxRate->country
            ]);
        });
        
        return $taxAmount;
    }
    
    /**
     * Get estimated delivery days for a service code
     */
    private function getEstimatedDeliveryDays($serviceCode)
    {
        $deliveryMap = [
            'ups_ground' => '3-5',
            'ups_3_day_select' => '3',
            'ups_2nd_day_air' => '2',
            'ups_next_day_air' => '1',
            'fedex_ground' => '3-5',
            'fedex_2_day' => '2',
            'fedex_express_saver' => '3',
            'fedex_standard_overnight' => '1',
            'usps_priority_mail' => '2-3',
            'usps_ground_advantage' => '3-5',
            'usps_priority_mail_express' => '1-2',
            'dhl_express' => '2-3',
            'dhl_express_worldwide' => '1-3',
            'globalpost_standard' => '7-14',
            'seko_ltl' => '5-7'
        ];
        
        return $deliveryMap[strtolower($serviceCode)] ?? '3-7';
    }
    
    /**
     * Create order in ShipStation for in-house items
     */
    private function createShipStationOrder($items, $shippingData, $paymentIntentId)
    {
        $selectedShippingMethod = session('selected_shipping_method');
        $billingData = session('checkout_billing');
        $flatRateSettings = \App\Models\FreeShippingSetting::current();

        try {
            if ($flatRateSettings->flat_rate_enabled) {
                $shippingMethodName = 'Standard Shipping';
                $shippingRate = $flatRateSettings->flat_rate_amount;
            } else {
                $shippingMethodName = $selectedShippingMethod['name'] ?? 'Ground';
                $shippingRate = $selectedShippingMethod['rate'] ?? 9.99;
            }

            // Prepare order data for ShipStation
            $orderData = [
                'order_id' => 'MADD-' . time() . '-' . rand(1000, 9999),
                'customer_name' => $shippingData['ship_name'] ?? '',
                'customer_email' => $shippingData['email'] ?? '',
                'company' => $shippingData['company'] ?? '',
                'phone' => $shippingData['ship_phone'] ?? '',
                'shipping_address1' => $shippingData['ship_address1'] ?? '',
                'shipping_address2' => $shippingData['ship_address2'] ?? '',
                'shipping_address3' => $shippingData['ship_address3'] ?? '',
                'shipping_city' => $shippingData['ship_city'] ?? '',
                'shipping_state' => $shippingData['ship_state'] ?? '',
                'shipping_zip' => $shippingData['ship_zip'] ?? '',
                'billing_address1' => ($billingData['billing_address1'] ?? $shippingData['ship_address1']) ?? '',
                'billing_address2' => ($billingData['billing_address2'] ?? $shippingData['ship_address2']) ?? '',
                'billing_city' => ($billingData['billing_city'] ?? $shippingData['ship_city']) ?? '',
                'billing_state' => ($billingData['billing_state'] ?? $shippingData['ship_state']) ?? '',
                'billing_zip' => ($billingData['billing_zip'] ?? $shippingData['ship_zip']) ?? '',
                'payment_method' => 'Stripe Credit Card',
                'shipping_method' => $shippingMethodName,
                'customer_notes' => implode(' ', array_filter([
                    $shippingData['comment1'] ?? '',
                    $shippingData['comment2'] ?? ''
                ])),
                'items' => []
            ];

            // Calculate totals
            $totalAmount = 0;
            $taxAmount = 0;
            $shippingAmount = $shippingRate;
            
            // Add items to order
            foreach ($items as $item) {
                // Handle ARI Partstream items differently (no product_id lookup needed)
                if ($item['is_ari_partstream'] ?? false) {
                    $itemPrice = $item['price'] ?? 0;
                    $itemQuantity = $item['quantity'] ?? 1;
                    $itemSubtotal = $itemPrice * $itemQuantity;
                    $totalAmount += $itemSubtotal;
                } else {
                    // Regular Bagisto products
                    $product = $this->productRepository->find($item['product_id']);
                    
                    $itemPrice = $item['price'] ?? 0;
                    $itemQuantity = $item['quantity'] ?? 1;
                    $itemSubtotal = $itemPrice * $itemQuantity;
                    
                    $totalAmount += $itemSubtotal;
                }
            }
            
            // Calculate tax dynamically for the entire order
            $taxAmount = $this->calculateTax($totalAmount, $shippingData);
            
            // Recalculate items with proportional tax
            foreach ($items as $item) {
                $itemPrice = $item['price'] ?? 0;
                $itemQuantity = $item['quantity'] ?? 1;
                $itemSubtotal = $itemPrice * $itemQuantity;
                $itemTax = $totalAmount > 0 ? ($itemSubtotal / $totalAmount) * $taxAmount : 0;
                
                if ($item['is_ari_partstream'] ?? false) {
                    // ARI Partstream item - use data from ARI API
                    $orderData['items'][] = [
                        'id' => $item['id'] ?? uniqid(),
                        'product_id' => null, // No Bagisto product ID for ARI items
                        'sku' => $item['sku'],
                        'name' => $item['name'] ?? $item['sku'],
                        'quantity' => $itemQuantity,
                        'unit_price' => $itemPrice,
                        'tax_amount' => $itemTax,
                        'weight' => 1.0, // Default weight for ARI items
                        'upc' => '',
                        'image_url' => $item['image_url'] ?? '',
                        'warehouse_location' => 'MAIN', // Default warehouse location
                        'brand' => $item['brand'] ?? null,
                        'source' => 'ari_partstream' // Flag for tracking
                    ];
                } else {
                    // Regular Bagisto product
                    $product = $this->productRepository->find($item['product_id']);
                    
                    $orderData['items'][] = [
                        'id' => $item['id'] ?? uniqid(),
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'quantity' => $itemQuantity,
                        'unit_price' => $itemPrice,
                        'tax_amount' => $itemTax,
                        'weight' => $product->weight ?? 1.0,
                        'upc' => $product->upc ?? '',
                        'image_url' => $product->image_url ?? '',
                        'warehouse_location' => 'MAIN', // Default warehouse location
                        'source' => 'bagisto_catalog' // Flag for tracking
                    ];
                }
            }
            
            $orderData['total_amount'] = $totalAmount + $taxAmount + $shippingAmount;
            $orderData['tax_amount'] = $taxAmount;
            $orderData['shipping_amount'] = $shippingAmount;
            
            // Create order in ShipStation
            $result = $this->shipStationService->createOrder($orderData);
            
            return $result;
            
        } catch (\Exception $e) {
            \Log::channel('shipstation')->error('ShipStation Order Creation Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'items' => $items,
                'shipping_data' => $shippingData
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function verifyDropshipperPrices($cartItems)
    {
        $priceChanges = [];

        foreach ($cartItems as $item) {
            $selectedSupplier = $item['selected_supplier'] ?? 'ari_stock';

            if ($selectedSupplier === 'ari_stock') {
                continue;
            }

            $service = match($selectedSupplier) {
                'wps' => $this->wpsDropshipService,
                'parts_unlimited' => $this->partsUnlimitedDropshipService,
                'turn14' => app(\App\Services\Dropship\Turn14DropshipService::class),
                'helmet_house' => $this->helmetHouseDropshipService,
                default => null
            };

            if (!$service) {
                continue;
            }

            try {
                $realTimeData = $service->checkAvailability($item['sku']);

                if ($realTimeData && $realTimeData['available']) {
                    $cachedPrice = $item['suppliers'][$selectedSupplier]['price'] ?? 0;
                    $realTimePrice = $realTimeData['price'] ?? 0;

                    if (abs($realTimePrice - $cachedPrice) > 0.50) {
                        $priceChanges[] = "{$item['name']}: ${$cachedPrice} -> ${$realTimePrice}";

                        \DB::table('supplier_cache')->updateOrInsert(
                            ['sku' => $item['sku'], 'supplier' => $selectedSupplier],
                            [
                                'is_available' => true,
                                'price' => $realTimePrice,
                                'inventory' => $realTimeData['inventory'] ?? 0,
                        'dropshipper_item_id' => $realTimeData['wps_item_id'] ?? $realTimeData['parts_unlimited_sku'] ?? $realTimeData['turn14_item_id'] ?? $realTimeData['helmet_house_sku'] ?? null,
                                'cached_at' => now(),
                                'expires_at' => now()->addHours(24)
                            ]
                        );
                    }
                } elseif ($realTimeData && !$realTimeData['available']) {
                    $priceChanges[] = "{$item['name']} is no longer available from {$selectedSupplier}";
                }
            } catch (\Exception $e) {
                \Log::error("Price verification failed for {$selectedSupplier}:{$item['sku']}", ['error' => $e->getMessage()]);
            }
        }

        return $priceChanges;
    }

    private function saveFulfillmentDetails($orderId, $supplierOrders, $ordersBySupplier)
    {
        foreach ($supplierOrders as $supplier => $result) {
            $items = $ordersBySupplier[$supplier] ?? [];

            foreach ($items as $item) {
                $fulfillmentData = [
                    'order_id' => $orderId,
                    'order_item_id' => null,
                    'item_sku' => $item['sku'] ?? null,
                    'supplier' => $supplier,
                    'fulfillment_type' => $this->getFulfillmentType($supplier),
                    'status' => $result['success'] ? 'success' : 'failed',
                    'request_data' => json_encode([
                        'sku' => $item['sku'] ?? null,
                        'name' => $item['name'] ?? null,
                        'quantity' => $item['quantity'] ?? null,
                        'price' => $item['price'] ?? null,
                        'is_ari_partstream' => $item['is_ari_partstream'] ?? false,
                    ]),
                    'response_data' => json_encode($result),
                    'error_message' => !$result['success'] ? ($result['error'] ?? 'Unknown error') : null,
                    'external_order_id' => $result['order_number'] ?? $result['order_id'] ?? null,
                    'external_po_number' => $result['po_number'] ?? null,
                    'tracking_number' => $result['tracking_number'] ?? null,
                    'item_price' => $item['price'] ?? null,
                    'item_quantity' => $item['quantity'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                try {
                    \DB::table('order_fulfillment_details')->insert($fulfillmentData);
                } catch (\Exception $e) {
                    $this->safeLog('error', 'Failed to save fulfillment details', [
                        'order_id' => $orderId,
                        'supplier' => $supplier,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }
    }

    private function getFulfillmentType($supplier)
    {
        $types = [
            'wps' => 'Dropshipper - WPS',
            'parts_unlimited' => 'Dropshipper - Parts Unlimited',
            'turn14' => 'Dropshipper - Turn14',
            'helmet_house' => 'Dropshipper - Helmet House',
            'ari_stock' => 'In-House - ShipStation',
        ];

        return $types[$supplier] ?? 'Unknown';
    }

    private function calculateTurn14Shipping($cartItems, $ariItems, $shippingAddress)
    {
        $turn14Items = [];

        foreach ($cartItems as $item) {
            $supplier = $item['selected_supplier'] ?? 'ari_stock';
            if ($supplier === 'turn14') {
                $turn14Items[] = [
                    'sku' => $item['sku'] ?? null,
                    'turn14_item_id' => $item['turn14_item_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'name' => $item['name'] ?? 'Unknown'
                ];
            }
        }

        foreach ($ariItems as $item) {
            $supplier = $item['selected_supplier'] ?? 'ari_stock';
            if ($supplier === 'turn14') {
                $turn14Items[] = [
                    'sku' => $item['sku'] ?? null,
                    'turn14_item_id' => $item['turn14_item_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'name' => $item['name'] ?? 'Unknown'
                ];
            }
        }

        if (empty($turn14Items)) {
            return 0;
        }

        if (method_exists($this->turn14DropshipService, 'getShippingQuote')) {
            $quoteResult = $this->turn14DropshipService->getShippingQuote($turn14Items, $shippingAddress);

            if ($quoteResult['success'] ?? false) {
                $rate = $quoteResult['rate'] ?? 0;

                \Log::info('Turn14 shipping calculated at checkout', [
                    'rate' => $rate,
                    'items_count' => count($turn14Items),
                    'quote_id' => $quoteResult['quote_id'] ?? null
                ]);

                return $rate;
            } else {
                \Log::warning('Turn14 shipping quote failed', [
                    'error' => $quoteResult['error'] ?? 'Unknown error',
                    'items_count' => count($turn14Items)
                ]);
            }
        }

        return 0;
    }
}
