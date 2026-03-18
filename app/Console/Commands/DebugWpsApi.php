<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DebugWpsApi extends Command
{
    protected $signature = 'debug:wps-api {--sku=568-9011X}';
    protected $description = 'Debug WPS API configuration and connectivity';

    public function handle()
    {
        $sku = $this->option('sku');
        
        $this->info("🔍 Debugging WPS API configuration...");
        
        // Check environment variables
        $baseUrl = rtrim(env('WPS_API_BASE_URL', 'https://api.wps-inc.com'), '/');
        $token = env('WPS_API_TOKEN');
        
        $this->info("\n📋 Configuration:");
        $this->table(['Setting', 'Value'], [
            ['Base URL', $baseUrl],
            ['Token Set', $token ? 'Yes' : 'No'],
            ['Token Length', $token ? strlen($token) : 0],
            ['Token Preview', $token ? substr($token, 0, 8) . '...' : 'Not set']
        ]);
        
        if (!$token) {
            $this->error("❌ WPS_API_TOKEN not set in .env file");
            return 1;
        }
        
        // Test raw API call
        $this->info("\n🌐 Testing raw API call...");
        
        try {
            $url = "{$baseUrl}/items";
            $params = ['filter[sku]' => $sku];
            
            $this->info("URL: {$url}");
            $this->info("Parameters: " . json_encode($params));
            
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'User-Agent' => 'Laravel/Bagisto WPS Integration'
            ])->timeout(30)->get($url, $params);
            
            $this->info("\n📊 Response Details:");
            $this->table(['Property', 'Value'], [
                ['Status Code', $response->status()],
                ['Successful', $response->successful() ? 'Yes' : 'No'],
                ['Content Type', $response->header('content-type')],
                ['Response Size', strlen($response->body()) . ' bytes']
            ]);
            
            if ($response->successful()) {
                $data = $response->json();
                
                $this->info("\n✅ API Response Success!");
                
                if (isset($data['data']) && is_array($data['data'])) {
                    $items = $data['data'];
                    $this->info("Items found: " . count($items));
                    
                    if (!empty($items)) {
                        $item = $items[0];
                        $this->info("\n🎯 Product Found:");
                        $this->table(['Field', 'Value'], [
                            ['SKU', $item['sku'] ?? 'N/A'],
                            ['Name', $item['name'] ?? 'N/A'],
                            ['WPS ID', $item['id'] ?? 'N/A'],
                            ['List Price', '$' . ($item['list_price'] ?? 0)],
                            ['Dealer Price', '$' . ($item['standard_dealer_price'] ?? 0)],
                            ['Status', $item['status'] ?? 'N/A'],
                            ['Dropship Eligible', ($item['drop_ship_eligible'] ?? false) ? 'Yes' : 'No']
                        ]);
                        
                        // Test inventory call
                        $this->info("\n📦 Testing inventory lookup...");
                        
                        // Try filter approach first
                        $inventoryResponse = Http::withHeaders([
                            'Authorization' => 'Bearer ' . $token,
                            'Accept' => 'application/json',
                        ])->timeout(15)->get("{$baseUrl}/inventory", [
                            'filter[item_id]' => $item['id']
                        ]);
                        
                        if ($inventoryResponse->successful()) {
                            $inventoryData = $inventoryResponse->json()['data'] ?? [];
                            $totalQty = 0;
                            
                            foreach ($inventoryData as $inventoryRecord) {
                                if (isset($inventoryRecord['total'])) {
                                    // Use the total field if available
                                    $totalQty += $inventoryRecord['total'];
                                } else {
                                    // Sum individual warehouse quantities
                                    $warehouses = [
                                        'ca_warehouse', 'ga_warehouse', 'id_warehouse', 
                                        'in_warehouse', 'pa_warehouse', 'pa2_warehouse', 'tx_warehouse'
                                    ];
                                    
                                    foreach ($warehouses as $warehouse) {
                                        $totalQty += $inventoryRecord[$warehouse] ?? 0;
                                    }
                                }
                            }
                            
                            $this->info("✅ Inventory API (filter) working: {$totalQty} total quantity");
                            
                            // Show warehouse breakdown
                            if (!empty($inventoryData)) {
                                $record = $inventoryData[0];
                                $this->info("Warehouse breakdown:");
                                $this->info("  CA: {$record['ca_warehouse']}, GA: {$record['ga_warehouse']}, ID: {$record['id_warehouse']}");
                                $this->info("  IN: {$record['in_warehouse']}, PA: {$record['pa_warehouse']}, TX: {$record['tx_warehouse']}");
                                $this->info("  Total: {$record['total']}");
                            }
                        } else {
                            $this->warn("⚠️ Inventory API (filter) failed: " . $inventoryResponse->status());
                            
                            // Try direct entity endpoint
                            $this->info("Trying direct entity endpoint...");
                            $directResponse = Http::withHeaders([
                                'Authorization' => 'Bearer ' . $token,
                                'Accept' => 'application/json',
                            ])->timeout(15)->get("{$baseUrl}/inventory/{$item['id']}");
                            
                            if ($directResponse->successful()) {
                                $data = $directResponse->json()['data'] ?? [];
                                $qty = 0;
                                if (isset($data['quantity'])) {
                                    $qty = $data['quantity'];
                                } elseif (is_array($data)) {
                                    foreach ($data as $warehouse) {
                                        $qty += $warehouse['quantity'] ?? 0;
                                    }
                                }
                                $this->info("✅ Inventory API (direct) working: {$qty} total quantity");
                            } else {
                                $this->error("❌ Both inventory approaches failed");
                                $this->info("Filter response: " . $inventoryResponse->body());
                                $this->info("Direct response: " . $directResponse->body());
                            }
                        }
                        
                    } else {
                        $this->warn("⚠️ No products found for SKU: {$sku}");
                    }
                } else {
                    $this->error("❌ Invalid response format");
                    $this->line("Response body: " . $response->body());
                }
            } else {
                $this->error("❌ API call failed");
                $this->error("Status: " . $response->status());
                $this->error("Response: " . $response->body());
                
                if ($response->status() === 401) {
                    $this->error("🔒 Authentication failed - check your WPS API token");
                } elseif ($response->status() === 404) {
                    $this->error("🔍 Endpoint not found - check the API URL");
                }
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Exception occurred: " . $e->getMessage());
            $this->error("File: " . $e->getFile() . ":" . $e->getLine());
        }
        
        return 0;
    }
}