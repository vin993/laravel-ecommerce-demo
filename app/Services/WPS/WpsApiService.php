<?php

namespace App\Services\WPS;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WpsApiService
{
    protected $baseUrl;
    protected $token;

    public function __construct()
    {
        $this->baseUrl = config('wps.api.base_url');
        $this->token = config('wps.api.token');
    }

    /**
     * Make API request with proper authentication
     */
    protected function makeRequest($endpoint, $params = [], $commandOutput = null)
    {
        $startTime = microtime(true);
        $fullUrl = $this->baseUrl . $endpoint;
        
        try {
            if ($commandOutput) {
                $commandOutput->writeln('<comment>🌐 API Request: ' . $endpoint . '</comment>');
            }
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ])->timeout(30)->get($fullUrl, $params);
            
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);

            if ($response->successful()) {
                $responseData = $response->json();
                $dataCount = 0;
                
                // Count items in response for better logging
                if (isset($responseData['data']) && is_array($responseData['data'])) {
                    $dataCount = count($responseData['data']);
                }
                
                Log::channel('wps')->info('WPS API Success', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'response_time_ms' => $responseTime,
                    'data_count' => $dataCount,
                    'params' => $params
                ]);
                
                if ($commandOutput) {
                    $commandOutput->writeln('<info>✅ API Success: ' . $endpoint . ' (' . $dataCount . ' items, ' . $responseTime . 'ms)</info>');
                }
                
                return $responseData;
            }

            $errorInfo = [
                'endpoint' => $endpoint,
                'status' => $response->status(),
                'response_time_ms' => $responseTime,
                'response_body' => $response->body(),
                'params' => $params
            ];
            
            Log::channel('wps')->error('WPS API Error', $errorInfo);
            
            if ($commandOutput) {
                $commandOutput->writeln('<error>❌ API Error: ' . $endpoint . ' (Status: ' . $response->status() . ', ' . $responseTime . 'ms)</error>');
                if ($response->status() == 429) {
                    $commandOutput->writeln('<comment>⏳ Rate limit reached. Consider adding delays between requests.</comment>');
                }
            }

            return null;
        } catch (\Exception $e) {
            $responseTime = round((microtime(true) - $startTime) * 1000, 2);
            
            $errorInfo = [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'response_time_ms' => $responseTime,
                'params' => $params,
                'exception_type' => get_class($e)
            ];
            
            Log::channel('wps')->error('WPS API Exception', $errorInfo);
            
            if ($commandOutput) {
                $commandOutput->writeln('<error>❌ API Exception: ' . $endpoint . ' - ' . $e->getMessage() . ' (' . $responseTime . 'ms)</error>');
                if (strpos($e->getMessage(), 'timeout') !== false) {
                    $commandOutput->writeln('<comment>⏳ Request timed out. Consider increasing timeout or checking network connectivity.</comment>');
                }
            }
            
            return null;
        }
    }

    /**
     * Get products with pagination
     */
    public function getProducts($cursor = null)
    {
        $params = [];
        if ($cursor) {
            $params['page[cursor]'] = $cursor;
        }

        return $this->makeRequest('products/', $params);
    }

    /**
     * Get product items by product ID with detailed information
     */
    public function getProductItems($productId)
    {
        return $this->makeRequest("products/{$productId}/items");
    }

    /**
     * Get detailed item information including dimensions and pricing
     */
    public function getItemDetails($itemId)
    {
        $response = $this->makeRequest("items/{$itemId}");
        
        if ($response && isset($response['data'])) {
            // Extract detailed item data
            $itemData = $response['data'];
            
            // Log the full response to see available fields
            Log::channel('wps')->debug('Item details response', [
                'item_id' => $itemId,
                'available_fields' => array_keys($itemData)
            ]);
            
            return $itemData;
        }
        
        return null;
    }

    /**
     * Get item attribute values (categories, specifications)
     */
    public function getItemAttributeValues($itemId)
    {
        return $this->makeRequest("items/{$itemId}/attributevalues");
    }

    /**
     * Get item images
     */
    public function getItemImages($itemId)
    {
        return $this->makeRequest("items/{$itemId}/images");
    }

    /**
     * Get item specifications/dimensions
     */
    public function getItemSpecifications($itemId)
    {
        return $this->makeRequest("items/{$itemId}/specifications");
    }

    /**
     * Get inventory data
     */
    public function getInventory($cursor = null)
    {
        $params = [];
        if ($cursor) {
            $params['page[cursor]'] = $cursor;
        }

        return $this->makeRequest('inventory', $params);
    }

    /**
     * Get comprehensive item data by combining multiple endpoints
     */
    public function getCompleteItemData($itemId)
    {
        $data = [
            'item' => null,
            'attributes' => null,
            'specifications' => null,
            'images' => null
        ];

        // Get basic item data
        $data['item'] = $this->getItemDetails($itemId);
        
        // Get attribute values (categories)
        $data['attributes'] = $this->getItemAttributeValues($itemId);
        
        // Get specifications (may contain dimensions)
        $data['specifications'] = $this->getItemSpecifications($itemId);
        
        // Get images
        $data['images'] = $this->getItemImages($itemId);

        Log::channel('wps')->debug('Complete item data retrieved', [
            'item_id' => $itemId,
            'has_item' => !is_null($data['item']),
            'has_attributes' => !is_null($data['attributes']),
            'has_specifications' => !is_null($data['specifications']),
            'has_images' => !is_null($data['images'])
        ]);

        return $data;
    }

    /**
     * Extract dimensions from item data
     */
    public function extractDimensions($itemData)
    {
        $dimensions = [
            'weight' => null,
            'length' => null,
            'width' => null,
            'height' => null
        ];

        // Check different possible locations for dimension data
        $possibleSources = [
            $itemData['item'] ?? [],
            $itemData['specifications']['data'] ?? [],
        ];

        foreach ($possibleSources as $source) {
            if (is_array($source)) {
                // Look for dimension fields with various naming conventions
                $dimensionMap = [
                    'weight' => ['weight', 'Weight', 'item_weight', 'shipping_weight'],
                    'length' => ['length', 'Length', 'item_length', 'shipping_length'],
                    'width' => ['width', 'Width', 'item_width', 'shipping_width'],
                    'height' => ['height', 'Height', 'item_height', 'shipping_height']
                ];

                foreach ($dimensionMap as $dimension => $possibleKeys) {
                    foreach ($possibleKeys as $key) {
                        if (isset($source[$key]) && is_numeric($source[$key]) && is_null($dimensions[$dimension])) {
                            $dimensions[$dimension] = (float) $source[$key];
                            break;
                        }
                    }
                }
            }
        }

        // If specifications is an array of objects, search through them
        if (isset($itemData['specifications']['data']) && is_array($itemData['specifications']['data'])) {
            foreach ($itemData['specifications']['data'] as $spec) {
                if (isset($spec['name']) && isset($spec['value'])) {
                    $name = strtolower($spec['name']);
                    $value = $spec['value'];
                    
                    if (is_numeric($value)) {
                        if (strpos($name, 'weight') !== false && is_null($dimensions['weight'])) {
                            $dimensions['weight'] = (float) $value;
                        } elseif (strpos($name, 'length') !== false && is_null($dimensions['length'])) {
                            $dimensions['length'] = (float) $value;
                        } elseif (strpos($name, 'width') !== false && is_null($dimensions['width'])) {
                            $dimensions['width'] = (float) $value;
                        } elseif (strpos($name, 'height') !== false && is_null($dimensions['height'])) {
                            $dimensions['height'] = (float) $value;
                        }
                    }
                }
            }
        }

        return $dimensions;
    }

    /**
     * Extract product status flags
     */
    public function extractProductStatus($itemData)
    {
        $status = [
            'is_new' => false,
            'is_featured' => false,
            'is_available' => true
        ];

        $item = $itemData['item'] ?? [];

        // Check for new product indicators
        if (isset($item['is_new'])) {
            $status['is_new'] = (bool) $item['is_new'];
        } elseif (isset($item['new'])) {
            $status['is_new'] = (bool) $item['new'];
        }

        // Check for featured product indicators
        if (isset($item['is_featured'])) {
            $status['is_featured'] = (bool) $item['is_featured'];
        } elseif (isset($item['featured'])) {
            $status['is_featured'] = (bool) $item['featured'];
        }

        // Check availability
        if (isset($item['status'])) {
            $status['is_available'] = !in_array(strtolower($item['status']), ['na', 'discontinued', 'inactive']);
        }

        return $status;
    }
}