<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Stripe\Webhook;
use App\Mail\AdminAdditionalShippingPaid;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = config('services.stripe.webhook_secret');

        Log::channel('stripe_webhook')->info('Stripe webhook request received', [
            'has_signature' => !empty($sigHeader),
            'signature_preview' => $sigHeader ? substr($sigHeader, 0, 50) . '...' : null,
            'has_secret' => !empty($endpointSecret),
            'secret_preview' => $endpointSecret ? substr($endpointSecret, 0, 15) . '...' : null,
            'payload_length' => strlen($payload),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'all_headers' => $request->headers->all()
        ]);

        if (empty($endpointSecret)) {
            Log::channel('stripe_webhook')->error('Stripe webhook secret not configured in services.stripe.webhook_secret');
            return response()->json(['error' => 'Webhook not configured'], 500);
        }

        if (empty($sigHeader)) {
            Log::channel('stripe_webhook')->error('Stripe webhook missing signature header', [
                'available_headers' => array_keys($request->headers->all())
            ]);
            return response()->json(['error' => 'Missing signature'], 400);
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $endpointSecret
            );
        } catch (\UnexpectedValueException $e) {
            Log::channel('stripe_webhook')->error('Stripe webhook invalid payload', [
                'error' => $e->getMessage(),
                'payload_preview' => substr($payload, 0, 200)
            ]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::channel('stripe_webhook')->error('Stripe webhook signature verification failed', [
                'error' => $e->getMessage(),
                'signature_preview' => substr($sigHeader, 0, 50),
                'secret_preview' => substr($endpointSecret, 0, 15)
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::channel('stripe_webhook')->error('Stripe webhook unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Server error'], 500);
        }

        Log::channel('stripe_webhook')->info('Stripe webhook verified and received', [
            'type' => $event['type'],
            'id' => $event['id'],
            'data' => $event['data']['object'] ?? []
        ]);

        switch ($event['type']) {
            case 'invoice.paid':
                $this->handleInvoicePaid($event['data']['object']);
                break;

            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event['data']['object']);
                break;

            case 'invoice.finalized':
                $this->handleInvoiceFinalized($event['data']['object']);
                break;

            default:
                Log::info('Unhandled Stripe webhook event type', ['type' => $event['type']]);
        }

        return response()->json(['success' => true]);
    }

    protected function handleInvoicePaid($invoice)
    {
        $invoiceId = $invoice['id'];
        $metadata = $invoice['metadata'] ?? [];

        Log::channel('stripe_webhook')->info('Processing invoice.paid event', [
            'invoice_id' => $invoiceId,
            'metadata' => $metadata
        ]);

        if (isset($metadata['type']) && $metadata['type'] === 'additional_shipping') {
            $orderId = $metadata['order_id'] ?? null;

            if ($orderId) {
                DB::beginTransaction();

                try {
                    $order = DB::table('orders')->where('id', $orderId)->first();

                    if ($order) {
                        // Add only the amount that was just paid (pending_payment_amount) to base_grand_total_invoiced
                        $amountJustPaid = $order->pending_payment_amount ?? 0;
                        $newInvoicedTotal = $order->base_grand_total_invoiced + $amountJustPaid;

                        DB::table('orders')->where('id', $orderId)->update([
                            'additional_shipping_invoice_status' => 'paid',
                            'pending_payment_amount' => 0,
                            'base_grand_total_invoiced' => $newInvoicedTotal,
                            'status' => 'processing',
                            'updated_at' => now()
                        ]);

                        DB::table('order_comments')->insert([
                            'order_id' => $orderId,
                            'comment' => "Additional shipping invoice paid via Stripe. Amount: $" . number_format($amountJustPaid, 2) . ". Invoice ID: {$invoiceId}",
                            'customer_notified' => 0,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);

                        // Note: Invoice grand totals are already updated when admin adds additional shipping
                        // We don't need to update them again here - just mark the payment as received
                        // The order's base_grand_total_invoiced has already been updated above

                        // Send admin notification email
                        $adminEmails = explode(',', env('ADMIN_ORDER_EMAILS', ''));
                        $adminEmails = array_filter(array_map('trim', $adminEmails));

                        if (!empty($adminEmails)) {
                            try {
                                // Use the amount just paid (already calculated above)
                                foreach ($adminEmails as $adminEmail) {
                                    Mail::to($adminEmail)->send(
                                        new AdminAdditionalShippingPaid($order, $invoiceId, $amountJustPaid)
                                    );
                                }

                                Log::channel('stripe_webhook')->info('Admin notification emails sent', [
                                    'order_id' => $orderId,
                                    'recipients' => $adminEmails
                                ]);
                            } catch (\Exception $emailException) {
                                Log::channel('stripe_webhook')->error('Failed to send admin notification email', [
                                    'order_id' => $orderId,
                                    'error' => $emailException->getMessage()
                                ]);
                            }
                        }

                        Log::channel('stripe_webhook')->info('Successfully updated order after invoice payment', [
                            'order_id' => $orderId,
                            'invoice_id' => $invoiceId,
                            'amount' => $amountJustPaid
                        ]);
                    } else {
                        Log::channel('stripe_webhook')->warning('Order not found for invoice payment', [
                            'order_id' => $orderId,
                            'invoice_id' => $invoiceId
                        ]);
                    }

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::channel('stripe_webhook')->error('Failed to update order after Stripe invoice paid', [
                        'order_id' => $orderId,
                        'invoice_id' => $invoiceId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            } else {
                Log::channel('stripe_webhook')->warning('Invoice paid but no order_id in metadata', [
                    'invoice_id' => $invoiceId,
                    'metadata' => $metadata
                ]);
            }
        } else {
            Log::channel('stripe_webhook')->info('Invoice paid but not additional_shipping type', [
                'invoice_id' => $invoiceId,
                'metadata' => $metadata
            ]);
        }
    }

    protected function handleInvoicePaymentFailed($invoice)
    {
        $invoiceId = $invoice['id'];
        $metadata = $invoice['metadata'] ?? [];

        if (isset($metadata['type']) && $metadata['type'] === 'additional_shipping') {
            $orderId = $metadata['order_id'] ?? null;

            if ($orderId) {
                DB::table('orders')->where('id', $orderId)->update([
                    'additional_shipping_invoice_status' => 'payment_failed',
                    'updated_at' => now()
                ]);

                DB::table('order_comments')->insert([
                    'order_id' => $orderId,
                    'comment' => "Additional shipping invoice payment failed. Invoice ID: {$invoiceId}",
                    'customer_notified' => 0,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                Log::channel('stripe_webhook')->warning('Stripe additional shipping invoice payment failed', [
                    'order_id' => $orderId,
                    'invoice_id' => $invoiceId
                ]);
            }
        }
    }

    protected function handleInvoiceFinalized($invoice)
    {
        $invoiceId = $invoice['id'];
        $metadata = $invoice['metadata'] ?? [];

        Log::channel('stripe_webhook')->info('Stripe invoice finalized', [
            'invoice_id' => $invoiceId,
            'order_id' => $metadata['order_id'] ?? 'N/A',
            'metadata' => $metadata
        ]);
    }
}
