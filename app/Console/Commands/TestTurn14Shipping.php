<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\Turn14DropshipService;

class TestTurn14Shipping extends Command
{
    protected $signature = 'test:turn14-shipping {--sku=}';

    protected $description = 'Test Turn14 shipping quote API';

    public function handle()
    {
        $sku = $this->option('sku');

        if (!$sku) {
            $this->error('Please provide a SKU with --sku option');
            $this->info('Example: php artisan test:turn14-shipping --sku=bor140017');
            return 1;
        }

        $this->info('Testing Turn14 Shipping Quote API');
        $this->info('===================================');
        $this->info('');

        $testItems = [
            [
                'sku' => $sku,
                'quantity' => 1
            ]
        ];

        $testAddress = [
            'ship_name' => 'John Doe',
            'ship_address1' => '123 Test St',
            'ship_city' => 'Los Angeles',
            'ship_state' => 'CA',
            'ship_zip' => '90001',
            'ship_phone' => '555-0123',
            'email' => 'test@example.com'
        ];

        $this->info('Test Item: ' . $sku);
        $this->info('Ship To: ' . $testAddress['ship_city'] . ', ' . $testAddress['ship_state']);
        $this->info('');
        $this->info('Fetching shipping quote...');
        $this->info('');

        try {
            $service = app(Turn14DropshipService::class);
            $result = $service->getShippingQuote($testItems, $testAddress);

            if ($result['success'] ?? false) {
                $this->info('SUCCESS!');
                $this->info('');
                $this->info('Shipping Rate: $' . number_format($result['rate'], 2));
                $this->info('Quote ID: ' . ($result['quote_id'] ?? 'N/A'));
                $this->info('Method: ' . ($result['method'] ?? 'N/A'));
                $this->info('');

                if (isset($result['shipping_options']) && !empty($result['shipping_options'])) {
                    $this->info('Available Shipping Options:');
                    $this->table(
                        ['Code', 'Cost', 'Days', 'ETA'],
                        array_map(function($option) {
                            return [
                                $option['code'] ?? 'N/A',
                                '$' . number_format($option['cost'] ?? 0, 2),
                                $option['days'] ?? 'N/A',
                                $option['eta'] ?? 'N/A'
                            ];
                        }, $result['shipping_options'])
                    );
                }
            } else {
                $this->error('FAILED!');
                $this->error('Error: ' . ($result['error'] ?? 'Unknown error'));
            }

        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            $this->error('Trace: ' . $e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}
