<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\HelmetHouseDropshipService;
use Exception;

class TestHelmetHouse extends Command
{
    protected $signature = 'test:helmet-house {--sku=5642210800} {--test-order}';
    protected $description = 'Test Helmet House API integration';

    protected $helmetHouseService;

    public function __construct()
    {
        parent::__construct();
        $this->helmetHouseService = new HelmetHouseDropshipService();
    }

    public function handle()
    {
        $this->info('Testing Helmet House API Integration');
        $this->newLine();

        $this->testConnection();
        $this->testAvailability();

        if ($this->option('test-order')) {
            $this->testOrderCreation();
        }

        return Command::SUCCESS;
    }

    private function testConnection()
    {
        $this->info('1. Testing API Connection...');
        
        try {
            $result = $this->helmetHouseService->testConnection();
            
            if ($result['success']) {
                $this->info('   Connection successful!');
                $this->line('   Status: ' . $result['status']);
                $this->line('   Has Data: ' . ($result['has_data'] ? 'Yes' : 'No'));
            } else {
                $this->error('   Connection failed: ' . ($result['error'] ?? 'Unknown error'));
            }
            
        } catch (Exception $e) {
            $this->error('   Connection exception: ' . $e->getMessage());
        }
        
        $this->newLine();
    }

    private function testAvailability()
    {
        $sku = $this->option('sku');
        $this->info("2. Testing Product Availability for SKU: {$sku}");
        
        try {
            $result = $this->helmetHouseService->checkAvailability($sku);
            
            if ($result && $result['available']) {
                $this->info('   Product is available!');
                $this->table(['Field', 'Value'], [
                    ['SKU', $result['helmet_house_sku']],
                    ['Price', '$' . number_format($result['price'], 2)],
                    ['MAP Price', '$' . number_format($result['map_price'] ?? 0, 2)],
                    ['Retail Price', '$' . number_format($result['retail_price'] ?? 0, 2)],
                    ['Inventory', $result['inventory']],
                    ['Weight', ($result['weight'] ?? 0) . ' lbs'],
                    ['ETA', $result['eta'] ?? 'N/A'],
                    ['East Warehouse', $result['warehouses']['east']['quantity'] ?? 0],
                    ['West Warehouse', $result['warehouses']['west']['quantity'] ?? 0],
                ]);
            } else {
                $this->warn('   Product not available or not found');
                if (isset($result['error'])) {
                    $this->error('   Error: ' . $result['error']);
                }
            }
            
        } catch (Exception $e) {
            $this->error('   Availability check exception: ' . $e->getMessage());
        }
        
        $this->newLine();
    }

    private function testOrderCreation()
    {
        $sku = $this->option('sku');
        $this->info("3. Testing Order Creation (HOLD MODE)");
        $this->warn("   Order PO will be 10 chars max and held in Helmet House CRM");
        $this->warn("   You can verify and delete it from the CRM");
        $this->newLine();

        $testCartItems = [
            [
                'sku' => $sku,
                'quantity' => 1,
                'price' => 41.02
            ]
        ];

        $testShippingInfo = [
            'ship_name' => 'Test Customer - Madd Parts',
            'ship_address1' => '123 Test St',
            'ship_address2' => '',
            'ship_city' => 'Test City',
            'ship_state' => 'CA',
            'ship_zip' => '90210',
            'phone' => '555-123-4567',
            'ship_phone' => '555-123-4567',
            'email' => 'test@maddparts.com'
        ];

        $this->info('   Test Order Details:');
        $this->table(['Field', 'Value'], [
            ['SKU', $sku],
            ['Quantity', 1],
            ['Price', '$41.02'],
            ['Customer', 'Test Customer - Madd Parts'],
            ['Email', 'test@maddparts.com'],
            ['City/State', 'Test City, CA'],
            ['Dealer Number', env('HELMET_HOUSE_DEALER_NUMBER')],
            ['Test Mode', env('HELMET_HOUSE_TEST_MODE') ? 'Yes' : 'No']
        ]);
        $this->newLine();

        try {
            $result = $this->helmetHouseService->createOrder($testCartItems, $testShippingInfo);

            if ($result['success']) {
                $this->info('   ✓ Order creation successful!');
                $this->newLine();
                $this->table(['Field', 'Value'], [
                    ['PO Number', $result['po_number']],
                    ['Helmet House Order ID', $result['helmet_house_order_id'] ?? 'N/A'],
                    ['Reference Number', $result['reference_number'] ?? 'N/A'],
                    ['Status Code', $result['status_code'] ?? 'N/A'],
                    ['Status Message', $result['status_message'] ?? 'N/A'],
                    ['Order Total', '$' . number_format($result['order_total'] ?? 0, 2)]
                ]);

                $this->newLine();
                $this->info('   Check the Helmet House CRM portal to verify this order.');
                $this->warn('   Remember to delete this test order from the CRM!');
            } else {
                $this->error('   ✗ Order creation failed!');
                $this->newLine();
                $this->error('   Error: ' . ($result['error'] ?? 'Unknown error'));
                $this->newLine();
                $this->warn('   Troubleshooting tips:');
                $this->line('   1. Check storage/logs/helmet-house-orders.log for detailed request/response');
                $this->line('   2. Verify HELMET_HOUSE_DEALER_NUMBER in .env is correct');
                $this->line('   3. Verify HELMET_HOUSE_API_TOKEN in .env is valid');
                $this->line('   4. Contact Helmet House support if issue persists');
            }

        } catch (Exception $e) {
            $this->error('   ✗ Order creation exception!');
            $this->newLine();
            $this->error('   Exception: ' . $e->getMessage());
            $this->newLine();
            $this->line('   Trace: ' . $e->getTraceAsString());
        }

        $this->newLine();
    }
}