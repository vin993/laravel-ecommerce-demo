<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class InspectShipStationCarriers extends Command
{
    protected $signature = 'shipstation:inspect-carriers';
    protected $description = 'Detailed inspection of ShipStation carriers and services';

    public function handle()
    {
        $apiKey = env('SHIPSTATION_API_KEY');
        $secretKey = env('SHIPSTATION_SECRET_KEY');
        $baseUrl = env('SHIPSTATION_BASE_URL');

        $this->info('Detailed ShipStation Carrier Inspection');
        $this->info('======================================');

        try {
            $response = Http::withBasicAuth($apiKey, $secretKey)
                ->timeout(10)
                ->get("{$baseUrl}/carriers");

            if (!$response->successful()) {
                $this->error('Failed to fetch carriers: HTTP ' . $response->status());
                return 1;
            }

            $carriers = $response->json();

            $this->info("\nRAW JSON RESPONSE:");
            $this->line(json_encode($carriers, JSON_PRETTY_PRINT));

            $this->info("\n\nPARSED CARRIERS:");
            $this->line(str_repeat('=', 80));

            foreach ($carriers as $index => $carrier) {
                $this->info("\n[Carrier #{$index}]");
                $this->line("Name: " . ($carrier['name'] ?? 'N/A'));
                $this->line("Code: " . ($carrier['code'] ?? 'N/A'));
                $this->line("Nickname: " . ($carrier['nickname'] ?? 'N/A'));
                $this->line("Account Number: " . ($carrier['accountNumber'] ?? 'N/A'));
                $this->line("Requires Funded Account: " . (isset($carrier['requiresFundedAccount']) ? ($carrier['requiresFundedAccount'] ? 'Yes' : 'No') : 'N/A'));
                $this->line("Balance: " . ($carrier['balance'] ?? 'N/A'));
                $this->line("Primary: " . (isset($carrier['primary']) ? ($carrier['primary'] ? 'Yes' : 'No') : 'N/A'));

                $this->line("\nAll Keys in Carrier Object:");
                $this->line(implode(', ', array_keys($carrier)));

                if (isset($carrier['services'])) {
                    $this->info("\nServices Array:");
                    if (is_array($carrier['services']) && count($carrier['services']) > 0) {
                        foreach ($carrier['services'] as $serviceIndex => $service) {
                            $this->line("  Service #{$serviceIndex}:");
                            $this->line("    " . json_encode($service, JSON_PRETTY_PRINT));
                        }
                    } else {
                        $this->line("  Empty or not an array");
                    }
                } else {
                    $this->warn("  No 'services' key in carrier object");
                }

                $this->line(str_repeat('-', 80));
            }

            $this->info("\n\nSUGGESTIONS:");
            $this->info("Based on the carrier list, the UPS carriers are:");

            $upsCarriers = array_filter($carriers, function($c) {
                return isset($c['code']) && $c['code'] === 'ups';
            });

            foreach ($upsCarriers as $ups) {
                $this->line("- " . ($ups['name'] ?? 'UPS') . " (" . ($ups['nickname'] ?? 'No nickname') . ")");
                $this->line("  Code: " . ($ups['code'] ?? 'N/A'));
                $this->line("  Account: " . ($ups['accountNumber'] ?? 'N/A'));
            }

        } catch (\Exception $e) {
            $this->error('Exception: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
