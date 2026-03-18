<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\CustomerShipmentNotification;

class ShipStationWebhookController extends Controller
{
    public function handle(Request $request)
    {
        Log::channel('shipstation')->info('ShipStation Webhook Received', [
            'payload' => $request->all(),
            'headers' => $request->headers->all()
        ]);

        $resourceType = $request->input('resource_type');
        $resourceUrl = $request->input('resource_url');

        if ($resourceType === 'SHIP_NOTIFY') {
            return $this->handleShipmentNotification($request);
        }

        Log::channel('shipstation')->info('Unhandled webhook type', [
            'resource_type' => $resourceType
        ]);

        return response()->json(['status' => 'received'], 200);
    }

    protected function handleShipmentNotification(Request $request)
    {
        try {
            $orderNumber = $request->input('order_number');
            $trackingNumber = $request->input('tracking_number');
            $carrierCode = $request->input('carrier');
            $shipDate = $request->input('ship_date');

            Log::channel('shipstation')->info('Processing shipment notification', [
                'order_number' => $orderNumber,
                'tracking_number' => $trackingNumber,
                'carrier' => $carrierCode
            ]);

            if (!$orderNumber) {
                Log::channel('shipstation')->warning('No order number in webhook');
                return response()->json(['error' => 'Missing order number'], 400);
            }

            $orderIdMatch = preg_match('/MADD-\d{4}-(\d+)/', $orderNumber, $matches);
            if (!$orderIdMatch) {
                Log::channel('shipstation')->warning('Invalid order number format', [
                    'order_number' => $orderNumber
                ]);
                return response()->json(['error' => 'Invalid order number format'], 400);
            }

            $orderId = (int) $matches[1];

            $order = DB::table('orders')->where('id', $orderId)->first();

            if (!$order) {
                Log::channel('shipstation')->warning('Order not found', [
                    'order_id' => $orderId,
                    'order_number' => $orderNumber
                ]);
                return response()->json(['error' => 'Order not found'], 404);
            }

            $carrierTitle = $this->getCarrierTitle($carrierCode);

            $shipmentId = DB::table('shipments')->insertGetId([
                'order_id' => $orderId,
                'carrier_code' => $carrierCode,
                'carrier_title' => $carrierTitle,
                'track_number' => $trackingNumber,
                'total_qty' => $order->total_qty_ordered ?? 0,
                'total_weight' => 0,
                'status' => 'shipped',
                'email_sent' => 0,
                'customer_id' => $order->customer_id,
                'customer_type' => $order->customer_type ?? 'Webkul\Customer\Models\Customer',
                'inventory_source_id' => 1,
                'inventory_source_name' => 'Default',
                'created_at' => now(),
                'updated_at' => now()
            ]);

            DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'status' => 'processing',
                    'updated_at' => now()
                ]);

            $orderAddress = DB::table('addresses')
                ->where('order_id', $orderId)
                ->where('address_type', 'order_shipping')
                ->first();

            $customer = DB::table('customers')->where('id', $order->customer_id)->first();

            if ($customer && $customer->email) {
                $trackingUrl = $this->getTrackingUrl($carrierCode, $trackingNumber);

                Mail::to($customer->email)->send(new CustomerShipmentNotification([
                    'order' => $order,
                    'shipment' => (object)[
                        'id' => $shipmentId,
                        'carrier_title' => $carrierTitle,
                        'track_number' => $trackingNumber,
                        'tracking_url' => $trackingUrl,
                        'created_at' => now()
                    ],
                    'customer' => $customer,
                    'shipping_address' => $orderAddress
                ]));

                DB::table('shipments')
                    ->where('id', $shipmentId)
                    ->update(['email_sent' => 1]);

                Log::channel('shipstation')->info('Shipment notification email sent', [
                    'order_id' => $orderId,
                    'customer_email' => $customer->email,
                    'tracking_number' => $trackingNumber
                ]);
            }

            Log::channel('shipstation')->info('Shipment created successfully', [
                'shipment_id' => $shipmentId,
                'order_id' => $orderId,
                'tracking_number' => $trackingNumber
            ]);

            return response()->json([
                'status' => 'success',
                'shipment_id' => $shipmentId
            ], 200);

        } catch (\Exception $e) {
            Log::channel('shipstation')->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payload' => $request->all()
            ]);

            return response()->json(['error' => 'Processing failed'], 500);
        }
    }

    protected function getCarrierTitle($carrierCode)
    {
        $carriers = [
            'ups' => 'UPS',
            'usps' => 'USPS',
            'fedex' => 'FedEx',
            'dhl_express' => 'DHL Express',
            'stamps_com' => 'USPS',
            'ups_walleted' => 'UPS',
            'fedex_walleted' => 'FedEx'
        ];

        return $carriers[$carrierCode] ?? strtoupper($carrierCode);
    }

    protected function getTrackingUrl($carrierCode, $trackingNumber)
    {
        $urls = [
            'ups' => 'https://www.ups.com/track?tracknum=' . $trackingNumber,
            'ups_walleted' => 'https://www.ups.com/track?tracknum=' . $trackingNumber,
            'usps' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $trackingNumber,
            'stamps_com' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $trackingNumber,
            'fedex' => 'https://www.fedex.com/fedextrack/?tracknumbers=' . $trackingNumber,
            'fedex_walleted' => 'https://www.fedex.com/fedextrack/?tracknumbers=' . $trackingNumber,
            'dhl_express' => 'https://www.dhl.com/en/express/tracking.html?AWB=' . $trackingNumber
        ];

        return $urls[$carrierCode] ?? '#';
    }
}
