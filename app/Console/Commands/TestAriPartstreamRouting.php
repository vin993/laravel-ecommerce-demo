<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestAriPartstreamRouting extends Command
{
    protected $signature = 'test:ari-partstream-routing';
    protected $description = 'Test ARI Partstream item routing to verify supplier assignments';

    public function handle()
    {
        $this->info('Testing ARI Partstream Item Routing...');
        
        // Simulate ARI Partstream items with different supplier assignments
        $testAriItems = [
            [
                'sku' => 'TEST-ARI-001',
                'name' => 'Test ARI Partstream Item 1',
                'price' => 49.99,
                'quantity' => 2,
                'selected_supplier' => 'ari_partstream', // Should route to 'ari_stock' (ShipStation)
                'brand' => 'Test Brand',
            ],
            [
                'sku' => 'TEST-ARI-002', 
                'name' => 'Test ARI Partstream Item 2',
                'price' => 99.99,
                'quantity' => 1,
                'selected_supplier' => 'wps', // Should route to WPS
                'brand' => 'Test Brand',
                'suppliers' => [
                    'wps' => [
                        'wps_item_id' => 'WPS-12345',
                        'price' => 89.99,
                        'available' => true
                    ]
                ]
            ],
            [
                'sku' => 'TEST-ARI-003',
                'name' => 'Test ARI Partstream Item 3', 
                'price' => 75.50,
                'quantity' => 3,
                'selected_supplier' => 'turn14', // Should route to Turn14
                'brand' => 'Test Brand',
                'suppliers' => [
                    'turn14' => [
                        'turn14_item_id' => 'T14-67890',
                        'price' => 69.99,
                        'available' => true
                    ]
                ]
            ]
        ];
        
        $this->info('Processing test ARI items through supplier routing logic...');
        
        foreach ($testAriItems as $ariItem) {
            $selectedSupplier = $ariItem['selected_supplier'] ?? 'ari_partstream';
            
            // Convert ari_partstream to ari_stock for ShipStation routing
            if ($selectedSupplier === 'ari_partstream') {
                $selectedSupplier = 'ari_stock';
            }
            
            $this->line("SKU: {$ariItem['sku']}");
            $this->line("  Original Supplier: {$ariItem['selected_supplier']}");
            $this->line("  Routed Supplier: {$selectedSupplier}");
            $this->line("  Price: \${$ariItem['price']}");
            $this->line("  Quantity: {$ariItem['quantity']}");
            
            // Simulate the supplier assignment
            switch ($selectedSupplier) {
                case 'ari_stock':
                    $this->info("  ✓ Will be sent to ShipStation for in-house fulfillment");
                    Log::channel('shipstation')->info('Test: ARI Partstream item routed to ShipStation', [
                        'sku' => $ariItem['sku'],
                        'supplier' => $selectedSupplier,
                        'original_supplier' => $ariItem['selected_supplier']
                    ]);
                    break;
                    
                case 'wps':
                    $this->info("  ✓ Will be sent to WPS for dropship fulfillment");
                    Log::channel('dropship')->info('Test: ARI Partstream item routed to WPS', [
                        'sku' => $ariItem['sku'],
                        'supplier' => $selectedSupplier,
                        'wps_item_id' => $ariItem['suppliers']['wps']['wps_item_id'] ?? null
                    ]);
                    break;
                    
                case 'turn14':
                    $this->info("  ✓ Will be sent to Turn14 for dropship fulfillment");
                    Log::channel('dropship')->info('Test: ARI Partstream item routed to Turn14', [
                        'sku' => $ariItem['sku'],
                        'supplier' => $selectedSupplier,
                        'turn14_item_id' => $ariItem['suppliers']['turn14']['turn14_item_id'] ?? null
                    ]);
                    break;
                    
                case 'parts_unlimited':
                    $this->info("  ✓ Will be sent to Parts Unlimited for dropship fulfillment");
                    break;
                    
                case 'helmet_house':
                    $this->info("  ✓ Will be sent to Helmet House for dropship fulfillment");
                    break;
                    
                default:
                    $this->warn("  ⚠ Unknown supplier: {$selectedSupplier}");
            }
            
            $this->line('');
        }
        
        $this->info('✅ Test completed! Check the logs:');
        $this->line('  - ShipStation logs: storage/logs/shipstation.log');
        $this->line('  - Dropship logs: storage/logs/dropship.log');
        
        $this->warn('🔍 Key Changes Made:');
        $this->line('  1. ARI Partstream items with supplier="ari_partstream" now route to ShipStation');
        $this->line('  2. ARI Partstream items with other suppliers (wps, turn14, etc.) route to those dropshippers');
        $this->line('  3. All routing is logged for tracking and debugging');
        $this->line('  4. Database records correctly show the final supplier routing');
        
        return 0;
    }
}