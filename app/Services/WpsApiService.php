<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WpsApiService
{
    private $baseUrl;
    private $apiKey;
    
    public function __construct()
    {
        $this->baseUrl = config('wps.api_url', 'https://api.wps-inc.com');
        $this->apiKey = config('wps.api_key');
    }
    
    /**
     * Create a cart in WPS system
     */
    public function createCart($poNumber, $cartData)
    {
        try {
            $payload = array_merge([
                'po_number' => $poNumber,
                'default_warehouse' => 'TX', // Default to Texas warehouse
                'ship_via' => 'BEST', // Best Ground method available
                'allow_backorder' => true,
                'multiple_warehouse' => true,
                'pay_type' => 'CC' // Credit card
            ], $cartData);
            
            Log::info('WPS Create Cart Request', ['po_number' => $poNumber, 'payload' => $payload]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->baseUrl . '/carts', $payload);
            
            if ($response->successful()) {
                $data = $response->json();
                Log::info('WPS Create Cart Success', ['po_number' => $poNumber, 'response' => $data]);
                return ['success' => true, 'data' => $data];
            }
            
            Log::error('WPS Create Cart Failed', [
                'po_number' => $poNumber,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to create WPS cart',
                'error' => $response->body()
            ];
            
        } catch (\Exception $e) {
            Log::error('WPS Create Cart Exception', [
                'po_number' => $poNumber,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error creating WPS cart: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Add items to WPS cart
     */
    public function addItemToCart($poNumber, $itemData)
    {
        try {
            Log::info('WPS Add Item to Cart', ['po_number' => $poNumber, 'item' => $itemData]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->baseUrl . "/carts/{$poNumber}/items", $itemData);
            
            if ($response->successful()) {
                $data = $response->json();
                Log::info('WPS Add Item Success', ['po_number' => $poNumber, 'response' => $data]);
                return ['success' => true, 'data' => $data];
            }
            
            Log::error('WPS Add Item Failed', [
                'po_number' => $poNumber,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to add item to WPS cart',
                'error' => $response->body()
            ];
            
        } catch (\Exception $e) {
            Log::error('WPS Add Item Exception', [
                'po_number' => $poNumber,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error adding item to WPS cart: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Submit cart as order
     */
    public function submitOrder($poNumber)
    {
        try {
            Log::info('WPS Submit Order', ['po_number' => $poNumber]);
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ])->post($this->baseUrl . '/orders', [
                'po_number' => $poNumber
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                Log::info('WPS Submit Order Success', ['po_number' => $poNumber, 'response' => $data]);
                return ['success' => true, 'data' => $data];
            }
            
            Log::error('WPS Submit Order Failed', [
                'po_number' => $poNumber,
                'status' => $response->status(),
                'response' => $response->body()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to submit WPS order',
                'error' => $response->body()
            ];
            
        } catch (\Exception $e) {
            Log::error('WPS Submit Order Exception', [
                'po_number' => $poNumber,
                'error' => $e->getMessage()
            ]);
            
            return [
                'success' => false,
                'message' => 'Error submitting WPS order: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get order status
     */
    public function getOrderStatus($poNumber)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json'
            ])->get($this->baseUrl . "/orders/{$poNumber}");
            
            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to get order status',
                'error' => $response->body()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error getting order status: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Show cart details
     */
    public function showCart($poNumber)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json'
            ])->get($this->baseUrl . "/carts/{$poNumber}");
            
            if ($response->successful()) {
                return ['success' => true, 'data' => $response->json()];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to get cart details',
                'error' => $response->body()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error getting cart details: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Delete cart
     */
    public function deleteCart($poNumber)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json'
            ])->delete($this->baseUrl . "/carts/{$poNumber}");
            
            if ($response->successful()) {
                return ['success' => true];
            }
            
            return [
                'success' => false,
                'message' => 'Failed to delete cart',
                'error' => $response->body()
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error deleting cart: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate unique PO number
     */
    public function generatePoNumber()
    {
        return 'MP-' . date('Ymd') . '-' . uniqid();
    }
    
    /**
     * Get available warehouses
     */
    public function getWarehouses()
    {
        return [
            'CA' => 'Fresno, CA',
            'GA' => 'Midway, GA', 
            'ID' => 'Boise, ID',
            'IN' => 'Ashley, IN',
            'PA' => 'Elizabethtown, PA',
            'TX' => 'Midlothian, TX'
        ];
    }
    
    /**
     * Get shipping methods
     */
    public function getShippingMethods()
    {
        return [
            'BEST' => 'Best Ground Method Available',
            'UPS' => 'UPS Ground',
            'FDXG' => 'FedEx Ground',
            'UP2D' => 'UPS 2 Day Blue',
            'FE2D' => 'FedEx 2 Day',
            'UP1D' => 'UPS 1 Day Red',
            'FE1D' => 'FedEx 1 Day',
            'US1C' => 'USPS Priority Mail',
            'US4C' => 'USPS Parcel Post'
        ];
    }
}
