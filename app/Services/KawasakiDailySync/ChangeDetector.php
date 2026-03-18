<?php

namespace App\Services\KawasakiDailySync;

use Illuminate\Support\Facades\DB;
use SimpleXMLElement;

class ChangeDetector
{
    /**
     * Detect if a product has changed since last sync
     *
     * @param SimpleXMLElement $node
     * @return array ['action' => 'create'|'update'|'skip', 'data' => array, 'changes' => array]
     */
    public function detectChanges(SimpleXMLElement $node): array
    {
        $sku = (string) $node['ItemNumber'];
        $currentData = $this->extractNodeData($node);
        
        // Filter out excluded items
        if ($this->shouldExcludeItem($currentData)) {
            return [
                'action' => 'skip',
                'data' => null,
                'changes' => [],
                'reason' => 'excluded_by_filter'
            ];
        }
        
        $currentChecksum = md5(json_encode($currentData));
        
        $snapshot = DB::table('kawasaki_product_snapshots')
            ->where('sku', $sku)
            ->first();
        
        if (!$snapshot) {
            return [
                'action' => 'create',
                'data' => $currentData,
                'changes' => []
            ];
        }
        
        if ($snapshot->checksum !== $currentChecksum) {
            $previousData = json_decode($snapshot->last_xml_data, true);
            $changes = $this->detectFieldChanges($previousData, $currentData);
            
            return [
                'action' => 'update',
                'data' => $currentData,
                'changes' => $changes
            ];
        }
        
        return [
            'action' => 'skip',
            'data' => null,
            'changes' => []
        ];
    }

    /**
     * Extract relevant data from XML node
     */
    protected function extractNodeData(SimpleXMLElement $node): array
    {
        return [
            'sku' => (string) $node['ItemNumber'],
            'name' => (string) $node['ItemDescription'],
            'price' => (float) $node['MsrpPriceAmt'],
            'dealer_price' => (float) $node['DealerPriceAmt'],
            'weight' => (string) $node['ItemWeight'],
            'length' => (string) $node['ItemLength'],
            'width' => (string) $node['ItemWidth'],
            'height' => (string) $node['ItemHeight'],
            'inventory' => (int) $node['EcommAvailToShipQty'],
            'size_and_style' => (string) $node['SizeAndStyle'],
            'status_flag' => (string) $node['RtlCusEcommItemFlag'],
            'inventory_type' => (string) $node['InventoryType'],
            'root_status' => (string) $node['ItemRootStatus'],
            'description' => (string) ($node->ExtendedDescription ?? ''),
            'categories' => $this->extractCategories($node),
            'images' => $this->extractImages($node),
        ];
    }

    /**
     * Extract categories from XML node
     */
    protected function extractCategories(SimpleXMLElement $node): array
    {
        $categories = [];
        
        if (isset($node->Categories)) {
            foreach ($node->Categories->Category as $category) {
                $categories[] = [
                    'id' => (string) $category['CategoryId'],
                    'name' => (string) $category,
                ];
            }
        }
        
        return $categories;
    }

    /**
     * Extract images from XML node
     */
    protected function extractImages(SimpleXMLElement $node): array
    {
        $images = [];
        
        if (isset($node->Images)) {
            foreach ($node->Images->Image as $image) {
                $path = (string) ($image['src'] ?? $image['Path'] ?? $image['Url'] ?? $image['URL'] ?? '');
                if (!empty($path)) {
                    $images[] = $path;
                }
            }
        }
        
        return $images;
    }

    /**
     * Detect which fields changed between previous and current data
     */
    protected function detectFieldChanges(array $previous, array $current): array
    {
        $changes = [];
        
        foreach ($current as $key => $value) {
            if (!isset($previous[$key]) || $previous[$key] !== $value) {
                $changes[$key] = [
                    'old' => $previous[$key] ?? null,
                    'new' => $value,
                ];
            }
        }
        
        return $changes;
    }

    /**
     * Determine if an item should be excluded from sync
     */
    protected function shouldExcludeItem(array $data): bool
    {
        // 1 = Out of stock, 2 = Restricted, 4 = Coming Soon, 8 = No longer printed
        $excludedStatuses = ['1', '2', '4', '8'];
        if (in_array($data['status_flag'], $excludedStatuses)) {
            return true;
        }
        
        // Exclude OEM parts (Type A = with diagrams, Type C = without diagrams)
        // Only sync accessories (Type B)
        if (isset($data['inventory_type']) && in_array($data['inventory_type'], ['A', 'C'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Update product snapshot after sync
     */
    public function updateSnapshot(string $sku, array $data): void
    {
        $checksum = md5(json_encode($data));
        
        DB::table('kawasaki_product_snapshots')->updateOrInsert(
            ['sku' => $sku],
            [
                'last_xml_data' => json_encode($data),
                'checksum' => $checksum,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}
