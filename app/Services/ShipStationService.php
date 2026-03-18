<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ShipStationService
{
    protected $apiKey;
    protected $secretKey;
    protected $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('SHIPSTATION_API_KEY');
        $this->secretKey = env('SHIPSTATION_SECRET_KEY');
        $this->baseUrl = env('SHIPSTATION_BASE_URL');
    }

    public function testConnection()
    {
        try {
            Log::info('ShipStation API Test', [
                'base_url' => $this->baseUrl,
                'has_api_key' => !empty($this->apiKey),
                'api_key_length' => strlen($this->apiKey ?? ''),
                'has_secret' => !empty($this->secretKey),
                'secret_length' => strlen($this->secretKey ?? '')
            ]);

            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/stores");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'status' => $response->status(),
                    'data' => $data,
                    'error' => null
                ];
            } else {
                $statusCode = $response->status();
                $errorBody = $response->body();

                $errorMessage = $errorBody;
                switch ($statusCode) {
                    case 401:
                        $errorMessage = 'Unauthorized - Check your API Key and Secret Key';
                        break;
                    case 403:
                        $errorMessage = 'Forbidden - API access may be disabled for your account';
                        break;
                    case 404:
                        $errorMessage = 'Not Found - Invalid API endpoint';
                        break;
                    case 429:
                        $errorMessage = 'Rate Limited - Too many requests';
                        break;
                    case 500:
                        $errorMessage = 'Server Error - ShipStation API is experiencing issues';
                        break;
                }

                return [
                    'success' => false,
                    'status' => $statusCode,
                    'data' => null,
                    'error' => "HTTP {$statusCode}: {$errorMessage}"
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getStores()
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/stores");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'stores' => $response->json()
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function createOrder($orderData)
    {
        if (!$this->apiKey || !$this->secretKey) {
            throw new Exception('ShipStation API credentials not configured');
        }

        $orderNumber = 'MADD-' . date('Y') . '-' . str_pad($orderData['order_id'] ?? rand(100000, 999999), 6, '0', STR_PAD_LEFT);

        Log::channel('shipstation')->info('ShipStation Order Creation Started', [
            'order_number' => $orderNumber,
            'items_count' => count($orderData['items'] ?? []),
            'customer' => $orderData['customer_name'] ?? 'Unknown',
            'email' => $orderData['customer_email'] ?? 'Unknown'
        ]);

        try {
            $shipStationOrder = [
                'orderNumber' => $orderNumber,
                'orderKey' => $orderNumber,
                'orderDate' => date('c'),
                'paymentDate' => date('c'),
                'orderStatus' => 'awaiting_shipment',
                'customerUsername' => $orderData['customer_email'] ?? '',
                'customerEmail' => $orderData['customer_email'] ?? '',
                'billTo' => [
                    'name' => $orderData['customer_name'] ?? '',
                    'company' => $orderData['company'] ?? '',
                    'street1' => $orderData['billing_address1'] ?? $orderData['shipping_address1'] ?? '',
                    'street2' => $orderData['billing_address2'] ?? $orderData['shipping_address2'] ?? '',
                    'street3' => $orderData['billing_address3'] ?? $orderData['shipping_address3'] ?? '',
                    'city' => $orderData['billing_city'] ?? $orderData['shipping_city'] ?? '',
                    'state' => $orderData['billing_state'] ?? $orderData['shipping_state'] ?? '',
                    'postalCode' => $orderData['billing_zip'] ?? $orderData['shipping_zip'] ?? '',
                    'country' => 'US',
                    'phone' => $orderData['phone'] ?? '',
                    'residential' => true
                ],
                'shipTo' => [
                    'name' => $orderData['customer_name'] ?? '',
                    'company' => $orderData['company'] ?? '',
                    'street1' => $orderData['shipping_address1'] ?? '',
                    'street2' => $orderData['shipping_address2'] ?? '',
                    'street3' => $orderData['shipping_address3'] ?? '',
                    'city' => $orderData['shipping_city'] ?? '',
                    'state' => $orderData['shipping_state'] ?? '',
                    'postalCode' => $orderData['shipping_zip'] ?? '',
                    'country' => 'US',
                    'phone' => $orderData['phone'] ?? '',
                    'residential' => true
                ],
                'items' => [],
                'amountPaid' => $orderData['total_amount'] ?? 0,
                'taxAmount' => $orderData['tax_amount'] ?? 0,
                'shippingAmount' => $orderData['shipping_amount'] ?? 0,
                'customerNotes' => $orderData['customer_notes'] ?? '',
                'internalNotes' => 'MaddParts In-House Order - ' . date('Y-m-d H:i:s'),
                'gift' => false,
                'giftMessage' => '',
                'paymentMethod' => $orderData['payment_method'] ?? 'Credit Card',
                'requestedShippingService' => $orderData['shipping_method'] ?? 'Ground',
                'advancedOptions' => [
                    'storeId' => 793849
                ]
            ];

            foreach ($orderData['items'] as $item) {
                $shipStationOrder['items'][] = [
                    'lineItemKey' => $item['id'] ?? uniqid(),
                    'sku' => $item['sku'] ?? '',
                    'name' => $item['name'] ?? '',
                    'imageUrl' => $item['image_url'] ?? '',
                    'weight' => [
                        'value' => $item['weight'] ?? 1,
                        'units' => 'pounds'
                    ],
                    'quantity' => $item['quantity'] ?? 1,
                    'unitPrice' => $item['unit_price'] ?? 0,
                    'taxAmount' => $item['tax_amount'] ?? 0,
                    'shippingAmount' => 0,
                    'warehouseLocation' => $item['warehouse_location'] ?? '',
                    'options' => [],
                    'productId' => $item['product_id'] ?? null,
                    'fulfillmentSku' => $item['sku'] ?? '',
                    'adjustment' => false,
                    'upc' => $item['upc'] ?? ''
                ];
            }

            Log::channel('shipstation')->info('ShipStation Order Payload', [
                'order_number' => $orderNumber,
                'payload' => $shipStationOrder
            ]);

            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(30)
                ->post("{$this->baseUrl}/orders/createorder", $shipStationOrder);

            Log::channel('shipstation')->info('ShipStation API Response', [
                'order_number' => $orderNumber,
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::channel('shipstation')->info('ShipStation Order Created Successfully', [
                    'order_number' => $orderNumber,
                    'shipstation_order_id' => $responseData['orderId'] ?? null,
                    'shipstation_order_key' => $responseData['orderKey'] ?? null,
                    'order_status' => $responseData['orderStatus'] ?? null
                ]);

                return [
                    'success' => true,
                    'order_number' => $orderNumber,
                    'shipstation_order_id' => $responseData['orderId'] ?? null,
                    'shipstation_order_key' => $responseData['orderKey'] ?? null,
                    'order_status' => $responseData['orderStatus'] ?? 'awaiting_shipment',
                    'response_data' => $responseData
                ];
            }

            Log::channel('shipstation')->error('ShipStation Order Creation Failed', [
                'order_number' => $orderNumber,
                'http_status' => $response->status(),
                'response_body' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            Log::channel('shipstation')->error('ShipStation Order Creation Exception', [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getOrder($orderNumber)
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/orders", [
                    'orderNumber' => $orderNumber
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'orders' => $data['orders'] ?? [],
                    'total' => $data['total'] ?? 0
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getOrderById($orderId)
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/orders/{$orderId}");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'order' => $response->json()
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function updateOrder($shipstationOrderId, $updateData)
    {
        if (!$this->apiKey || !$this->secretKey) {
            return [
                'success' => false,
                'error' => 'ShipStation API credentials not configured'
            ];
        }

        Log::channel('shipstation')->info('ShipStation Order Update Started', [
            'shipstation_order_id' => $shipstationOrderId,
            'update_data_keys' => array_keys($updateData)
        ]);

        try {
            $existingOrderResult = $this->getOrderById($shipstationOrderId);

            if (!$existingOrderResult['success']) {
                Log::channel('shipstation')->error('Failed to fetch existing ShipStation order', [
                    'shipstation_order_id' => $shipstationOrderId,
                    'error' => $existingOrderResult['error']
                ]);

                return [
                    'success' => false,
                    'error' => 'Could not fetch existing order from ShipStation'
                ];
            }

            $existingOrder = $existingOrderResult['order'];

            $billingName = trim(
                (isset($updateData['billing_first_name']) && isset($updateData['billing_last_name']))
                    ? $updateData['billing_first_name'] . ' ' . $updateData['billing_last_name']
                    : $existingOrder['billTo']['name']
            );

            $shippingName = trim(
                (isset($updateData['shipping_first_name']) && isset($updateData['shipping_last_name']))
                    ? $updateData['shipping_first_name'] . ' ' . $updateData['shipping_last_name']
                    : $existingOrder['shipTo']['name']
            );

            $billingStreet1 = $updateData['billing_address1'] ?? $existingOrder['billTo']['street1'];
            $billingCity = $updateData['billing_city'] ?? $existingOrder['billTo']['city'];
            $billingState = $updateData['billing_state'] ?? $existingOrder['billTo']['state'];
            $billingPostcode = $updateData['billing_postcode'] ?? $existingOrder['billTo']['postalCode'];
            $billingCountry = $updateData['billing_country'] ?? $existingOrder['billTo']['country'] ?? 'US';
            $billingPhone = $updateData['billing_phone'] ?? $existingOrder['billTo']['phone'] ?? '';

            $shippingStreet1 = $updateData['shipping_address1'] ?? $existingOrder['shipTo']['street1'];
            if (empty($shippingStreet1)) {
                $shippingStreet1 = $billingStreet1;
            }

            $shippingCity = $updateData['shipping_city'] ?? $existingOrder['shipTo']['city'];
            if (empty($shippingCity)) {
                $shippingCity = $billingCity;
            }

            $shippingState = $updateData['shipping_state'] ?? $existingOrder['shipTo']['state'];
            if (empty($shippingState)) {
                $shippingState = $billingState;
            }

            $shippingPostcode = $updateData['shipping_postcode'] ?? $existingOrder['shipTo']['postalCode'];
            if (empty($shippingPostcode)) {
                $shippingPostcode = $billingPostcode;
            }

            $shippingPhone = $updateData['shipping_phone'] ?? $existingOrder['shipTo']['phone'] ?? '';
            if (empty($shippingPhone)) {
                $shippingPhone = $billingPhone;
            }

            $shippingCountry = $updateData['shipping_country'] ?? $existingOrder['shipTo']['country'] ?? 'US';
            if (empty($shippingCountry)) {
                $shippingCountry = $billingCountry;
            }

            $updatedOrder = array_merge($existingOrder, [
                'orderId' => $shipstationOrderId,
                'orderNumber' => $existingOrder['orderNumber'],
                'orderKey' => $existingOrder['orderKey'],
                'orderDate' => $existingOrder['orderDate'],
                'orderStatus' => $existingOrder['orderStatus'],
                'customerEmail' => $updateData['customer_email'] ?? $existingOrder['customerEmail'],
                'billTo' => [
                    'name' => $billingName,
                    'company' => $existingOrder['billTo']['company'] ?? '',
                    'street1' => $billingStreet1,
                    'street2' => $updateData['billing_address2'] ?? $existingOrder['billTo']['street2'] ?? '',
                    'street3' => $updateData['billing_address3'] ?? $existingOrder['billTo']['street3'] ?? '',
                    'city' => $billingCity,
                    'state' => $billingState,
                    'postalCode' => $billingPostcode,
                    'country' => $billingCountry,
                    'phone' => $billingPhone,
                    'residential' => true
                ],
                'shipTo' => [
                    'name' => $shippingName,
                    'company' => $existingOrder['shipTo']['company'] ?? '',
                    'street1' => $shippingStreet1,
                    'street2' => $updateData['shipping_address2'] ?? $existingOrder['shipTo']['street2'] ?? '',
                    'street3' => $updateData['shipping_address3'] ?? $existingOrder['shipTo']['street3'] ?? '',
                    'city' => $shippingCity,
                    'state' => $shippingState,
                    'postalCode' => $shippingPostcode,
                    'country' => $shippingCountry,
                    'phone' => $shippingPhone,
                    'residential' => true
                ],
                'items' => $existingOrder['items'] ?? [],
                'amountPaid' => $existingOrder['amountPaid'],
                'taxAmount' => $existingOrder['taxAmount'],
                'shippingAmount' => $existingOrder['shippingAmount'],
                'customerNotes' => $existingOrder['customerNotes'] ?? '',
                'internalNotes' => $existingOrder['internalNotes'] ?? '',
                'paymentMethod' => $existingOrder['paymentMethod'] ?? '',
                'requestedShippingService' => $existingOrder['requestedShippingService'] ?? '',
                'advancedOptions' => $existingOrder['advancedOptions'] ?? ['storeId' => 793849]
            ]);

            Log::channel('shipstation')->info('ShipStation Update Payload', [
                'shipstation_order_id' => $shipstationOrderId,
                'payload' => $updatedOrder
            ]);

            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(30)
                ->post("{$this->baseUrl}/orders/createorder", $updatedOrder);

            $responseBody = $response->body();
            $responseJson = null;

            try {
                $responseJson = $response->json();
            } catch (\Exception $e) {
                $responseJson = null;
            }

            Log::channel('shipstation')->info('ShipStation Update API Response', [
                'shipstation_order_id' => $shipstationOrderId,
                'http_status' => $response->status(),
                'response_body' => $responseBody,
                'response_json' => $responseJson,
                'successful' => $response->successful()
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                Log::channel('shipstation')->info('ShipStation Order Updated Successfully', [
                    'shipstation_order_id' => $shipstationOrderId,
                    'updated_order_id' => $responseData['orderId'] ?? null
                ]);

                return [
                    'success' => true,
                    'shipstation_order_id' => $responseData['orderId'] ?? $shipstationOrderId,
                    'response_data' => $responseData
                ];
            }

            Log::channel('shipstation')->error('ShipStation Order Update Failed', [
                'shipstation_order_id' => $shipstationOrderId,
                'http_status' => $response->status(),
                'response_body' => $response->body()
            ]);

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];

        } catch (Exception $e) {
            Log::channel('shipstation')->error('ShipStation Order Update Exception', [
                'shipstation_order_id' => $shipstationOrderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getShipments($orderNumber = null)
    {
        try {
            $params = [];
            if ($orderNumber) {
                $params['orderNumber'] = $orderNumber;
            }

            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/shipments", $params);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'shipments' => $data['shipments'] ?? [],
                    'total' => $data['total'] ?? 0
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function createShipment($shipmentData)
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(30)
                ->post("{$this->baseUrl}/shipments/createlabel", $shipmentData);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'shipment' => $response->json()
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getCarriers()
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/carriers");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'carriers' => $response->json()
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function getShippingMethods()
    {
        try {
            $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/carriers");

            if ($response->successful()) {
                $carriers = $response->json();
                $shippingMethods = [];

                $defaultServices = [
                    'ups' => [
                        ['code' => 'ups_ground', 'name' => 'Ground'],
                        ['code' => 'ups_3_day_select', 'name' => '3 Day Select'],
                        ['code' => 'ups_2nd_day_air', 'name' => '2nd Day Air'],
                        ['code' => 'ups_next_day_air', 'name' => 'Next Day Air']
                    ],
                    'fedex' => [
                        ['code' => 'fedex_ground', 'name' => 'Ground'],
                        ['code' => 'fedex_2_day', 'name' => '2Day'],
                        ['code' => 'fedex_express_saver', 'name' => 'Express Saver'],
                        ['code' => 'fedex_standard_overnight', 'name' => 'Standard Overnight']
                    ],
                    'stamps_com' => [
                        ['code' => 'usps_priority_mail', 'name' => 'Priority Mail'],
                        ['code' => 'usps_ground_advantage', 'name' => 'Ground Advantage'],
                        ['code' => 'usps_priority_mail_express', 'name' => 'Priority Mail Express']
                    ],
                    'ups_walleted' => [
                        ['code' => 'ups_ground', 'name' => 'Ground'],
                        ['code' => 'ups_3_day_select', 'name' => '3 Day Select'],
                        ['code' => 'ups_2nd_day_air', 'name' => '2nd Day Air']
                    ],
                    'fedex_walleted' => [
                        ['code' => 'fedex_ground', 'name' => 'Ground'],
                        ['code' => 'fedex_2_day', 'name' => '2Day'],
                        ['code' => 'fedex_express_saver', 'name' => 'Express Saver']
                    ],
                    'dhl_express_worldwide' => [
                        ['code' => 'dhl_express', 'name' => 'Express'],
                        ['code' => 'dhl_express_worldwide', 'name' => 'Express Worldwide']
                    ],
                    'globalpost' => [
                        ['code' => 'globalpost_standard', 'name' => 'Standard']
                    ],
                    'seko_ltl_walleted' => [
                        ['code' => 'seko_ltl', 'name' => 'LTL Service']
                    ]
                ];

                foreach ($carriers as $carrier) {
                    $carrierCode = $carrier['code'] ?? '';
                    $carrierName = $carrier['name'] ?? 'Unknown';
                    $nickname = $carrier['nickname'] ?? '';
                    $displayName = $nickname ? "$carrierName ($nickname)" : $carrierName;

                    if (isset($carrier['services']) && is_array($carrier['services'])) {
                        foreach ($carrier['services'] as $service) {
                            $shippingMethods[] = [
                                'code' => $carrierCode . '_' . $service['code'],
                                'name' => $displayName . ' - ' . $service['name'],
                                'carrier_code' => $carrierCode,
                                'service_code' => $service['code'],
                                'carrier_name' => $carrierName,
                                'service_name' => $service['name']
                            ];
                        }
                    } else {
                        $services = $defaultServices[$carrierCode] ?? [['code' => 'standard', 'name' => 'Standard']];

                        foreach ($services as $service) {
                            $shippingMethods[] = [
                                'code' => $carrierCode . '_' . $service['code'],
                                'name' => $displayName . ' - ' . $service['name'],
                                'carrier_code' => $carrierCode,
                                'service_code' => $service['code'],
                                'carrier_name' => $carrierName,
                                'service_name' => $service['name']
                            ];
                        }
                    }
                }

                return [
                    'success' => true,
                    'methods' => $shippingMethods
                ];
            }

            return [
                'success' => false,
                'error' => 'HTTP ' . $response->status() . ': ' . $response->body(),
                'methods' => []
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'methods' => []
            ];
        }
    }

    public function calculateShippingRates($shipToAddress, $items, $packageWeight = null)
    {
        if (!$this->apiKey || !$this->secretKey) {
            return [
                'success' => false,
                'error' => 'ShipStation API credentials not configured',
                'rates' => []
            ];
        }

        try {
            if (!$packageWeight) {
                $packageWeight = 0;
                foreach ($items as $item) {
                    $itemWeight = $item['weight'] ?? 1;
                    $packageWeight += $itemWeight * ($item['quantity'] ?? 1);
                }
                $packageWeight = max($packageWeight, 1);
            }

            $carrierAccounts = [
                ['code' => 'ups', 'shippingProviderId' => 1868096, 'name' => 'UPS Main'],
                ['code' => 'ups', 'shippingProviderId' => 1873220, 'name' => 'UPS 2-Day'],
                ['code' => 'stamps_com', 'shippingProviderId' => 222774, 'name' => 'USPS']
            ];

            $rateRequestBase = [
                'packageCode' => 'package',
                'fromPostalCode' => env('SHIPSTATION_FROM_ZIP', '72416'),
                'toState' => $shipToAddress['state'] ?? '',
                'toPostalCode' => $shipToAddress['postalCode'] ?? '',
                'toCountry' => $shipToAddress['country'] ?? 'US',
                'toCity' => $shipToAddress['city'] ?? '',
                'weight' => [
                    'value' => $packageWeight,
                    'units' => 'pounds'
                ],
                'dimensions' => [
                    'units' => 'inches',
                    'length' => 12,
                    'width' => 9,
                    'height' => 6
                ],
                'confirmation' => 'none',
                'residential' => true
            ];

            Log::channel('shipstation')->info('ShipStation Rate Request', [
                'carrier_accounts' => array_map(function($acc) { return $acc['name'] . ' (Provider: ' . $acc['shippingProviderId'] . ')'; }, $carrierAccounts),
                'items_count' => count($items),
                'total_weight' => $packageWeight
            ]);

            $allRates = [];

            foreach ($carrierAccounts as $carrierAccount) {
                try {
                    $rateRequest = array_merge($rateRequestBase, [
                        'carrierCode' => $carrierAccount['code']
                    ]);

                    $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
                        ->timeout(15)
                        ->post("{$this->baseUrl}/shipments/getrates", $rateRequest);

                    if ($response->successful()) {
                        $rates = $response->json();

                        Log::channel('shipstation')->info("ShipStation {$carrierAccount['name']} Rate Response", [
                            'carrier' => $carrierAccount['code'],
                            'provider_id' => $carrierAccount['shippingProviderId'],
                            'total_rates' => count($rates ?? []),
                            'sample_rate' => isset($rates[0]) ? $rates[0] : null
                        ]);

                        if (isset($rates) && is_array($rates)) {
                            foreach ($rates as $rate) {
                                $rate['_carrierAccountName'] = $carrierAccount['name'];
                                $rate['_shippingProviderId'] = $carrierAccount['shippingProviderId'];
                                $allRates[] = $rate;
                            }
                        }
                    } else {
                        $errorResponse = $response->body();

                        Log::channel('shipstation')->error("ShipStation {$carrierAccount['name']} Rate Request Failed", [
                            'carrier' => $carrierAccount['code'],
                            'provider_id' => $carrierAccount['shippingProviderId'],
                            'status' => $response->status(),
                            'response' => $errorResponse
                        ]);
                    }
                } catch (Exception $carrierException) {
                    Log::channel('shipstation')->error("ShipStation {$carrierAccount['name']} Rate Request Exception", [
                        'carrier' => $carrierAccount['code'],
                        'error' => $carrierException->getMessage()
                    ]);
                }
            }

            Log::channel('shipstation')->info('ShipStation All Rates Collected', [
                'total_rates' => count($allRates)
            ]);

            $groupedRates = [];
            $formattedRates = [];

            if (!empty($allRates)) {
                foreach ($allRates as $rate) {
                    $serviceCode = $rate['serviceCode'] ?? 'standard';
                    $carrierCode = $rate['carrierCode'] ?? 'ups';

                    $carrierName = strtoupper($carrierCode);
                    if ($carrierCode === 'stamps_com') {
                        $carrierName = 'USPS';
                    } elseif ($carrierCode === 'ups') {
                        $carrierName = 'UPS';
                    }

                    $serviceName = $rate['serviceName'] ?? $this->getServiceName($serviceCode);

                    $shipmentCost = (float) ($rate['shipmentCost'] ?? 0);
                    $otherCost = (float) ($rate['otherCost'] ?? 0);
                    $totalCost = $shipmentCost + $otherCost;

                    $deliveryDays = $rate['deliveryDays'] ?? null;
                    $guaranteedService = $rate['guaranteedService'] ?? false;

                    $rateCodePrefix = ($carrierCode === 'stamps_com') ? 'usps' : $carrierCode;

                    $rateData = [
                        'code' => $rateCodePrefix . '_' . $serviceCode,
                        'name' => $carrierName . ' - ' . $serviceName,
                        'carrier_code' => $carrierCode,
                        'service_code' => $serviceCode,
                        'carrier_name' => $carrierName,
                        'service_name' => $serviceName,
                        'rate' => $totalCost,
                        'delivery_days' => $deliveryDays,
                        'guaranteed_service' => $guaranteedService,
                        'carrier_icon' => $this->getCarrierIcon($carrierCode)
                    ];

                    if (!isset($groupedRates[$carrierName])) {
                        $groupedRates[$carrierName] = [
                            'carrier_name' => $carrierName,
                            'carrier_code' => $carrierCode,
                            'carrier_icon' => $this->getCarrierIcon($carrierCode),
                            'services' => []
                        ];
                    }

                    $groupedRates[$carrierName]['services'][] = $rateData;
                    $formattedRates[] = $rateData;
                }

                foreach ($groupedRates as &$carrierGroup) {
                    usort($carrierGroup['services'], function($a, $b) {
                        return $a['rate'] <=> $b['rate'];
                    });
                }

                ksort($groupedRates);

                usort($formattedRates, function($a, $b) {
                    return $a['rate'] <=> $b['rate'];
                });

                Log::channel('shipstation')->info('ShipStation All Rates Collected and Grouped', [
                    'total_rates' => count($formattedRates),
                    'carriers_count' => count($groupedRates),
                    'sample_rate_structure' => $allRates[0] ?? null,
                    'all_rate_keys' => !empty($allRates) ? array_keys($allRates[0]) : [],
                    'grouped_carriers' => array_keys($groupedRates),
                    'grouped_rates' => array_map(function($group) {
                        return [
                            'carrier_name' => $group['carrier_name'],
                            'services_count' => count($group['services']),
                            'sample_service' => $group['services'][0]['service_name'] ?? 'none'
                        ];
                    }, $groupedRates)
                ]);

                return [
                    'success' => true,
                    'rates' => $formattedRates,
                    'grouped_rates' => $groupedRates
                ];
            }

            Log::channel('shipstation')->warning('No rates retrieved from any carrier service');

            return [
                'success' => false,
                'error' => 'Shipping rates are currently unavailable',
                'rates' => [],
                'grouped_rates' => []
            ];

        } catch (Exception $e) {
            Log::channel('shipstation')->error('ShipStation Rate Calculation Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'error' => 'Shipping rates are currently unavailable',
                'rates' => [],
                'grouped_rates' => []
            ];
        }
    }

    private function getCarrierIcon($carrierCode)
    {
        $iconMap = [
            'ups' => 'fas fa-truck text-brown',
            'ups_walleted' => 'fas fa-truck text-brown',
            'fedex' => 'fas fa-shipping-fast text-purple',
            'fedex_walleted' => 'fas fa-shipping-fast text-purple',
            'stamps_com' => 'fas fa-envelope text-blue',
            'usps' => 'fas fa-envelope text-blue',
            'dhl_express' => 'fas fa-plane text-yellow',
            'dhl_express_worldwide' => 'fas fa-plane text-yellow',
            'dhl' => 'fas fa-plane text-yellow',
            'globalpost' => 'fas fa-globe text-green',
            'seko_ltl_walleted' => 'fas fa-warehouse text-orange',
            'seko' => 'fas fa-warehouse text-orange',
            'unknown' => 'fas fa-box text-gray'
        ];

        return $iconMap[strtolower($carrierCode)] ?? $iconMap['unknown'];
    }

    private function getCarrierName($carrierCode)
    {
        $carrierNames = [
            'ups' => 'UPS',
            'ups_walleted' => 'UPS',
            'fedex' => 'FedEx',
            'fedex_walleted' => 'FedEx',
            'stamps_com' => 'USPS',
            'usps' => 'USPS',
            'dhl_express_worldwide' => 'DHL Express',
            'dhl_express' => 'DHL Express',
            'dhl' => 'DHL Express',
            'globalpost' => 'GlobalPost',
            'seko_ltl_walleted' => 'SEKO LTL',
            'seko' => 'SEKO LTL',
            'unknown' => 'Unknown Carrier'
        ];

        $cleanCode = strtolower($carrierCode);
        if (isset($carrierNames[$cleanCode])) {
            return $carrierNames[$cleanCode];
        }

        if (strpos($cleanCode, 'ups') === 0) return 'UPS';
        if (strpos($cleanCode, 'fedex') === 0) return 'FedEx';
        if (strpos($cleanCode, 'usps') === 0) return 'USPS';
        if (strpos($cleanCode, 'dhl') === 0) return 'DHL Express';
        if (strpos($cleanCode, 'globalpost') === 0) return 'GlobalPost';

        return 'Unknown Carrier';
    }

    private function getServiceName($serviceCode)
    {
        $serviceNames = [
            'ups_ground' => 'Ground',
            'ups_surepost_1_lb_or_greater' => 'SurePost',
            'ups_2nd_day_air' => '2nd Day Air',
            'ups_next_day_air' => 'Next Day Air',
            'ups_next_day_air_saver' => 'Next Day Air Saver',
            'ups_next_day_air_early_am' => 'Next Day Air Early AM',
            'ups_3_day_select' => '3 Day Select',
            'fedex_ground' => 'Ground',
            'fedex_home_delivery' => 'Home Delivery',
            'fedex_smartpost_parcel_select' => 'SmartPost',
            'fedex_2_day' => '2Day',
            'fedex_2_day_am' => '2Day A.M.',
            'fedex_express_saver' => 'Express Saver',
            'fedex_standard_overnight' => 'Standard Overnight',
            'fedex_priority_overnight' => 'Priority Overnight',
            'fedex_first_overnight' => 'First Overnight',
            'usps_ground_advantage' => 'Ground Advantage',
            'usps_first_class_mail' => 'First-Class Mail',
            'usps_priority_mail' => 'Priority Mail',
            'usps_priority_mail_express' => 'Priority Mail Express',
            'usps_media_mail' => 'Media Mail',
            'dhl_express_worldwide' => 'Express Worldwide',
            'dhl_express_12_00' => 'Express 12:00',
            'dhl_express_10_30' => 'Express 10:30',
            'dhl_express_9_00' => 'Express 9:00',
            'globalpost_standard' => 'Standard',
            'globalpost_plus' => 'Plus',
            'globalpost_priority' => 'Priority',
            'standard' => 'Standard Service'
        ];

        $cleanCode = strtolower($serviceCode);

        if (isset($serviceNames[$cleanCode])) {
            return $serviceNames[$cleanCode];
        }

        $parts = explode('_', $cleanCode);
        if (count($parts) > 1) {
            array_shift($parts);
            $serviceName = implode(' ', array_map('ucfirst', $parts));

            $serviceName = str_replace([
                '1 Lb Or Greater',
                'Parcel Select',
                'Air Early Am',
                '2nd Day Air',
                'Next Day Air'
            ], [
                '',
                'SmartPost',
                'Air Early A.M.',
                '2nd Day Air',
                'Next Day Air'
            ], $serviceName);

            return trim($serviceName) ?: 'Service';
        }

        return 'Service';
    }

    private function extractCarrierFromServiceName($serviceName)
    {
        $serviceName = strtolower($serviceName);

        if (strpos($serviceName, 'ups') !== false) return 'UPS';
        if (strpos($serviceName, 'fedex') !== false) return 'FedEx';
        if (strpos($serviceName, 'usps') !== false) return 'USPS';
        if (strpos($serviceName, 'dhl') !== false) return 'DHL Express';
        if (strpos($serviceName, 'globalpost') !== false || strpos($serviceName, 'global post') !== false) return 'GlobalPost';
        if (strpos($serviceName, 'seko') !== false) return 'SEKO LTL';
        if (strpos($serviceName, 'surepost') !== false) return 'UPS';
        if (strpos($serviceName, 'smartpost') !== false) return 'FedEx';
        if (strpos($serviceName, 'priority') !== false || strpos($serviceName, 'ground advantage') !== false) return 'USPS';
        if (strpos($serviceName, 'promise') !== false) return 'GlobalPost';
        if (strpos($serviceName, 'express') !== false && strpos($serviceName, 'fedex') === false) return 'DHL Express';

        return 'Unknown Carrier';
    }

}
