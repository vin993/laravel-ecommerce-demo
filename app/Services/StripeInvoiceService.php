<?php

namespace App\Services;

use Stripe\Stripe;
use Stripe\Invoice;
use Stripe\InvoiceItem;
use Stripe\Customer;
use Illuminate\Support\Facades\Log;

class StripeInvoiceService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    public function createShippingAdjustmentInvoice($order, $additionalShippingAmount, $reason = null)
    {
        try {
            Log::info('Creating Stripe invoice', [
                'order_id' => $order->id,
                'additional_shipping_amount' => $additionalShippingAmount,
                'amount_in_cents' => round($additionalShippingAmount * 100),
            ]);

            $stripeCustomer = $this->getOrCreateStripeCustomer($order);

            $invoice = Invoice::create([
                'customer' => $stripeCustomer->id,
                'collection_method' => 'send_invoice',
                'days_until_due' => 7,
                'metadata' => [
                    'order_id' => $order->id,
                    'order_increment_id' => $order->increment_id,
                    'type' => 'additional_shipping',
                ],
            ]);

            $invoiceItem = InvoiceItem::create([
                'customer' => $stripeCustomer->id,
                'invoice' => $invoice->id,
                'amount' => round($additionalShippingAmount * 100),
                'currency' => strtolower($order->order_currency_code ?? 'usd'),
                'description' => "Additional Shipping for Order #{$order->increment_id}" .
                               ($reason ? " - {$reason}" : ''),
            ]);

            Log::info('Stripe invoice item created', [
                'invoice_id' => $invoice->id,
                'invoice_item_id' => $invoiceItem->id,
                'amount' => $additionalShippingAmount,
            ]);

            $invoice->finalizeInvoice();

            $invoice->sendInvoice();

            Log::info('Stripe shipping adjustment invoice created', [
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'amount' => $additionalShippingAmount,
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'invoice_id' => $invoice->id,
                'hosted_invoice_url' => $invoice->hosted_invoice_url,
                'invoice_pdf' => $invoice->invoice_pdf,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to create Stripe shipping adjustment invoice', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getOrCreateStripeCustomer($order)
    {
        try {
            $existingCustomers = Customer::all([
                'email' => $order->customer_email,
                'limit' => 1,
            ]);

            if (count($existingCustomers->data) > 0) {
                return $existingCustomers->data[0];
            }

            return Customer::create([
                'email' => $order->customer_email,
                'name' => $order->customer_full_name,
                'metadata' => [
                    'bagisto_order_id' => $order->id,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get or create Stripe customer', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function getInvoiceStatus($invoiceId)
    {
        try {
            $invoice = Invoice::retrieve($invoiceId);

            return [
                'success' => true,
                'status' => $invoice->status,
                'paid' => $invoice->paid,
                'amount_paid' => $invoice->amount_paid / 100,
                'amount_due' => $invoice->amount_due / 100,
            ];

        } catch (\Exception $e) {
            Log::error('Failed to retrieve Stripe invoice status', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
