<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TestStripeWebhook extends Command
{
    protected $signature = 'test:stripe-webhook {order_id}';

    protected $description = 'Test Stripe webhook configuration and simulate invoice paid event';

    public function handle()
    {
        $orderId = $this->argument('order_id');

        $order = DB::table('orders')->where('id', $orderId)->first();

        if (!$order) {
            $this->error("Order ID {$orderId} not found.");
            return 1;
        }

        $this->info("Order Details:");
        $this->line("  ID: {$order->id}");
        $this->line("  Increment ID: {$order->increment_id}");
        $this->line("  Customer Email: {$order->customer_email}");
        $this->line("  Additional Shipping: {$order->additional_shipping_amount}");
        $this->line("  Invoice ID: {$order->additional_shipping_stripe_invoice_id}");
        $this->line("  Invoice Status: {$order->additional_shipping_invoice_status}");
        $this->line("  Pending Amount: {$order->pending_payment_amount}");
        $this->line("");

        $this->info("Webhook Configuration:");
        $this->line("  Webhook Secret: " . (config('services.stripe.webhook_secret') ? 'SET' : 'NOT SET'));
        $this->line("  Stripe Secret Key: " . (config('services.stripe.secret') ? 'SET' : 'NOT SET'));
        $this->line("");

        if ($this->confirm('Simulate invoice paid event?', true)) {
            $this->info("Simulating invoice.paid webhook...");

            DB::beginTransaction();

            try {
                DB::table('orders')->where('id', $orderId)->update([
                    'additional_shipping_invoice_status' => 'paid',
                    'pending_payment_amount' => 0,
                    'status' => 'processing',
                    'updated_at' => now()
                ]);

                DB::table('order_comments')->insert([
                    'order_id' => $orderId,
                    'comment' => "Additional shipping invoice paid via Stripe (SIMULATED TEST). Invoice ID: {$order->additional_shipping_stripe_invoice_id}",
                    'customer_notified' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                DB::commit();

                $this->info("Successfully updated order!");
                $this->line("  Invoice Status: paid");
                $this->line("  Pending Amount: 0");
                $this->line("  Order Status: processing");

                Log::channel('stripe_webhook')->info('Test webhook simulation completed', [
                    'order_id' => $orderId,
                    'invoice_id' => $order->additional_shipping_stripe_invoice_id
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("Failed to update order: {$e->getMessage()}");
                return 1;
            }
        }

        return 0;
    }
}
