<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe\Stripe;
use Stripe\WebhookEndpoint;

class CheckStripeWebhookConfig extends Command
{
    protected $signature = 'stripe:check-webhook-config';

    protected $description = 'Check Stripe webhook endpoint configuration';

    public function handle()
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $this->info("Local Configuration:");
        $this->line("  Webhook Secret (local): " . config('services.stripe.webhook_secret'));
        $this->line("  Stripe Secret Key: " . (config('services.stripe.secret') ? substr(config('services.stripe.secret'), 0, 15) . '...' : 'NOT SET'));
        $this->line("");

        try {
            $this->info("Fetching webhook endpoints from Stripe...");
            $endpoints = WebhookEndpoint::all(['limit' => 10]);

            if (count($endpoints->data) === 0) {
                $this->warn("No webhook endpoints configured in Stripe.");
                return 0;
            }

            $this->info("Found " . count($endpoints->data) . " webhook endpoint(s):");
            $this->line("");

            foreach ($endpoints->data as $index => $endpoint) {
                $this->line("Endpoint #" . ($index + 1) . ":");
                $this->line("  ID: {$endpoint->id}");
                $this->line("  URL: {$endpoint->url}");
                $this->line("  Status: {$endpoint->status}");
                $this->line("  Events: " . implode(', ', $endpoint->enabled_events));

                if ($endpoint->url === 'https://yourdomain.com/webhooks/stripe') {
                    $this->line("");
                    $this->info("  ** This is your configured endpoint **");
                    $this->info("  Note: Stripe does not return webhook secrets after creation for security.");
                    $this->info("  Your local secret should match what was shown during webhook creation.");
                }
                $this->line("");
            }

        } catch (\Exception $e) {
            $this->error("Error fetching webhook endpoints: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
