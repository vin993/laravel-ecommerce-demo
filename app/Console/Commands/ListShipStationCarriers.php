<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShipStationService;

class ListShipStationCarriers extends Command
{
    protected $signature = 'shipstation:list-carriers';
    protected $description = 'List all carriers and services in ShipStation account';

    public function handle()
    {
        $shipStationService = app(ShipStationService::class);

        $this->info('ShipStation Account Carriers');
        $this->info('============================');

        $result = $shipStationService->getCarriers();

        if (!$result['success']) {
            $this->error('Failed to fetch carriers: ' . $result['error']);
            return 1;
        }

        $carriers = $result['carriers'];
        $this->info("Found " . count($carriers) . " carrier(s) in your account\n");

        foreach ($carriers as $carrier) {
            $name = $carrier['name'] ?? 'Unknown';
            $code = $carrier['code'] ?? 'N/A';
            $nickname = $carrier['nickname'] ?? '';
            $accountNumber = $carrier['accountNumber'] ?? 'N/A';

            $this->line("Carrier: {$name}" . ($nickname ? " ({$nickname})" : ""));
            $this->line("  Code: {$code}");
            $this->line("  Account: {$accountNumber}");

            if (isset($carrier['services']) && is_array($carrier['services'])) {
                $this->line("  Services (" . count($carrier['services']) . "):");
                foreach ($carrier['services'] as $service) {
                    $serviceName = $service['name'] ?? 'Unknown';
                    $serviceCode = $service['code'] ?? 'N/A';
                    $carrierServiceId = $service['carrierServiceId'] ?? 'N/A';
                    $this->line("    - {$serviceName}");
                    $this->line("      Service Code: {$serviceCode}");
                    $this->line("      Service ID: {$carrierServiceId}");
                }
            } else {
                $this->line("  Services: None configured");
            }

            $this->line("");
        }

        $this->info("\nTo use specific carrier services for rates:");
        $this->info("Update the carrier service IDs in:");
        $this->info("  app/Services/ShipStationService.php");
        $this->info("  Look for: \$clientCarrierServices array");

        return 0;
    }
}
