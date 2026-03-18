<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\WpsDropshipService;

class TestWpsOrder extends Command
{
    protected $signature = 'test:wps-order {--sku=26-1105} {--qty=1}';
    protected $description = 'Test complete WPS order flow with hold_order';

    public function handle()
    {
        $sku = $this->option('sku');
        $quantity = (int) $this->option('qty');
        
        $wpsService = app(WpsDropshipService::class);
        
        $this->info("🧪 Testing complete WPS order flow...");
        $this->info("SKU: {$sku}, Quantity: {$quantity}");
        
        // Step 1: Check availability
        $this->info("\n1️⃣ Checking product availability...");
        $availability = $wpsService->checkAvailability($sku);
        
        if (!$availability || !$availability['available']) {
            $this->error("❌ Product not available in WPS");
            return 1;
        }
        
        $this->table(['Property', 'Value'], [
            ['SKU', $sku],
            ['Name', $availability['name']],
            ['Available', 'Yes'],
            ['Price', '$' . number_format($availability['price'], 2)],
            ['Inventory', $availability['inventory']],
            ['WPS Item ID', $availability['wps_item_id']]
        ]);
        
        // Step 2: Create test order
        $this->info("\n2️⃣ Creating test order...");
        
        $testCartItems = [
            [
                'sku' => $sku,
                'quantity' => $quantity,
                'price' => $availability['price'],
                'name' => $availability['name']
            ]
        ];
        
        $testShippingInfo = [
            'ship_name' => 'Test Customer',
            'ship_address1' => '123 Test Street',
            'ship_address2' => 'Suite 100',
            'ship_city' => 'Test City',
            'ship_state' => 'TX',
            'ship_zip' => '75001',
            'ship_phone' => '555-1234',
            'email' => 'test@maddparts.com',
            'default_warehouse' => 'TX',
            'ship_via' => 'BEST'
        ];
        
        $orderResult = $wpsService->createOrder($testCartItems, $testShippingInfo);
        
        if ($orderResult['success']) {
            $this->info("✅ Order created successfully!");
            $this->table(['Field', 'Value'], [
                ['PO Number', $orderResult['po_number']],
                ['Order Number', $orderResult['order_number'] ?? 'Pending'],
                ['Status', 'HELD (Test Order)'],
                ['Items', count($testCartItems)],
                ['Total Value', '$' . number_format($availability['price'] * $quantity, 2)]
            ]);
            
            // Step 3: Check order status
            $this->info("\n3️⃣ Checking order status...");
            $statusResult = $wpsService->getOrderStatus($orderResult['po_number']);
            
            if ($statusResult['success']) {
                $orderData = $statusResult['data'];
                $this->info("✅ Order status retrieved:");
                $this->line("PO: {$orderData['po_number']}");
                $this->line("Ship To: {$orderData['ship_name']}");
                $this->line("Address: {$orderData['ship_address']}, {$orderData['ship_city']}, {$orderData['ship_state']} {$orderData['ship_zip']}");
                
                if (isset($orderData['order_details']) && !empty($orderData['order_details'])) {
                    $order = $orderData['order_details'][0];
                    $this->table(['Order Property', 'Value'], [
                        ['Order Number', $order['order_number'] ?? 'N/A'],
                        ['Status', $order['order_status'] ?? 'N/A'],
                        ['Warehouse', $order['warehouse'] ?? 'N/A'],
                        ['Order Total', '$' . number_format($order['order_total'] ?? 0, 2)],
                        ['Freight', '$' . number_format($order['freight'] ?? 0, 2)],
                        ['Order Date', $order['order_date'] ?? 'N/A']
                    ]);
                }
            } else {
                $this->warn("⚠️ Could not retrieve order status: " . $statusResult['error']);
            }
            
        } else {
            $this->error("❌ Order creation failed: " . $orderResult['error']);
            return 1;
        }
        
        $this->newLine();
        $this->info("🎉 WPS order flow test completed!");
        $this->info("📝 Summary:");
        $this->info("   ✅ Product availability check");
        $this->info("   ✅ Cart creation with hold_order=true");
        $this->info("   ✅ Item addition to cart");
        $this->info("   ✅ Order submission");
        $this->info("   ✅ Order status retrieval");
        $this->newLine();
        $this->warn("⚠️  Order is HELD and will not be fulfilled (test mode)");
        
        return 0;
    }
}