<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShipStationService;
use Illuminate\Support\Facades\Http;

class VerifyUSPSCarrier extends Command
{
    protected $signature = 'shipstation:verify-usps';
    protected $description = 'Verify USPS/Stamps.com carrier configuration';

    public function handle()
    {
        $shipStationService = app(ShipStationService::class);

        $this->info('Verifying USPS/Stamps.com Carrier Configuration');
        $this->info('==============================================');

        $result = $shipStationService->getCarriers();

        if (!$result['success']) {
            $this->error('Failed to fetch carriers: ' . $result['error']);
            return 1;
        }

        $carriers = $result['carriers'];
        $this->info("Total carriers found: " . count($carriers) . "\n");

        $uspsCarrier = null;
        foreach ($carriers as $carrier) {
            if (($carrier['code'] ?? '') === 'stamps_com') {
                $uspsCarrier = $carrier;
                break;
            }
        }

        if (!$uspsCarrier) {
            $this->error('✗ USPS/Stamps.com carrier not found in account');
            $this->info("\nAvailable carriers:");
            foreach ($carriers as $carrier) {
                $this->line("  - " . ($carrier['name'] ?? 'Unknown') . " (code: " . ($carrier['code'] ?? 'N/A') . ")");
            }
            return 1;
        }

        $this->info('✓ USPS/Stamps.com carrier found!');
        $this->line("Name: " . ($uspsCarrier['name'] ?? 'N/A'));
        $this->line("Code: " . ($uspsCarrier['code'] ?? 'N/A'));
        $this->line("Nickname: " . ($uspsCarrier['nickname'] ?? 'N/A'));
        $this->line("Account Number: " . ($uspsCarrier['accountNumber'] ?? 'N/A'));

        if (isset($uspsCarrier['services']) && is_array($uspsCarrier['services'])) {
            $this->info("\n✓ Services configured: " . count($uspsCarrier['services']));

            $targetServiceId = 'se-222774';
            $foundTarget = false;

            $this->table(
                ['Service Name', 'Code', 'Carrier Service ID', 'Match'],
                array_map(function($service) use ($targetServiceId, &$foundTarget) {
                    $carrierServiceId = $service['carrierServiceId'] ?? 'N/A';
                    $isMatch = $carrierServiceId === $targetServiceId;
                    if ($isMatch) $foundTarget = true;
                    return [
                        $service['name'] ?? 'Unknown',
                        $service['code'] ?? 'N/A',
                        $carrierServiceId,
                        $isMatch ? '✓ TARGET' : ''
                    ];
                }, $uspsCarrier['services'])
            );

            if ($foundTarget) {
                $this->info("\n✓ Target service ID '{$targetServiceId}' found!");
            } else {
                $this->error("\n✗ Target service ID '{$targetServiceId}' NOT found");
                $this->warn("You may need to update the service ID in ShipStationService.php");
            }
        } else {
            $this->error("\n✗ No services configured for USPS carrier");
            $this->info("Please configure USPS services in your ShipStation account");
        }

        $this->info("\n\nTesting rate request for USPS...");

        $apiKey = env('SHIPSTATION_API_KEY');
        $secretKey = env('SHIPSTATION_SECRET_KEY');
        $baseUrl = env('SHIPSTATION_BASE_URL');

        $testRequest = [
            'carrierCode' => 'stamps_com',
            'packageCode' => 'package',
            'fromPostalCode' => env('SHIPSTATION_FROM_ZIP', '72416'),
            'toState' => 'TX',
            'toPostalCode' => '75001',
            'toCountry' => 'US',
            'toCity' => 'Dallas',
            'weight' => [
                'value' => 1,
                'units' => 'pounds'
            ],
            'dimensions' => [
                'units' => 'inches',
                'length' => 12,
                'width' => 9,
                'height' => 6
            ],
            'confirmation' => 'none',
            'residential' => true
        ];

        try {
            $response = Http::withBasicAuth($apiKey, $secretKey)
                ->timeout(15)
                ->post("{$baseUrl}/shipments/getrates", $testRequest);

            if ($response->successful()) {
                $rates = $response->json();
                $this->info("✓ USPS rate request successful!");
                $this->info("Rates returned: " . count($rates));

                if (!empty($rates)) {
                    $this->table(
                        ['Service', 'Rate', 'Service Code'],
                        array_map(function($rate) {
                            return [
                                $rate['serviceName'] ?? 'N/A',
                                '$' . number_format(($rate['shipmentCost'] ?? 0) + ($rate['otherCost'] ?? 0), 2),
                                $rate['serviceCode'] ?? 'N/A'
                            ];
                        }, array_slice($rates, 0, 5))
                    );
                }
            } else {
                $this->error("✗ USPS rate request failed");
                $this->error("Status: " . $response->status());
                $this->line($response->body());
            }
        } catch (\Exception $e) {
            $this->error("✗ Exception: " . $e->getMessage());
        }

        return 0;
    }
}
