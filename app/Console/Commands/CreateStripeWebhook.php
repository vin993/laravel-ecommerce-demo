<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe\Stripe;
use Stripe\WebhookEndpoint;

class CreateStripeWebhook extends Command
{
    protected $signature = 'stripe:create-webhook';

    protected $description = 'Create Stripe webhook endpoint via API';

    public function handle()
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $url = 'https://yourdomain.com/webhooks/stripe';

        $this->info("Creating webhook endpoint...");
        $this->line("URL: {$url}");
        $this->line("");

        try {
            $endpoint = WebhookEndpoint::create([
                'url' => $url,
                'enabled_events' => [
                    'invoice.paid',
                    'invoice.payment_failed',
                    'invoice.finalized',
                ],
                'description' => 'Additional shipping invoice payment notifications',
            ]);

            $this->info("Webhook endpoint created successfully!");
            $this->line("");
            $this->line("Endpoint Details:");
            $this->line("  ID: {$endpoint->id}");
            $this->line("  URL: {$endpoint->url}");
            $this->line("  Status: {$endpoint->status}");
            $this->line("  Secret: {$endpoint->secret}");
            $this->line("  Events: " . implode(', ', $endpoint->enabled_events));
            $this->line("");

            $this->warn("IMPORTANT: Update your .env file with this webhook secret:");
            $this->line("STRIPE_WEBHOOK_SECRET={$endpoint->secret}");
            $this->line("");
            $this->warn("After updating .env, run:");
            $this->line("sudo -u www-data php artisan config:clear");

        } catch (\Exception $e) {
            $this->error("Failed to create webhook endpoint: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
