<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Webkul\Sales\Repositories\InvoiceRepository;
use Webkul\Sales\Repositories\OrderRepository;

class CreateInvoiceOnOrderSuccess
{
    protected $invoiceRepository;
    protected $orderRepository;

    public function __construct(
        InvoiceRepository $invoiceRepository,
        OrderRepository $orderRepository
    ) {
        $this->invoiceRepository = $invoiceRepository;
        $this->orderRepository = $orderRepository;
    }

    public function handle($orderId)
    {
        try {
            if (is_object($orderId)) {
                $orderId = $orderId->id ?? null;
            }

            if (!$orderId) {
                Log::warning('CreateInvoiceOnOrderSuccess: No order ID provided');
                return;
            }

            $order = $this->orderRepository->find($orderId);

            if (!$order) {
                Log::warning('CreateInvoiceOnOrderSuccess: Order not found', ['order_id' => $orderId]);
                return;
            }

            if (!$order->canInvoice()) {
                Log::info('CreateInvoiceOnOrderSuccess: Order cannot be invoiced', [
                    'order_id' => $orderId,
                    'status' => $order->status
                ]);
                return;
            }

            $regularItems = [];
            foreach ($order->items as $item) {
                if ($item->qty_to_invoice > 0) {
                    $regularItems[$item->id] = $item->qty_to_invoice;
                }
            }

            $ariItems = [];
            $ariOrderItems = DB::table('ari_partstream_order_items')
                ->where('order_id', $orderId)
                ->get();

            foreach ($ariOrderItems as $ariItem) {
                $ariItems[$ariItem->id] = $ariItem->quantity;
            }

            if (empty($regularItems) && empty($ariItems)) {
                Log::warning('CreateInvoiceOnOrderSuccess: No items to invoice', ['order_id' => $orderId]);
                return;
            }

            $invoiceData = [
                'order_id' => $orderId,
                'invoice' => [
                    'items' => $regularItems,
                    'ari_items' => $ariItems,
                ],
                'email_sent' => 1
            ];

            $invoice = $this->invoiceRepository->create($invoiceData, 'paid', 'processing');

            Log::info('Auto-invoice created successfully', [
                'order_id' => $orderId,
                'invoice_id' => $invoice->id,
                'increment_id' => $invoice->increment_id
            ]);

        } catch (\Exception $e) {
            Log::error('CreateInvoiceOnOrderSuccess: Failed to create invoice', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
