<?php

namespace App\Services\KawasakiDailySync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\DataImportService\KawasakiAutomatedXmlImportService;

class ProductSyncHandler
{
    protected $importService;
    
    public function __construct()
    {
        // Reuse existing import service logic
        $ftpService = app(\App\Services\DataStream\FtpService::class);
        $this->importService = new KawasakiAutomatedXmlImportService($ftpService);
    }

    /**
     * Sync a product (create or update)
     */
    public function syncProduct(array $data, string $action, array $changes = []): int
    {
        if ($action === 'create') {
            return $this->createProduct($data);
        } elseif ($action === 'update') {
            return $this->updateProduct($data, $changes);
        }
        
        return 0;
    }

    /**
     * Create a new product
     */
    protected function createProduct(array $data): int
    {
        // For now, just return 0 (product creation not implemented in daily sync)
        // The actual product creation is complex and should use the existing import service
        return 0;
    }

    /**
     * Update an existing product
     */
    protected function updateProduct(array $data, array $changes): int
    {
        $productId = DB::table('products')->where('sku', $data['sku'])->value('id');
        
        if (!$productId) {
            // Product doesn't exist, skip for now
            Log::info("[DailySync] Product not found for update: {$data['sku']}");
            return 0;
        }

        // Update only changed fields
        if (!empty($changes)) {
            $this->updateProductFields($productId, $data, $changes);
            $this->updateProductAttributes($productId, $data, $changes);
            Log::info("[DailySync] Updated product {$productId}: " . implode(', ', array_keys($changes)));
        }
        
        return $productId;
    }

    /**
     * Update product fields in product_flat table
     */
    protected function updateProductFields(int $productId, array $data, array $changes): void
    {
        $updates = [];
        
        if (isset($changes['name'])) {
            $updates['name'] = $data['name'];
        }
        
        if (isset($changes['price'])) {
            $updates['price'] = $data['price'];
        }
        
        if (isset($changes['weight'])) {
            $updates['weight'] = $this->parseWeight($data['weight']);
        }
        
        if (!empty($updates)) {
            DB::table('product_flat')
                ->where('product_id', $productId)
                ->update($updates);
        }
    }

    /**
     * Update product attributes
     */
    protected function updateProductAttributes(int $productId, array $data, array $changes): void
    {
        // Update size_and_style if changed
        if (isset($changes['size_and_style']) && !empty($data['size_and_style'])) {
            $attrId = DB::table('attributes')->where('code', 'size_and_style')->value('id');
            
            if ($attrId) {
                DB::table('product_attribute_values')->updateOrInsert(
                    ['product_id' => $productId, 'attribute_id' => $attrId],
                    ['text_value' => $data['size_and_style']]
                );
            }
        }
    }

    /**
     * Map data array to attributes array for import service
     */
    protected function mapDataToAttributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'price' => $data['price'],
            'weight' => $this->parseWeight($data['weight']),
            'length' => $this->parseDimension($data['length']),
            'width' => $this->parseDimension($data['width']),
            'height' => $this->parseDimension($data['height']),
            'description' => $data['description'],
            'short_description' => Str::limit($data['description'], 150),
            'url_key' => Str::slug($data['name'] . '-' . $data['sku']),
            'brand' => 'Kawasaki',
            'status' => 1,
            'size_and_style' => $data['size_and_style'] ?? null,
        ];
    }

    /**
     * Parse weight from string (e.g., "0.6200g" -> 0.62)
     */
    protected function parseWeight(string $weight): float
    {
        return (float) preg_replace('/[^0-9.]/', '', $weight);
    }

    /**
     * Parse dimension from string (e.g., "9.00in" -> 9.00)
     */
    protected function parseDimension(string $dimension): float
    {
        return (float) preg_replace('/[^0-9.]/', '', $dimension);
    }
}
