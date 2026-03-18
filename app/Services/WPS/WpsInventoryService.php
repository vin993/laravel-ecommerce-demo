<?php

namespace App\Services\WPS;

use App\Models\WPS\WpsProductItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WpsInventoryService
{
    protected $apiService;

    public function __construct(WpsApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Sync inventory from WPS API to both WPS tracking and Bagisto
     */
    public function syncInventoryFromApi()
    {
        Log::channel('wps')->info('Starting inventory sync from WPS API');

        $cursor = null;
        $totalUpdated = 0;
        $bagistoUpdated = 0;

        do {
            $response = $this->apiService->getInventory($cursor);

            if (!$response || !isset($response['data'])) {
                Log::channel('wps')->error('Failed to fetch inventory', ['cursor' => $cursor]);
                break;
            }

            foreach ($response['data'] as $inventoryData) {
                try {
                    $result = $this->updateItemInventory($inventoryData);
                    $totalUpdated += $result['wps_updated'] ? 1 : 0;
                    $bagistoUpdated += $result['bagisto_updated'] ? 1 : 0;
                } catch (\Exception $e) {
                    Log::channel('wps')->error('Failed to update inventory', [
                        'item_id' => $inventoryData['item_id'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            $cursor = $response['meta']['cursor']['next'] ?? null;

        } while ($cursor);

        Log::channel('wps')->info('Inventory sync completed', [
            'wps_updated' => $totalUpdated,
            'bagisto_updated' => $bagistoUpdated
        ]);

        return [
            'wps_updated' => $totalUpdated,
            'bagisto_updated' => $bagistoUpdated
        ];
    }

    /**
     * Update inventory for a single item
     */
    protected function updateItemInventory($inventoryData)
    {
        $result = ['wps_updated' => false, 'bagisto_updated' => false];

        // Update WPS tracking
        $wpsItem = WpsProductItem::where('wps_item_id', $inventoryData['item_id'])->first();

        if ($wpsItem) {
            $wpsItem->update(['inventory_total' => $inventoryData['total']]);
            $result['wps_updated'] = true;

            // Update Bagisto inventory if product exists
            if ($wpsItem->bagisto_product_id) {
                $this->updateBagistoInventory($wpsItem->bagisto_product_id, $inventoryData['total']);
                $result['bagisto_updated'] = true;
            }
        }

        return $result;
    }

    /**
     * Update Bagisto product inventory
     */
    public function updateBagistoInventory($productId, $quantity)
    {
        $inventorySourceId = 1;

        $existing = DB::table('product_inventories')
            ->where('product_id', $productId)
            ->where('inventory_source_id', $inventorySourceId)
            ->first();

        if ($existing) {
            DB::table('product_inventories')
                ->where('product_id', $productId)
                ->where('inventory_source_id', $inventorySourceId)
                ->update(['qty' => $quantity]);
        } else {
            DB::table('product_inventories')->insert([
                'product_id' => $productId,
                'inventory_source_id' => $inventorySourceId,
                'qty' => $quantity
            ]);
        }

        Log::channel('wps')->debug('Synced Bagisto inventory', [
            'product_id' => $productId,
            'quantity' => $quantity
        ]);
    }


    /**
     * Sync inventory from WPS tracking to Bagisto (for existing data)
     */
    public function syncInventoryToBagisto($limit = 1000)
    {
        Log::channel('wps')->info('Syncing WPS inventory to Bagisto', ['limit' => $limit]);

        $wpsItems = WpsProductItem::whereNotNull('bagisto_product_id')
            ->limit($limit)
            ->get();

        $updated = 0;
        $errors = 0;

        foreach ($wpsItems as $wpsItem) {
            try {
                $this->updateBagistoInventory($wpsItem->bagisto_product_id, $wpsItem->inventory_total);
                $updated++;
            } catch (\Exception $e) {
                $errors++;
                Log::channel('wps')->error('Failed to sync inventory to Bagisto', [
                    'wps_item_id' => $wpsItem->wps_item_id,
                    'bagisto_product_id' => $wpsItem->bagisto_product_id,
                    'error' => $e->getMessage()
                ]);
            }
        }

        Log::channel('wps')->info('Inventory sync to Bagisto completed', [
            'updated' => $updated,
            'errors' => $errors
        ]);

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * Get inventory statistics
     */
    public function getInventoryStats()
    {
        $wpsItemsWithInventory = WpsProductItem::where('inventory_total', '>', 0)->count();
        $wpsItemsOutOfStock = WpsProductItem::where('inventory_total', '<=', 0)->count();
        $bagistoInventoryRecords = DB::table('product_inventories')
            ->whereIn('product_id', WpsProductItem::whereNotNull('bagisto_product_id')->pluck('bagisto_product_id'))
            ->count();

        return [
            'wps_items_in_stock' => $wpsItemsWithInventory,
            'wps_items_out_of_stock' => $wpsItemsOutOfStock,
            'bagisto_inventory_records' => $bagistoInventoryRecords,
        ];
    }
}