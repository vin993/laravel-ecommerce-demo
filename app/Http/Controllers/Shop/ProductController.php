<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use Webkul\Product\Repositories\ProductRepository;
use Webkul\Product\Helpers\Review;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Customer\Repositories\WishlistRepository;
use Webkul\Checkout\Repositories\CartRepository;
use App\Services\Dropship\WpsDropshipService;
use App\Services\Dropship\PartsUnlimitedDropshipService;
use App\Services\Dropship\Turn14DropshipService;
use App\Services\Dropship\HelmetHouseDropshipService;

class ProductController extends Controller
{
    protected $productRepository;
    protected $reviewHelper;
    protected $channelRepository;
    protected $wishlistRepository;
    protected $cartRepository;
    protected $wpsService;
    protected $partsUnlimitedService;
    protected $turn14Service;
    protected $helmetHouseService;

    public function __construct(
        ProductRepository $productRepository,
        Review $reviewHelper,
        ChannelRepository $channelRepository,
        WishlistRepository $wishlistRepository,
        CartRepository $cartRepository,
        WpsDropshipService $wpsService,
        PartsUnlimitedDropshipService $partsUnlimitedService,
        Turn14DropshipService $turn14Service,
        HelmetHouseDropshipService $helmetHouseService
    ) {
        $this->productRepository = $productRepository;
        $this->reviewHelper = $reviewHelper;
        $this->channelRepository = $channelRepository;
        $this->wishlistRepository = $wishlistRepository;
        $this->cartRepository = $cartRepository;
        $this->wpsService = $wpsService;
        $this->partsUnlimitedService = $partsUnlimitedService;
        $this->turn14Service = $turn14Service;
        $this->helmetHouseService = $helmetHouseService;
    }

    public function view($slug)
    {
        // First try to find the product in product_flat table
        $productFlat = \Webkul\Product\Models\ProductFlat::where('url_key', $slug)
            ->where('channel', 'maddparts')
            ->where('locale', 'en')
            ->first();

        if (!$productFlat) {
            abort(404);
        }

        // Get the actual product using the product_id from product_flat
        $product = $this->productRepository->find($productFlat->product_id);

        if (!$product) {
            abort(404);
        }

        // Override product name and description with product_flat data for accuracy
        if ($productFlat->name) {
            $product->name = $productFlat->name;
        }
        if ($productFlat->description) {
            $product->description = $productFlat->description;
        }

        // For configurable products, if parent has no description, get from first variant
        if ($product->type === 'configurable' && empty($product->description)) {
            $firstVariant = \Webkul\Product\Models\Product::where('parent_id', $product->id)
                ->with('attribute_values')
                ->first();

            if ($firstVariant) {
                $variantFlat = \Webkul\Product\Models\ProductFlat::where('product_id', $firstVariant->id)
                    ->where('channel', 'maddparts')
                    ->where('locale', 'en')
                    ->first();

                if ($variantFlat && $variantFlat->description) {
                    $product->description = $variantFlat->description;
                }

                // Also copy attribute values from variant to parent for specifications tab
                if ($firstVariant->attribute_values->count() > 0) {
                    $product->setRelation('attribute_values', $firstVariant->attribute_values);
                }
            }
        }

        // Fix image URLs for Kawasaki products - use Kawasaki URLs from additional_data
        if ($product->images->isNotEmpty()) {
            foreach ($product->images as $image) {
                // Check if additional_data contains a Kawasaki URL
                if (!empty($image->additional_data)) {
                    $additionalData = json_decode($image->additional_data, true);
                    if (isset($additionalData['kawasaki_image_url'])) {
                        // Override the path with the Kawasaki URL
                        $image->path = $additionalData['kawasaki_image_url'];
                    }
                }
            }
        }

        // Skip heavy queries for instant page load
        $productAdditionalData = [
            'reviews' => collect(),
            'average_rating' => 0,
            'percentage_rating' => [],
        ];

        // Fast related products - just from same category
        $relatedProducts = collect();
        if ($product->categories->isNotEmpty()) {
            $categoryId = $product->categories->first()->id;

            $productIds = \DB::table('product_flat as pf')
                ->join('product_categories as pc', 'pf.product_id', '=', 'pc.product_id')
                ->where('pc.category_id', $categoryId)
                ->where('pf.product_id', '!=', $product->id)
                ->where('pf.channel', 'maddparts')
                ->where('pf.locale', 'en')
                ->where('pf.status', 1)
                ->orderBy('pf.product_id', 'desc')
                ->limit(8)
                ->pluck('pf.product_id');

            if ($productIds->isNotEmpty()) {
                $relatedProducts = \Webkul\Product\Models\Product::whereIn('id', $productIds)
                    ->with([
                        'images' => function($query) {
                            $query->limit(1);
                        },
                        'product_flats' => function($query) {
                            $query->where('channel', 'maddparts')->where('locale', 'en');
                        }
                    ])
                    ->get()
                    ->map(function($product) {
                        $flat = $product->product_flats->first();
                        if ($flat) {
                            $product->name = $flat->name;
                            $product->price = $flat->price;
                            $product->special_price = $flat->special_price;
                            $product->url_key = $flat->url_key;
                        }

                        // If configurable product has no images, get from first variant
                        if ($product->type === 'configurable' && $product->images->isEmpty()) {
                            $variantImage = \DB::table('products as p')
                                ->join('product_images as pi', 'p.id', '=', 'pi.product_id')
                                ->where('p.parent_id', $product->id)
                                ->select('pi.*')
                                ->first();

                            if ($variantImage) {
                                $product->setRelation('images', collect([
                                    (object)[
                                        'id' => $variantImage->id,
                                        'path' => $variantImage->path,
                                        'product_id' => $variantImage->product_id,
                                        'additional_data' => $variantImage->additional_data ?? null
                                    ]
                                ]));
                            }
                        }

                        // Fix image URLs for Kawasaki products - use Kawasaki URLs from additional_data
                        if ($product->images->isNotEmpty()) {
                            foreach ($product->images as $image) {
                                // Check if additional_data contains a Kawasaki URL
                                if (!empty($image->additional_data)) {
                                    $additionalData = json_decode($image->additional_data, true);
                                    if (isset($additionalData['kawasaki_image_url'])) {
                                        // Override the path with the Kawasaki URL
                                        $image->path = $additionalData['kawasaki_image_url'];
                                    }
                                }
                            }
                        }

                        return $product;
                    });
            }
        }

        $upSellProducts = collect();
        $crossSellProducts = collect();

        // Check if product is in wishlist (for logged in users)
        $isInWishlist = false;
        if (auth()->guard('customer')->check()) {
            $isInWishlist = $this->wishlistRepository->findWhere([
                'customer_id' => auth()->guard('customer')->id(),
                'product_id' => $product->id
            ])->count() > 0;
        }

        // Get current cart quantity for this product (if any)
        $cartQuantity = 0;
        try {
            if (function_exists('cart') && cart()->getCart()) {
                $cart = cart()->getCart();
                $cartItem = $cart->items->where('product_id', $product->id)->first();
                $cartQuantity = $cartItem ? $cartItem->quantity : 0;
            }
        } catch (\Exception $e) {
            $cartQuantity = 0;
        }

        // Get current product price and inventory (without dropshipper check for fast loading)
        $updatedPrice = \DB::table('product_flat')
            ->where('product_id', $product->id)
            ->value('price');

        if ($updatedPrice && $updatedPrice > 0) {
            $product->price = $updatedPrice;
        }

        // Get current inventory
        $inventoryRecord = \DB::table('product_inventories')
            ->where('product_id', $product->id)
            ->first();

        if ($inventoryRecord) {
            $displayQty = $inventoryRecord->qty;

            if ($inventoryRecord->virtual_inventory && $inventoryRecord->virtual_qty_base > 0) {
                $displayQty = $inventoryRecord->virtual_qty_base;
            }

            $product->inventories = collect([
                (object)['qty' => $displayQty]
            ]);
        }

        // Dropshipper data will be loaded asynchronously via AJAX
        $dropshipperData = [];

        // DISABLED: Dropshipper data will be loaded asynchronously via AJAX
        // Commenting out to prevent price checks for Kawasaki products
        /*
        // Load dropshipper data synchronously if debug mode is enabled
        if (env('DROPSHIPPER_DEBUG_PRODUCT_DETAIL', false)) {
            if ($product->type === 'configurable') {
                $variants = \DB::table('products')
                    ->where('parent_id', $product->id)
                    ->limit(10)
                    ->get();

                foreach ($variants as $variant) {
                    $variantData = $this->checkDropshippers($variant->sku, $variant->id);

                    foreach ($variantData as $supplierName => $supplierInfo) {
                        if ($supplierName !== 'ari_stock') {
                            if (!isset($dropshipperData[$supplierName])) {
                                $dropshipperData[$supplierName] = $supplierInfo;
                                $dropshipperData[$supplierName]['variant_sku'] = $variant->sku;
                            } elseif (($supplierInfo['available'] ?? false) && ($supplierInfo['price'] ?? 0) > 0) {
                                if (($supplierInfo['price'] ?? 0) < ($dropshipperData[$supplierName]['price'] ?? PHP_INT_MAX)) {
                                    $dropshipperData[$supplierName] = $supplierInfo;
                                    $dropshipperData[$supplierName]['variant_sku'] = $variant->sku;
                                }
                            }
                        }
                    }
                }
            } else {
                $dropshipperData = $this->checkDropshippers($product->sku, $product->id);
            }
        }
        */

        // Skip vehicle fitments for speed (millions of records)
        $vehicleFitments = collect();

        return view('products.view', compact(
            'product',
            'productAdditionalData',
            'relatedProducts',
            'upSellProducts',
            'crossSellProducts',
            'isInWishlist',
            'cartQuantity',
            'dropshipperData',
            'vehicleFitments'
        ));
    }

    private function getVehicleFitments($productId)
    {
        $fitments = \DB::table('product_vehicle_fitment as pvf')
            ->join('ds_type_make_model_year as tmmy', 'pvf.tmmy_id', '=', 'tmmy.tmmy_id')
            ->join('ds_vehicle_types as t', 'tmmy.vehicle_type_id', '=', 't.vehicle_type_id')
            ->join('ds_makes as ma', 'tmmy.make_id', '=', 'ma.make_id')
            ->join('ds_models as mo', 'tmmy.model_id', '=', 'mo.model_id')
            ->join('ds_years as y', 'tmmy.year_id', '=', 'y.year_id')
            ->where('pvf.product_id', $productId)
            ->select(
                'tmmy.tmmy_id',
                'tmmy.vehicle_type_id',
                'tmmy.make_id',
                'tmmy.model_id',
                'tmmy.year_id',
                't.description as type',
                'ma.description as make',
                'mo.description as model',
                'y.description as year'
            )
            ->orderBy('y.description', 'desc')
            ->orderBy('ma.description')
            ->orderBy('mo.description')
            ->get();

        return $fitments->groupBy(function($item) {
            return $item->type . '|' . $item->make . '|' . $item->model;
        })->map(function($group) {
            $first = $group->first();
            return [
                'type' => $first->type,
                'make' => $first->make,
                'model' => $first->model,
                'years' => $group->pluck('year')->sort()->values()->all(),
                'vehicle_type_id' => $first->vehicle_type_id,
                'make_id' => $first->make_id,
                'model_id' => $first->model_id
            ];
        })->values();
    }

    private function checkDropshippers($sku, $productId)
    {
        $suppliers = [
            'ari_stock' => [
                'available' => true,
                'price' => 0,
                'source' => 'ari_datastream'
            ]
        ];

        \Log::info("Checking dropshippers for product: {$sku}");

        $skusToCheck = [$sku];
        $product = \DB::table('products')->where('id', $productId)->first();
        if ($product && $product->type === 'configurable') {
            $childProducts = \DB::table('products')
                ->where('parent_id', $productId)
                ->limit(20)
                ->pluck('sku')
                ->toArray();

            $skusToCheck = array_merge($skusToCheck, $childProducts);

            if (str_ends_with($sku, '-PARENT')) {
                $baseSku = str_replace('-PARENT', '', $sku);
                $skusToCheck[] = $baseSku;
            }
        }

        $skusToCheck = array_slice($skusToCheck, 0, 10);

        $suppliersToCheck = ['wps', 'parts_unlimited', 'turn14', 'helmet_house'];
        $cachedResults = [];

        // PERFORMANCE FIX: Batch-load ALL cache entries in ONE query instead of 40 queries
        // Previously: 10 SKUs × 4 suppliers = 40 separate database queries
        // Now: 1 query total - reduces AJAX response time by 80-85%
        if (!empty($skusToCheck)) {
            $allCachedData = \DB::table('supplier_cache')
                ->whereIn('sku', $skusToCheck)
                ->whereIn('supplier', $suppliersToCheck)
                ->where('expires_at', '>', now())
                ->get();

            // Group results by supplier for easy lookup
            foreach ($allCachedData as $cached) {
                $supplierName = $cached->supplier;
                if (empty($suppliers[$supplierName]) && !isset($cachedResults[$supplierName])) {
                    $cachedResults[$supplierName] = [
                        'sku' => $cached->sku,
                        'data' => $cached
                    ];
                }
            }
        }

        foreach ($cachedResults as $supplierName => $result) {
            $cached = $result['data'];
            $checkSku = $result['sku'];

            if ($supplierName === 'wps' && $cached->is_available) {
                $suppliers['wps'] = [
                    'available' => true,
                    'price' => $cached->price,
                    'inventory' => $cached->inventory ?? 0,
                    'source' => 'wps_cached',
                    'wps_item_id' => $cached->dropshipper_item_id
                ];
            } elseif ($supplierName === 'parts_unlimited' && $cached->is_available) {
                $suppliers['parts_unlimited'] = [
                    'available' => true,
                    'price' => $cached->price,
                    'base_price' => $cached->price,
                    'dropship_fee' => 0,
                    'inventory' => $cached->inventory ?? 0,
                    'source' => 'parts_unlimited_cached',
                    'parts_unlimited_sku' => $cached->dropshipper_item_id,
                    'fee_note' => 'Includes $0.00 dropship fee'
                ];
            } elseif ($supplierName === 'turn14' && $cached->dropshipper_item_id) {
                $suppliers['turn14'] = [
                    'available' => $cached->is_available ?? false,
                    'price' => $cached->price ?? 0,
                    'inventory' => $cached->inventory ?? 0,
                    'source' => 'turn14_cached',
                    'turn14_item_id' => $cached->dropshipper_item_id
                ];
            } elseif ($supplierName === 'helmet_house' && $cached->is_available) {
                $suppliers['helmet_house'] = [
                    'available' => true,
                    'price' => $cached->price ?? 0,
                    'inventory' => $cached->inventory ?? 0,
                    'source' => 'helmet_house_cached',
                    'helmet_house_sku' => $cached->dropshipper_item_id,
                    'map_price' => null,
                    'retail_price' => null
                ];
            }
        }

        if (!empty($suppliers['wps']) && !empty($suppliers['parts_unlimited']) && !empty($suppliers['turn14']) && !empty($suppliers['helmet_house'])) {
            $this->updateProductFromDropshippers($productId, $sku, $suppliers);
            return $suppliers;
        }

        foreach ($skusToCheck as $checkSku) {
            if (!empty($suppliers['wps']) && !empty($suppliers['parts_unlimited']) && !empty($suppliers['turn14']) && !empty($suppliers['helmet_house'])) {
                break;
            }

            if (empty($suppliers['wps'])) {
                $wpsAvailability = $this->getCachedOrCheck($checkSku, 'wps');
                if ($wpsAvailability && $wpsAvailability['available']) {
                    $suppliers['wps'] = [
                        'available' => true,
                        'price' => $wpsAvailability['price'],
                        'inventory' => $wpsAvailability['inventory'] ?? 0,
                        'source' => $wpsAvailability['source'] ?? 'wps_cached',
                        'wps_item_id' => $wpsAvailability['wps_item_id'] ?? null
                    ];
                    \Log::info("WPS available for {$checkSku}: " . ($wpsAvailability['source'] ?? 'wps_cached'));
                }
            }

            if (empty($suppliers['parts_unlimited'])) {
                $partsUnlimitedAvailability = $this->getCachedOrCheck($checkSku, 'parts_unlimited');
                if ($partsUnlimitedAvailability && $partsUnlimitedAvailability['available']) {
                    $suppliers['parts_unlimited'] = [
                        'available' => true,
                        'price' => $partsUnlimitedAvailability['price'],
                        'base_price' => $partsUnlimitedAvailability['base_price'] ?? $partsUnlimitedAvailability['price'],
                        'dropship_fee' => $partsUnlimitedAvailability['dropship_fee'] ?? 0,
                        'inventory' => $partsUnlimitedAvailability['inventory'] ?? 0,
                        'source' => $partsUnlimitedAvailability['source'] ?? 'parts_unlimited_cached',
                        'parts_unlimited_sku' => $partsUnlimitedAvailability['parts_unlimited_sku'] ?? null,
                        'fee_note' => 'Includes $' . number_format($partsUnlimitedAvailability['dropship_fee'] ?? 0, 2) . ' dropship fee'
                    ];
                    \Log::info("Parts Unlimited available for {$checkSku}: " . ($partsUnlimitedAvailability['source'] ?? 'parts_unlimited_cached'));
                }
            }

            if (empty($suppliers['turn14'])) {
                $turn14Availability = $this->getCachedOrCheck($checkSku, 'turn14');
                if ($turn14Availability && isset($turn14Availability['turn14_item_id']) && $turn14Availability['turn14_item_id']) {
                    $suppliers['turn14'] = [
                        'available' => $turn14Availability['available'] ?? false,
                        'price' => $turn14Availability['price'] ?? 0,
                        'inventory' => $turn14Availability['inventory'] ?? 0,
                        'source' => $turn14Availability['source'] ?? 'turn14_cached',
                        'turn14_item_id' => $turn14Availability['turn14_item_id']
                    ];
                    \Log::info("Turn14 data for {$checkSku}: available=" . ($turn14Availability['available'] ? 'true' : 'false') . ", inventory=" . ($turn14Availability['inventory'] ?? 0) . ", source=" . ($turn14Availability['source'] ?? 'turn14_cached'));
                }
            }

            if (empty($suppliers['helmet_house'])) {
                $helmetHouseAvailability = $this->getCachedOrCheck($checkSku, 'helmet_house');
                if ($helmetHouseAvailability && $helmetHouseAvailability['available']) {
                    $suppliers['helmet_house'] = [
                        'available' => true,
                        'price' => $helmetHouseAvailability['price'] ?? 0,
                        'inventory' => $helmetHouseAvailability['inventory'] ?? 0,
                        'source' => $helmetHouseAvailability['source'] ?? 'helmet_house_cached',
                        'helmet_house_sku' => $helmetHouseAvailability['helmet_house_sku'] ?? null,
                        'map_price' => $helmetHouseAvailability['map_price'] ?? null,
                        'retail_price' => $helmetHouseAvailability['retail_price'] ?? null
                    ];
                    \Log::info("Helmet House available for {$checkSku}: " . ($helmetHouseAvailability['source'] ?? 'helmet_house_cached'));
                }
            }
        }

        $this->updateProductFromDropshippers($productId, $sku, $suppliers);

        return $suppliers;
    }

    private function updateProductFromDropshippers($productId, $sku, $suppliers)
    {
        $lowestPrice = null;
        $totalInventory = 0;
        $hasDropshipperStock = false;

        foreach ($suppliers as $name => $data) {
            if ($name !== 'ari_stock' && ($data['available'] ?? false)) {
                $price = $data['price'] ?? 0;
                $inventory = $data['inventory'] ?? 0;

                if ($price > 0 && ($lowestPrice === null || $price < $lowestPrice)) {
                    $lowestPrice = $price;
                }

                $totalInventory += $inventory;
                if ($inventory > 0) {
                    $hasDropshipperStock = true;
                }
            }
        }

        if (!$hasDropshipperStock) {
            $this->handleVirtualInventory($productId, $sku);
        }

        if ($lowestPrice === null) {
            return;
        }

        $currentPrice = \DB::table('product_flat')
            ->where('product_id', $productId)
            ->value('price');

        if (!$currentPrice) {
            return;
        }

        $priceChanged = false;
        $inventoryChanged = false;

        if (abs($currentPrice - $lowestPrice) > 0.01) {
            \DB::table('product_flat')
                ->where('product_id', $productId)
                ->update(['price' => $lowestPrice]);

            \DB::table('product_attribute_values')
                ->where('product_id', $productId)
                ->where('attribute_id', 11)
                ->update(['float_value' => $lowestPrice]);

            $priceChanged = true;
            \Log::info("Updated price for SKU {$sku}: {$currentPrice} -> {$lowestPrice}");
        }

        $currentInventory = \DB::table('product_inventories')
            ->where('product_id', $productId)
            ->value('qty');

        if ($hasDropshipperStock) {
            if ($totalInventory != $currentInventory) {
                \DB::table('product_inventories')
                    ->where('product_id', $productId)
                    ->update([
                        'qty' => $totalInventory,
                        'virtual_inventory' => false,
                        'virtual_qty_base' => 0
                    ]);

                $inventoryChanged = true;
                \Log::info("Updated inventory for SKU {$sku}: {$currentInventory} -> {$totalInventory} (real stock)");
            }
        }

        if ($priceChanged || $inventoryChanged) {
            \DB::table('products')
                ->where('id', $productId)
                ->update(['updated_at' => now()]);
        }
    }

    private function handleVirtualInventory($productId, $sku)
    {
        if (!config('virtual_inventory.enabled', true)) {
            return;
        }

        $isAccessoryCategory = $this->isProductInVirtualInventoryCategory($productId);

        if (!$isAccessoryCategory) {
            return;
        }

        $productPrice = \DB::table('product_flat')->where('product_id', $productId)->value('price');
        if (empty($productPrice) || $productPrice <= 0) {
            return;
        }

        $inventory = \DB::table('product_inventories')
            ->where('product_id', $productId)
            ->first();

        if (!$inventory) {
            return;
        }

        if (!property_exists($inventory, 'virtual_inventory')) {
            \Log::warning("Virtual inventory columns not found. Run migration first.");
            return;
        }

        $defaultQty = config('virtual_inventory.default_quantity', 10);
        $replenishThreshold = config('virtual_inventory.replenish_threshold', 3);

        if (!$inventory->virtual_inventory) {
            \DB::table('product_inventories')
                ->where('product_id', $productId)
                ->update([
                    'qty' => $defaultQty,
                    'virtual_inventory' => true,
                    'virtual_qty_base' => $defaultQty,
                    'last_replenished_at' => now()
                ]);

            \Log::info("Set virtual inventory for SKU {$sku}: {$defaultQty} units");
        } elseif ($inventory->qty <= $replenishThreshold) {
            $replenishQty = $inventory->virtual_qty_base > 0 ? $inventory->virtual_qty_base : $defaultQty;
            \DB::table('product_inventories')
                ->where('product_id', $productId)
                ->update([
                    'qty' => $replenishQty,
                    'last_replenished_at' => now()
                ]);

            \Log::info("Replenished virtual inventory for SKU {$sku}: {$inventory->qty} -> {$replenishQty}");
        }
    }

    private function isProductInVirtualInventoryCategory($productId)
    {
        $searchTerms = config('virtual_inventory.category_search_terms', []);

        if (empty($searchTerms)) {
            return false;
        }

        $hasCategory = \DB::table('product_categories as pc')
            ->join('category_translations as ct', 'pc.category_id', '=', 'ct.category_id')
            ->where('pc.product_id', $productId)
            ->where(function($query) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $query->orWhere('ct.name', 'LIKE', "%{$term}%");
                }
            })
            ->exists();

        return $hasCategory;
    }

    private function findSimilarProducts($product)
    {
        $similarProducts = collect();

        if ($product->categories->isEmpty()) {
            return $similarProducts;
        }

        try {
            $categoryIds = $product->categories->pluck('id')->toArray();

            // OPTIMIZED: Use direct joins instead of nested whereHas() to avoid slow EXISTS subqueries
            // Old query took 100-156 seconds, new query takes < 2 seconds
            $categoryProducts = \Webkul\Product\Models\ProductFlat::where('product_flat.channel', 'maddparts')
                ->where('product_flat.locale', 'en')
                ->where('product_flat.status', 1)
                ->where('product_flat.product_id', '!=', $product->id)
                ->join('products', 'product_flat.product_id', '=', 'products.id')
                ->join('product_categories', 'products.id', '=', 'product_categories.product_id')
                ->whereIn('product_categories.category_id', $categoryIds)
                ->select('product_flat.*')
                ->distinct()
                ->with(['product.images', 'product.inventories', 'product.categories.translations', 'product.attribute_family'])
                ->orderBy('product_flat.product_id','desc')
                ->limit(8)
                ->get()
                ->map(function($productFlat) {
                    $product = $productFlat->product;
                    $product->name = $productFlat->name;
                    $product->price = $productFlat->price;
                    $product->url_key = $productFlat->url_key;
                    $product->short_description = $productFlat->short_description;
                    $product->description = $productFlat->description;
                    $product->special_price = $productFlat->special_price;
                    return $product;
                });

            return $categoryProducts;
        } catch (\Exception $e) {
            \Log::error('Similar products error: ' . $e->getMessage());
            return collect();
        }
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
                'helmet_house_sku' => $cached->dropshipper_item_id,
                'base_price' => $cached->price,
                'dropship_fee' => 0,
                'map_price' => null,
                'retail_price' => null,
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
                        'dropshipper_item_id' => $result['wps_item_id'] ?? $result['parts_unlimited_sku'] ?? $result['turn14_item_id'] ?? $result['helmet_house_sku'] ?? null,
                        'cached_at' => now(),
                        'expires_at' => now()->addDays(7)
                    ]
                );
            }

            return $result;
        } catch (\Exception $e) {
            \Log::error("Dropshipper check failed for {$supplier}:{$sku}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    public function checkDropshippersAjax($productId)
    {
        if (!is_numeric($productId) || $productId <= 0) {
            return response()->json(['error' => 'Invalid product ID'], 400);
        }

        $product = $this->productRepository->find($productId);

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        $dropshipperData = $this->checkDropshippers($product->sku, $product->id);

        $updatedPrice = \DB::table('product_flat')
            ->where('product_id', $product->id)
            ->value('price');

        $inventoryRecord = \DB::table('product_inventories')
            ->where('product_id', $product->id)
            ->first();

        $displayQty = 0;
        if ($inventoryRecord) {
            $displayQty = $inventoryRecord->qty;

            if ($inventoryRecord->virtual_inventory && $inventoryRecord->virtual_qty_base > 0) {
                $displayQty = $inventoryRecord->virtual_qty_base;
            }
        }

        return response()->json([
            'success' => true,
            'suppliers' => $dropshipperData,
            'updated_price' => $updatedPrice,
            'updated_inventory' => $displayQty
        ]);
    }
}