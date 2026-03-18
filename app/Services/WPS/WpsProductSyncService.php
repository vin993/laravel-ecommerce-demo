<?php

namespace App\Services\WPS;

use App\Models\WPS\WpsProduct;
use App\Models\WPS\WpsProductItem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class WpsProductSyncService
{
    protected $apiService;

    public function __construct(WpsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Sync all products from WPS API
     */
    public function syncAllProducts($commandOutput = null)
    {
        $startTime = microtime(true);
        Log::channel('wps')->info('Starting WPS product sync with enhanced data');
        
        if ($commandOutput) {
            $commandOutput->writeln('<info>🚀 Starting WPS Product Sync...</info>');
            $commandOutput->writeln('<comment>⏱️  Start Time: ' . now()->format('Y-m-d H:i:s') . '</comment>');
        }

        $cursor = null;
        $totalProducts = 0;
        $totalItems = 0;
        $itemsWithDimensions = 0;
        $errorCount = 0;
        $pageCount = 0;

        do {
            $pageCount++;
            $pageStartTime = microtime(true);
            
            if ($commandOutput) {
                $commandOutput->writeln('<comment>📄 Processing page ' . $pageCount . '...</comment>');
            }
            
            $response = $this->apiService->getProducts($cursor);

            if (!$response || !isset($response['data'])) {
                $error = 'Failed to fetch products from WPS API';
                Log::channel('wps')->error($error, ['cursor' => $cursor]);
                if ($commandOutput) {
                    $commandOutput->writeln('<error>❌ ' . $error . '</error>');
                }
                break;
            }

            $productsInPage = count($response['data']);
            if ($commandOutput) {
                $commandOutput->writeln('<info>📦 Found ' . $productsInPage . ' products in page ' . $pageCount . '</info>');
            }

            foreach ($response['data'] as $index => $productData) {
                try {
                    $productStartTime = microtime(true);
                    
                    if ($commandOutput) {
                        $commandOutput->writeln('<comment>🔄 Processing product ' . ($index + 1) . '/' . $productsInPage . ': ' . substr($productData['name'], 0, 50) . '...</comment>');
                    }
                    
                    $result = $this->syncSingleProduct($productData);
                    $totalItems += $result['synced_items'];
                    $itemsWithDimensions += $result['items_with_dimensions'] ?? 0;
                    $totalProducts++;
                    
                    $productTime = round((microtime(true) - $productStartTime) * 1000, 2);

                    if ($commandOutput) {
                        $commandOutput->writeln('<info>✅ Synced: ' . $productData['name'] . ' (' . $result['synced_items'] . ' items, ' . $productTime . 'ms)</info>');
                    }

                    Log::channel('wps')->info('Synced product', [
                        'product_id' => $productData['id'],
                        'name' => $productData['name'],
                        'items_count' => $result['synced_items'],
                        'items_with_dimensions' => $result['items_with_dimensions'] ?? 0,
                        'processing_time_ms' => $productTime
                    ]);
                } catch (\Exception $e) {
                    $errorCount++;
                    $errorMsg = 'Failed to sync product: ' . $productData['name'] . ' - ' . $e->getMessage();
                    
                    Log::channel('wps')->error('Failed to sync product', [
                        'product_id' => $productData['id'],
                        'product_name' => $productData['name'],
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    
                    if ($commandOutput) {
                        $commandOutput->writeln('<error>❌ ' . $errorMsg . '</error>');
                    }
                }
            }
            
            $pageTime = round((microtime(true) - $pageStartTime), 2);
            if ($commandOutput) {
                $commandOutput->writeln('<comment>⏱️  Page ' . $pageCount . ' completed in ' . $pageTime . 's</comment>');
                $commandOutput->writeln('<comment>📊 Running totals: Products=' . $totalProducts . ', Items=' . $totalItems . ', Errors=' . $errorCount . '</comment>');
                $commandOutput->writeln('');
            }

            $cursor = $response['meta']['cursor']['next'] ?? null;

        } while ($cursor);
        
        $totalTime = round((microtime(true) - $startTime), 2);
        $avgTimePerProduct = $totalProducts > 0 ? round($totalTime / $totalProducts, 3) : 0;

        $summary = [
            'total_products' => $totalProducts,
            'total_items' => $totalItems,
            'items_with_dimensions' => $itemsWithDimensions,
            'errors' => $errorCount,
            'pages_processed' => $pageCount,
            'total_time_seconds' => $totalTime,
            'avg_time_per_product' => $avgTimePerProduct
        ];
        
        Log::channel('wps')->info('WPS product sync completed', $summary);
        
        if ($commandOutput) {
            $commandOutput->writeln('');
            $commandOutput->writeln('<info>🎉 WPS Product Sync Completed!</info>');
            $commandOutput->writeln('<comment>📊 Final Statistics:</comment>');
            $commandOutput->writeln('<info>   • Products Synced: ' . $totalProducts . '</info>');
            $commandOutput->writeln('<info>   • Items Synced: ' . $totalItems . '</info>');
            $commandOutput->writeln('<info>   • Items with Dimensions: ' . $itemsWithDimensions . '</info>');
            $commandOutput->writeln('<info>   • Pages Processed: ' . $pageCount . '</info>');
            if ($errorCount > 0) {
                $commandOutput->writeln('<error>   • Errors: ' . $errorCount . '</error>');
            }
            $commandOutput->writeln('<comment>   • Total Time: ' . $totalTime . 's</comment>');
            $commandOutput->writeln('<comment>   • Avg Time per Product: ' . $avgTimePerProduct . 's</comment>');
            $commandOutput->writeln('<comment>⏱️  End Time: ' . now()->format('Y-m-d H:i:s') . '</comment>');
        }

        return [
            'products' => $totalProducts,
            'items' => $totalItems,
            'items_with_dimensions' => $itemsWithDimensions,
            'errors' => $errorCount,
            'pages_processed' => $pageCount,
            'total_time_seconds' => $totalTime
        ];
    }

    /**
     * Sync a single product and its items
     */
    public function syncSingleProduct($productData)
    {
        DB::beginTransaction();

        try {
            // Create or update WPS product record
            $wpsProduct = WpsProduct::updateOrCreate(
                ['wps_product_id' => $productData['id']],
                [
                    'name' => $productData['name'],
                    'description' => $productData['description'],
                    'status' => 'syncing'
                ]
            );

            // Get product items
            $itemsResponse = $this->apiService->getProductItems($productData['id']);

            if (!$itemsResponse || !isset($itemsResponse['data'])) {
                throw new \Exception('Failed to fetch product items');
            }

            $syncedItems = 0;
            $itemsWithDimensions = 0;
            $totalItems = count($itemsResponse['data']);

            foreach ($itemsResponse['data'] as $itemData) {
                // Only sync drop-ship eligible items
                if (!$itemData['drop_ship_eligible']) {
                    continue;
                }

                $wpsItem = $this->syncProductItem($wpsProduct, $itemData);
                if ($wpsItem) {
                    $syncedItems++;
                    
                    // Try to get enhanced data for this item
                    $this->enhanceItemData($wpsItem);
                    
                    // Check if item now has dimensions
                    if ($wpsItem->hasDimensions()) {
                        $itemsWithDimensions++;
                    }
                }
            }

            // Update product sync status
            $wpsProduct->update([
                'total_items' => $totalItems,
                'synced_items' => $syncedItems,
                'status' => 'synced',
                'last_synced_at' => now()
            ]);

            DB::commit();

            return [
                'synced_items' => $syncedItems,
                'items_with_dimensions' => $itemsWithDimensions
            ];

        } catch (\Exception $e) {
            DB::rollBack();

            if (isset($wpsProduct)) {
                $wpsProduct->markAsError($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Sync individual product item (keeping original structure)
     */
    protected function syncProductItem($wpsProduct, $itemData)
    {
        // Create or update WPS product item record with basic data
        $wpsItem = WpsProductItem::updateOrCreate(
            ['wps_item_id' => $itemData['id']],
            [
                'wps_product_id' => $wpsProduct->wps_product_id,
                'sku' => $itemData['sku'],
                'name' => $itemData['name'],
                'list_price' => $itemData['list_price'],
                'dealer_price' => $itemData['standard_dealer_price'],
                'website_price' => $itemData['website_price'] ?? null,
                'status' => $itemData['status'],
                'drop_ship_eligible' => $itemData['drop_ship_eligible'],
                'last_synced_at' => now()
            ]
        );

        return $wpsItem;
    }

    /**
     * Enhance item data with dimensions and additional information
     */
    protected function enhanceItemData($wpsItem)
    {
        try {
            // Get complete item data from multiple endpoints
            $completeData = $this->apiService->getCompleteItemData($wpsItem->wps_item_id);
            
            // Extract dimensions
            $dimensions = $this->apiService->extractDimensions($completeData);
            
            // Extract product status
            $productStatus = $this->apiService->extractProductStatus($completeData);
            
            // Prepare enhanced data
            $enhancedData = array_merge($dimensions, $productStatus, [
                'cost' => $this->extractCost($completeData),
                'special_price' => $this->extractSpecialPrice($completeData),
                'special_price_from' => $this->extractSpecialPriceFrom($completeData),
                'special_price_to' => $this->extractSpecialPriceTo($completeData),
            ]);

            // Update the item with enhanced data
            $wpsItem->update($enhancedData);

            Log::channel('wps')->debug('Enhanced item data', [
                'item_id' => $wpsItem->wps_item_id,
                'sku' => $wpsItem->sku,
                'has_dimensions' => $wpsItem->hasDimensions(),
                'dimensions' => $dimensions
            ]);

        } catch (\Exception $e) {
            Log::channel('wps')->warning('Failed to enhance item data', [
                'item_id' => $wpsItem->wps_item_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Extract cost from complete data
     */
    protected function extractCost($completeData)
    {
        $item = $completeData['item'] ?? [];
        
        return $item['cost'] ?? 
               $item['dealer_price'] ?? 
               $item['standard_dealer_price'] ?? 
               null;
    }

    /**
     * Extract special price from complete data
     */
    protected function extractSpecialPrice($completeData)
    {
        $item = $completeData['item'] ?? [];
        
        return $item['special_price'] ?? 
               $item['sale_price'] ?? 
               $item['promotional_price'] ?? 
               null;
    }

    /**
     * Extract special price from date
     */
    protected function extractSpecialPriceFrom($completeData)
    {
        $item = $completeData['item'] ?? [];
        
        $fromDate = $item['special_price_from'] ?? 
                    $item['sale_price_from'] ?? 
                    $item['promotional_price_from'] ?? 
                    null;
                    
        return $fromDate ? \Carbon\Carbon::parse($fromDate)->toDateString() : null;
    }

    /**
     * Extract special price to date
     */
    protected function extractSpecialPriceTo($completeData)
    {
        $item = $completeData['item'] ?? [];
        
        $toDate = $item['special_price_to'] ?? 
                  $item['sale_price_to'] ?? 
                  $item['promotional_price_to'] ?? 
                  null;
                  
        return $toDate ? \Carbon\Carbon::parse($toDate)->toDateString() : null;
    }

    /**
     * Sync inventory data
     */
    public function syncInventory()
    {
        Log::channel('wps')->info('Starting WPS inventory sync');

        $cursor = null;
        $totalUpdated = 0;

        do {
            $response = $this->apiService->getInventory($cursor);

            if (!$response || !isset($response['data'])) {
                Log::channel('wps')->error('Failed to fetch inventory', ['cursor' => $cursor]);
                break;
            }

            foreach ($response['data'] as $inventoryData) {
                $wpsItem = WpsProductItem::where('wps_item_id', $inventoryData['item_id'])->first();

                if ($wpsItem) {
                    $wpsItem->inventory_total = $inventoryData['total'];
                    $wpsItem->save();
                    $totalUpdated++;

                    if ($wpsItem->bagisto_product_id) {
                        app(WpsInventoryService::class)->updateBagistoInventory(
                            $wpsItem->bagisto_product_id,
                            $wpsItem->inventory_total
                        );
                    }
                }
            }

            $cursor = $response['meta']['cursor']['next'] ?? null;

        } while ($cursor);

        Log::channel('wps')->info('WPS inventory sync completed', [
            'total_updated' => $totalUpdated
        ]);

        return $totalUpdated;
    }
}