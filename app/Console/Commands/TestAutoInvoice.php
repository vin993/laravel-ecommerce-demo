<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Webkul\Sales\Repositories\OrderRepository;
use Illuminate\Support\Facades\Event;

class TestAutoInvoice extends Command
{
    protected $signature = 'test:auto-invoice {order_id}';
    protected $description = 'Test auto-invoice creation for an existing order';

    protected $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        parent::__construct();
        $this->orderRepository = $orderRepository;
    }

    public function handle()
    {
        $orderId = $this->argument('order_id');

        $order = $this->orderRepository->find($orderId);

        if (!$order) {
            $this->error("Order #{$orderId} not found");
            return 1;
        }

        $this->info("Testing auto-invoice for Order #{$order->increment_id} (ID: {$orderId})");
        $this->info("Order Status: {$order->status}");
        $this->info("Can Invoice: " . ($order->canInvoice() ? 'Yes' : 'No'));

        if ($order->invoices->count() > 0) {
            $this->warn("Order already has " . $order->invoices->count() . " invoice(s):");
            foreach ($order->invoices as $invoice) {
                $this->line("  - Invoice #{$invoice->increment_id} (ID: {$invoice->id}, State: {$invoice->state})");
            }
        }

        $this->info("\nFiring 'order.created' event...");
        event('order.created', $orderId);

        sleep(1);

        $order->refresh();

        if ($order->invoices->count() > 0) {
            $this->info("\nInvoices after event:");
            foreach ($order->invoices as $invoice) {
                $this->line("  - Invoice #{$invoice->increment_id} (ID: {$invoice->id}, State: {$invoice->state})");
            }
            $this->info("\n✓ Auto-invoice system working!");
        } else {
            $this->warn("\nNo invoice created. Check logs for details.");
        }

        return 0;
    }
}
