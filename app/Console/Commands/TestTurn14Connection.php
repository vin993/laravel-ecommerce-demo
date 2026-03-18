<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestTurn14Connection extends Command
{
    protected $signature = 'test:turn14-connection';
    protected $description = 'Test Turn14 API connection and show detailed error information';

    public function handle()
    {
        $this->info("🔍 Turn14 API Connection Test");
        $this->info("============================\n");

        $apiUrl = rtrim(config('turn14.api_url'), '/');
        $environment = config('turn14.environment', 'testing');
        $clientId = config('turn14.client_id');
        $clientSecret = config('turn14.client_secret');

        if ($environment === 'testing' && !str_contains($apiUrl, 'apitest')) {
            $apiUrl = str_replace('api.turn14.com', 'apitest.turn14.com', $apiUrl);
        }

        $this->info("Configuration:");
        $this->table(
            ['Setting', 'Value'],
            [
                ['Environment', $environment],
                ['API URL', $apiUrl],
                ['Client ID', substr($clientId, 0, 20) . '...'],
                ['Client Secret', substr($clientSecret, 0, 20) . '...'],
            ]
        );

        $this->newLine();
        $this->info("Step 1: Testing Token Endpoint...");

        try {
            $tokenResponse = Http::timeout(30)
                ->post("{$apiUrl}/v1/token", [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            $this->info("Token Response Status: " . $tokenResponse->status());

            if ($tokenResponse->successful()) {
                $this->info("✅ Token request successful");
                $tokenData = $tokenResponse->json();
                $accessToken = $tokenData['access_token'] ?? null;

                if ($accessToken) {
                    $this->info("✅ Access token received: " . substr($accessToken, 0, 30) . "...");

                    // Test a pricing call
                    $this->newLine();
                    $this->info("Step 2: Testing Pricing Endpoint...");
                    $testItemId = '4334417';  // From earlier tests

                    $pricingResponse = Http::timeout(30)
                        ->withToken($accessToken)
                        ->get("{$apiUrl}/v1/pricing/{$testItemId}");

                    $this->info("Pricing Response Status: " . $pricingResponse->status());

                    if ($pricingResponse->successful()) {
                        $this->info("✅ Pricing API accessible");

                        $pricingData = $pricingResponse->json();
                        $attributes = $pricingData['data']['attributes'] ?? [];

                        $this->newLine();
                        $this->info("Available Price Fields:");
                        $this->table(
                            ['Field', 'Value', 'Status'],
                            [
                                ['retail_price', isset($attributes['retail_price']) ? '$' . number_format($attributes['retail_price'], 2) : 'Not Available', isset($attributes['retail_price']) ? '✅' : '❌'],
                                ['map_price', isset($attributes['map_price']) ? '$' . number_format($attributes['map_price'], 2) : 'Not Available', isset($attributes['map_price']) ? '✅' : '❌'],
                                ['jobber_price', isset($attributes['jobber_price']) ? '$' . number_format($attributes['jobber_price'], 2) : 'Not Available', isset($attributes['jobber_price']) ? '✅' : '❌'],
                                ['purchase_cost', isset($attributes['purchase_cost']) ? '$' . number_format($attributes['purchase_cost'], 2) : 'Not Available', isset($attributes['purchase_cost']) ? '✅' : '❌'],
                            ]
                        );

                        if (isset($attributes['retail_price'])) {
                            $this->newLine();
                            $this->info("🎉 SUCCESS! Production API provides retail_price!");
                            $this->info("Your Turn14 integration is correctly configured.");
                        } else {
                            $this->newLine();
                            $this->warn("⚠️  Production API does NOT provide retail_price");
                            $this->warn("Contact Turn14 support - your account may need configuration.");
                        }

                    } else {
                        $this->error("❌ Pricing API failed: " . $pricingResponse->status());
                        $this->error("Response: " . $pricingResponse->body());
                    }

                } else {
                    $this->error("❌ No access token in response");
                    $this->error("Response: " . $tokenResponse->body());
                }

            } else {
                $this->error("❌ Token request failed: " . $tokenResponse->status());
                $this->error("Response: " . $tokenResponse->body());

                $this->newLine();
                $this->warn("Possible issues:");
                $this->warn("1. Invalid client credentials for production environment");
                $this->warn("2. Account not authorized for production API");
                $this->warn("3. Need different credentials for production vs test");
            }

        } catch (\Exception $e) {
            $this->error("❌ Connection failed: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
        }

        return 0;
    }
}
