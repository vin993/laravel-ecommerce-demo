<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShipStationService;
use DB;

class TestShipStationOrderUpdate extends Command
{
    protected $signature = 'test:shipstation-update {order_id}';
    protected $description = 'Test ShipStation order update for a specific order';

    public function handle()
    {
        $orderId = $this->argument('order_id');

        $order = DB::table('orders')->where('id', $orderId)->first();

        if (!$order) {
            $this->error("Order not found with ID: {$orderId}");
            return 1;
        }

        $this->info("Order Details:");
        $this->info("Order ID: {$order->id}");
        $this->info("Increment ID: {$order->increment_id}");
        $this->info("Customer: {$order->customer_first_name} {$order->customer_last_name}");
        $this->info("Email: {$order->customer_email}");
        $this->info("ShipStation Order ID: " . ($order->shipstation_order_id ?? 'NULL'));
        $this->info("ShipStation Order Number: " . ($order->shipstation_order_number ?? 'NULL'));

        if (!$order->shipstation_order_id) {
            $this->warn("This order does not have a ShipStation Order ID. It was likely fulfilled by a dropshipper.");
            return 0;
        }

        $shipStationService = app(ShipStationService::class);

        $this->info("\nFetching current order from ShipStation...");
        $result = $shipStationService->getOrderById($order->shipstation_order_id);

        if (!$result['success']) {
            $this->error("Failed to fetch order from ShipStation: " . $result['error']);
            return 1;
        }

        $shipstationOrder = $result['order'];

        $this->info("\nShipStation Order Details:");
        $this->info("Order ID: " . ($shipstationOrder['orderId'] ?? 'N/A'));
        $this->info("Order Number: " . ($shipstationOrder['orderNumber'] ?? 'N/A'));
        $this->info("Order Status: " . ($shipstationOrder['orderStatus'] ?? 'N/A'));
        $this->info("Customer Email: " . ($shipstationOrder['customerEmail'] ?? 'N/A'));

        if (isset($shipstationOrder['billTo'])) {
            $this->info("\nBilling Address:");
            $this->info("Name: " . ($shipstationOrder['billTo']['name'] ?? 'N/A'));
            $this->info("Address: " . ($shipstationOrder['billTo']['street1'] ?? 'N/A'));
            $this->info("City: " . ($shipstationOrder['billTo']['city'] ?? 'N/A'));
            $this->info("State: " . ($shipstationOrder['billTo']['state'] ?? 'N/A'));
            $this->info("Zip: " . ($shipstationOrder['billTo']['postalCode'] ?? 'N/A'));
            $this->info("Phone: " . ($shipstationOrder['billTo']['phone'] ?? 'N/A'));
        }

        if (isset($shipstationOrder['shipTo'])) {
            $this->info("\nShipping Address:");
            $this->info("Name: " . ($shipstationOrder['shipTo']['name'] ?? 'N/A'));
            $this->info("Address: " . ($shipstationOrder['shipTo']['street1'] ?? 'N/A'));
            $this->info("City: " . ($shipstationOrder['shipTo']['city'] ?? 'N/A'));
            $this->info("State: " . ($shipstationOrder['shipTo']['state'] ?? 'N/A'));
            $this->info("Zip: " . ($shipstationOrder['shipTo']['postalCode'] ?? 'N/A'));
            $this->info("Phone: " . ($shipstationOrder['shipTo']['phone'] ?? 'N/A'));
        }

        $this->info("\n✓ Successfully retrieved order from ShipStation");
        $this->info("\nTo test an update, use the admin panel to edit this order and save changes.");

        return 0;
    }
}
