<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersGetRequest;
use PayPalHttp\HttpException;

class PaypalPaymentService
{
    protected $client;
    protected $clientId;
    protected $clientSecret;
    protected $mode;

    public function __construct()
    {
        $this->clientId = config('services.paypal.client_id');
        $this->clientSecret = config('services.paypal.client_secret');
        $this->mode = config('services.paypal.mode', 'sandbox');

        $environment = $this->mode === 'live'
            ? new ProductionEnvironment($this->clientId, $this->clientSecret)
            : new SandboxEnvironment($this->clientId, $this->clientSecret);

        $this->client = new PayPalHttpClient($environment);
    }

    public function createOrder(array $orderData): array
    {
        try {
            $request = new OrdersCreateRequest();
            $request->prefer('return=representation');

            $amount = number_format($orderData['total'], 2, '.', '');

            $request->body = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $orderData['order_id'] ?? 'order_' . time(),
                    'amount' => [
                        'currency_code' => config('services.paypal.currency', 'USD'),
                        'value' => $amount
                    ],
                    'description' => 'Order from ' . config('app.name'),
                ]],
                'application_context' => [
                    'brand_name' => config('app.name'),
                    'shipping_preference' => 'NO_SHIPPING',
                    'user_action' => 'PAY_NOW',
                    'return_url' => route('checkout.payment'),
                    'cancel_url' => route('checkout.payment'),
                ]
            ];

            Log::info('PAYPAL API - Creating Order', [
                'payload' => $request->body,
                'order_total' => $orderData['total'],
                'amount' => $amount
            ]);

            $response = $this->client->execute($request);

            Log::info('PAYPAL API - Order Created Successfully', [
                'order_id' => $response->result->id,
                'status' => $response->result->status,
            ]);

            return [
                'success' => true,
                'order_id' => $response->result->id,
                'status' => $response->result->status,
            ];

        } catch (HttpException $ex) {
            Log::error('PAYPAL API - Order Creation Failed (HTTP Exception)', [
                'status_code' => $ex->statusCode,
                'message' => $ex->getMessage(),
                'error_details' => method_exists($ex, 'getMessage') ? $ex->getMessage() : 'Unknown error'
            ]);

            return [
                'success' => false,
                'error' => $ex->getMessage(),
            ];

        } catch (Exception $ex) {
            Log::error('PAYPAL API - Order Creation Failed (General Exception)', [
                'message' => $ex->getMessage(),
                'trace' => $ex->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $ex->getMessage(),
            ];
        }
    }

    public function captureOrder(string $orderId): array
    {
        try {
            $request = new OrdersCaptureRequest($orderId);
            $request->prefer('return=representation');

            Log::info('PAYPAL API - Capturing Order', [
                'order_id' => $orderId
            ]);

            $response = $this->client->execute($request);

            $captureId = null;
            if (isset($response->result->purchase_units[0]->payments->captures[0]->id)) {
                $captureId = $response->result->purchase_units[0]->payments->captures[0]->id;
            }

            Log::info('PAYPAL API - Order Captured Successfully', [
                'order_id' => $orderId,
                'capture_id' => $captureId,
                'status' => $response->result->status,
            ]);

            return [
                'success' => $response->result->status === 'COMPLETED',
                'order_id' => $orderId,
                'capture_id' => $captureId,
                'status' => $response->result->status,
                'payer_email' => $response->result->payer->email_address ?? null,
                'payer_name' => $response->result->payer->name->given_name ?? null,
            ];

        } catch (HttpException $ex) {
            Log::error('PAYPAL API - Order Capture Failed (HTTP Exception)', [
                'order_id' => $orderId,
                'status_code' => $ex->statusCode,
                'message' => $ex->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $ex->getMessage(),
            ];

        } catch (Exception $ex) {
            Log::error('PAYPAL API - Order Capture Failed (General Exception)', [
                'order_id' => $orderId,
                'message' => $ex->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $ex->getMessage(),
            ];
        }
    }

    public function getOrderDetails(string $orderId): array
    {
        try {
            $request = new OrdersGetRequest($orderId);

            Log::info('PAYPAL API - Fetching Order Details', [
                'order_id' => $orderId
            ]);

            $response = $this->client->execute($request);

            Log::info('PAYPAL API - Order Details Retrieved', [
                'order_id' => $orderId,
                'status' => $response->result->status,
            ]);

            return [
                'success' => true,
                'order_id' => $orderId,
                'status' => $response->result->status,
                'details' => $response->result,
            ];

        } catch (HttpException $ex) {
            Log::error('PAYPAL API - Get Order Details Failed (HTTP Exception)', [
                'order_id' => $orderId,
                'status_code' => $ex->statusCode,
                'message' => $ex->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $ex->getMessage(),
            ];

        } catch (Exception $ex) {
            Log::error('PAYPAL API - Get Order Details Failed (General Exception)', [
                'order_id' => $orderId,
                'message' => $ex->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $ex->getMessage(),
            ];
        }
    }

    public function getPublishableKey(): string
    {
        return $this->clientId;
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}
