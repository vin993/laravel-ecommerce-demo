<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\CustomerShipmentNotification;

class TestShipStationWebhook extends Command
{
    protected $signature = 'test:shipstation-webhook {order_id} {tracking_number} {--carrier=ups}';

    protected $description = 'Test ShipStation webhook processing by simulating a shipment notification';

    public function handle()
    {
        $orderId = $this->argument('order_id');
        $trackingNumber = $this->argument('tracking_number');
        $carrierCode = $this->option('carrier');

        $this->info("Testing ShipStation webhook for Order ID: {$orderId}");

        $order = DB::table('orders')->where('id', $orderId)->first();

        if (!$order) {
            $this->error("Order #{$orderId} not found");
            return 1;
        }

        $this->info("Order found: #{$order->increment_id}");

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

        $this->info("Shipment created: ID #{$shipmentId}");

        DB::table('orders')
            ->where('id', $orderId)
            ->update([
                'status' => 'processing',
                'updated_at' => now()
            ]);

        $this->info("Order status updated to: processing");

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

            $this->info("Email sent to: {$customer->email}");
        } else {
            $this->warn("No customer email found - skipping email");
        }

        $this->line('');
        $this->info('Shipment Details:');
        $this->table(
            ['Field', 'Value'],
            [
                ['Shipment ID', $shipmentId],
                ['Order ID', $orderId],
                ['Order Number', $order->increment_id],
                ['Carrier', $carrierTitle],
                ['Tracking Number', $trackingNumber],
                ['Tracking URL', $trackingUrl ?? 'N/A'],
                ['Customer Email', $customer->email ?? 'N/A']
            ]
        );

        $this->line('');
        $this->info('Test completed successfully');

        return 0;
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

        return $urls[$carrierCode] ?? null;
    }
}
