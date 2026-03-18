<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Stripe\Stripe;
use Stripe\WebhookEndpoint;

class ManageStripeWebhooks extends Command
{
    protected $signature = 'stripe:manage-webhooks {action=list} {--id=}';

    protected $description = 'Manage Stripe webhook endpoints (list, delete)';

    public function handle()
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $action = $this->argument('action');

        switch ($action) {
            case 'list':
                return $this->listWebhooks();
            case 'delete':
                return $this->deleteWebhook();
            default:
                $this->error("Unknown action: {$action}");
                $this->line("Available actions: list, delete");
                return 1;
        }
    }

    protected function listWebhooks()
    {
        try {
            $this->info("Fetching all webhook endpoints from Stripe...");
            $endpoints = WebhookEndpoint::all(['limit' => 100]);

            if (count($endpoints->data) === 0) {
                $this->warn("No webhook endpoints found.");
                $this->line("");
                $this->info("To create one, run:");
                $this->line("sudo -u www-data php artisan stripe:create-webhook");
                return 0;
            }

            $this->info("Found " . count($endpoints->data) . " webhook endpoint(s):");
            $this->line("");

            foreach ($endpoints->data as $index => $endpoint) {
                $this->line("Endpoint #" . ($index + 1) . ":");
                $this->line("  ID: {$endpoint->id}");
                $this->line("  URL: {$endpoint->url}");
                $this->line("  Status: {$endpoint->status}");
                $this->line("  Created: " . date('Y-m-d H:i:s', $endpoint->created));
                $this->line("  Events: " . implode(', ', $endpoint->enabled_events));
                $this->line("");
            }

            $this->info("To delete a webhook, run:");
            $this->line("sudo -u www-data php artisan stripe:manage-webhooks delete --id=WEBHOOK_ID");

        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }

    protected function deleteWebhook()
    {
        $id = $this->option('id');

        if (!$id) {
            $this->error("Please provide webhook ID with --id option");
            return 1;
        }

        try {
            $this->warn("Deleting webhook endpoint: {$id}");

            if (!$this->confirm('Are you sure?', false)) {
                $this->info("Cancelled.");
                return 0;
            }

            WebhookEndpoint::retrieve($id)->delete();

            $this->info("Webhook endpoint deleted successfully!");

        } catch (\Exception $e) {
            $this->error("Failed to delete webhook: {$e->getMessage()}");
            return 1;
        }

        return 0;
    }
}
