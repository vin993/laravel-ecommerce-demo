<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\PaymentIntent;
use Stripe\Exception\ApiErrorException;

class StripePaymentService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create a payment intent for the given order
     *
     * @param array $orderData
     * @return array
     */
    public function createPaymentIntent(array $orderData): array
    {
        try {
            $stripePayload = [
                'amount' => $this->convertToStripeAmount($orderData['total']),
                'currency' => config('services.stripe.currency', 'usd'),
                'metadata' => [
                    'order_id' => $orderData['order_id'] ?? null,
                    'customer_email' => $orderData['customer_email'] ?? '',
                    'customer_name' => $orderData['customer_name'] ?? '',
                ],
                'description' => 'Order from ' . config('app.name'),
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ];

            Log::info('STRIPE API - Creating Payment Intent', [
                'payload' => $stripePayload,
                'order_total' => $orderData['total'],
                'stripe_amount_cents' => $stripePayload['amount']
            ]);

            $paymentIntent = PaymentIntent::create($stripePayload);

            Log::info('STRIPE API - Payment Intent Created Successfully', [
                'payment_intent_id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'status' => $paymentIntent->status,
                'client_secret_prefix' => substr($paymentIntent->client_secret, 0, 20) . '...',
                'payment_method_types' => $paymentIntent->payment_method_types ?? []
            ]);

            return [
                'success' => true,
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
            ];

        } catch (ApiErrorException $e) {
            Log::error('Stripe API Error: ' . $e->getMessage(), [
                'order_data' => $orderData,
                'stripe_error' => $e->getJsonBody()
            ]);

            return [
                'success' => false,
                'error' => 'Payment processing error: ' . $e->getMessage(),
            ];

        } catch (Exception $e) {
            Log::error('Payment Intent Creation Error: ' . $e->getMessage(), [
                'order_data' => $orderData
            ]);

            return [
                'success' => false,
                'error' => 'An unexpected error occurred during payment processing.',
            ];
        }
    }

    /**
     * Retrieve a payment intent by ID
     *
     * @param string $paymentIntentId
     * @return array
     */
    public function retrievePaymentIntent(string $paymentIntentId): array
    {
        try {
            Log::info('STRIPE API - Retrieving Payment Intent', [
                'payment_intent_id' => $paymentIntentId
            ]);

            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            Log::info('STRIPE API - Payment Intent Retrieved', [
                'payment_intent_id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'payment_method' => $paymentIntent->payment_method ?? 'none',
                'payment_method_types' => $paymentIntent->payment_method_types ?? [],
                'last_payment_error' => $paymentIntent->last_payment_error ? [
                    'type' => $paymentIntent->last_payment_error->type ?? null,
                    'code' => $paymentIntent->last_payment_error->code ?? null,
                    'message' => $paymentIntent->last_payment_error->message ?? null,
                    'decline_code' => $paymentIntent->last_payment_error->decline_code ?? null,
                ] : null,
                'charges' => [
                    'total_count' => $paymentIntent->charges->total_count ?? 0,
                    'has_charges' => isset($paymentIntent->charges->data) && count($paymentIntent->charges->data) > 0,
                    'latest_charge_status' => isset($paymentIntent->charges->data[0]) ? $paymentIntent->charges->data[0]->status : null,
                ]
            ]);

            return [
                'success' => true,
                'payment_intent' => $paymentIntent,
                'status' => $paymentIntent->status,
                'amount' => $this->convertFromStripeAmount($paymentIntent->amount),
            ];

        } catch (ApiErrorException $e) {
            Log::error('STRIPE API - Retrieve Error', [
                'payment_intent_id' => $paymentIntentId,
                'error_message' => $e->getMessage(),
                'error_type' => $e->getStripeCode(),
                'http_status' => $e->getHttpStatus()
            ]);

            return [
                'success' => false,
                'error' => 'Could not retrieve payment information.',
            ];
        }
    }

    /**
     * Confirm a payment intent
     *
     * @param string $paymentIntentId
     * @param array $paymentMethodData
     * @return array
     */
    public function confirmPayment(string $paymentIntentId, array $paymentMethodData = []): array
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);

            if ($paymentIntent->status === 'requires_confirmation') {
                $paymentIntent = $paymentIntent->confirm($paymentMethodData);
            }

            return [
                'success' => true,
                'payment_intent' => $paymentIntent,
                'status' => $paymentIntent->status,
                'requires_action' => $paymentIntent->status === 'requires_action',
                'client_secret' => $paymentIntent->client_secret,
            ];

        } catch (ApiErrorException $e) {
            Log::error('Stripe Confirmation Error: ' . $e->getMessage(), [
                'payment_intent_id' => $paymentIntentId
            ]);

            return [
                'success' => false,
                'error' => 'Payment confirmation failed: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel a payment intent
     *
     * @param string $paymentIntentId
     * @return array
     */
    public function cancelPayment(string $paymentIntentId): array
    {
        try {
            $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
            
            if ($paymentIntent->status === 'requires_payment_method' || 
                $paymentIntent->status === 'requires_confirmation') {
                $paymentIntent->cancel();
            }

            return [
                'success' => true,
                'status' => $paymentIntent->status,
            ];

        } catch (ApiErrorException $e) {
            Log::error('Stripe Cancellation Error: ' . $e->getMessage(), [
                'payment_intent_id' => $paymentIntentId
            ]);

            return [
                'success' => false,
                'error' => 'Could not cancel payment.',
            ];
        }
    }

    /**
     * Handle webhook events from Stripe
     *
     * @param array $payload
     * @param string $signature
     * @return array
     */
    public function handleWebhook(array $payload, string $signature): array
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                json_encode($payload),
                $signature,
                config('services.stripe.webhook_secret')
            );

            Log::info('Stripe webhook received', [
                'event_type' => $event['type'],
                'event_id' => $event['id']
            ]);

            // Handle different event types
            switch ($event['type']) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentSucceeded($event['data']['object']);
                    break;
                case 'payment_intent.payment_failed':
                    $this->handlePaymentFailed($event['data']['object']);
                    break;
                default:
                    Log::info('Unhandled webhook event type: ' . $event['type']);
            }

            return [
                'success' => true,
                'event_type' => $event['type'],
            ];

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Webhook signature verification failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'Invalid webhook signature',
            ];

        } catch (Exception $e) {
            Log::error('Webhook processing error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => 'Webhook processing failed',
            ];
        }
    }

    /**
     * Convert dollar amount to Stripe's cents format
     *
     * @param float $amount
     * @return int
     */
    private function convertToStripeAmount(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * Convert Stripe's cents format to dollar amount
     *
     * @param int $amount
     * @return float
     */
    private function convertFromStripeAmount(int $amount): float
    {
        return $amount / 100;
    }

    /**
     * Handle successful payment webhook
     *
     * @param array $paymentIntent
     */
    private function handlePaymentSucceeded(array $paymentIntent): void
    {
        $orderId = $paymentIntent['metadata']['order_id'] ?? null;
        
        if ($orderId) {
            // Update order status to paid
            Log::info('Payment succeeded for order: ' . $orderId, [
                'payment_intent_id' => $paymentIntent['id'],
                'amount' => $this->convertFromStripeAmount($paymentIntent['amount'])
            ]);
            
            // TODO: Update your order status in database
            // You can add order status update logic here
        }
    }

    /**
     * Handle failed payment webhook
     *
     * @param array $paymentIntent
     */
    private function handlePaymentFailed(array $paymentIntent): void
    {
        $orderId = $paymentIntent['metadata']['order_id'] ?? null;
        
        if ($orderId) {
            Log::warning('Payment failed for order: ' . $orderId, [
                'payment_intent_id' => $paymentIntent['id'],
                'failure_reason' => $paymentIntent['last_payment_error']['message'] ?? 'Unknown'
            ]);
            
            // TODO: Update your order status in database
            // You can add order failure handling logic here
        }
    }

    /**
     * Get Stripe publishable key for frontend
     *
     * @return string
     */
    public function getPublishableKey(): string
    {
        return config('services.stripe.key');
    }
}
