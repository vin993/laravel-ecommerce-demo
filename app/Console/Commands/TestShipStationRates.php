<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShipStationService;

class TestShipStationRates extends Command
{
    protected $signature = 'test:shipstation-rates {--zip=75001}';
    protected $description = 'Test ShipStation carrier account rates';

    public function handle()
    {
        $shipStationService = app(ShipStationService::class);

        $this->info('Testing ShipStation Carrier Account Rates');
        $this->info('==========================================');

        $testAddress = [
            'state' => 'TX',
            'postalCode' => $this->option('zip'),
            'country' => 'US',
            'city' => 'Dallas'
        ];

        $testItems = [
            [
                'sku' => 'TEST-001',
                'name' => 'Test Product',
                'quantity' => 1,
                'weight' => 5
            ]
        ];

        $this->info("\nTest Address:");
        $this->table(['Field', 'Value'], [
            ['City', $testAddress['city']],
            ['State', $testAddress['state']],
            ['ZIP', $testAddress['postalCode']],
            ['Country', $testAddress['country']]
        ]);

        $this->info("\nTest Package:");
        $this->info('Weight: 5 lbs');
        $this->info('Dimensions: 12x9x6 inches');

        $this->info("\nFetching rates from client carrier accounts...");
        $this->info('Carrier Services: se-1868096 (UPS Main), se-1873220 (UPS 2-Day)');

        $result = $shipStationService->calculateShippingRates($testAddress, $testItems);

        if ($result['success']) {
            $this->info("\n✓ Successfully retrieved rates!");
            $this->info("Total options available: " . count($result['rates']));

            if (!empty($result['rates'])) {
                $rateData = [];
                foreach ($result['rates'] as $rate) {
                    $rateData[] = [
                        $rate['carrier_name'],
                        $rate['service_name'],
                        '$' . number_format($rate['rate'], 2),
                        $rate['delivery_days'] ? $rate['delivery_days'] . ' days' : 'N/A',
                        $rate['carrier_code']
                    ];
                }

                $this->table(
                    ['Carrier', 'Service', 'Rate', 'Delivery', 'Service ID'],
                    $rateData
                );
            } else {
                $this->warn('No rates available for this destination');
            }

            if (isset($result['grouped_rates'])) {
                $this->info("\nGrouped by Carrier:");
                foreach ($result['grouped_rates'] as $carrierName => $group) {
                    $this->info("  {$carrierName}: " . count($group['services']) . " service(s)");
                }
            }

        } else {
            $this->error('✗ Failed to retrieve rates');
            $this->error('Error: ' . ($result['error'] ?? 'Unknown error'));

            $this->info("\nTroubleshooting:");
            $this->info('1. Verify carrier service IDs in ShipStation account');
            $this->info('2. Check that carrier accounts are active');
            $this->info('3. Ensure API credentials have rate access');
            $this->info('4. Check logs: storage/logs/shipstation.log');
        }

        $this->info("\nTo test different ZIP code:");
        $this->info('  sudo -u www-data php artisan test:shipstation-rates --zip=90210');

        return $result['success'] ? 0 : 1;
    }
}
