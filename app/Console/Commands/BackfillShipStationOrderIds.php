<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShipStationService;
use DB;

class BackfillShipStationOrderIds extends Command
{
    protected $signature = 'shipstation:backfill-order-ids {--limit=10}';
    protected $description = 'Backfill ShipStation order IDs for existing orders';

    public function handle()
    {
        $shipStationService = app(ShipStationService::class);
        $limit = $this->option('limit');

        $orders = DB::table('orders')
            ->whereNull('shipstation_order_id')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('No orders found without ShipStation IDs.');
            return 0;
        }

        $this->info("Found {$orders->count()} orders to check.");

        $bar = $this->output->createProgressBar($orders->count());
        $bar->start();

        $updated = 0;
        $notFound = 0;

        foreach ($orders as $order) {
            $orderNumber = 'MADD-' . date('Y', strtotime($order->created_at)) . '-' . str_pad($order->id, 6, '0', STR_PAD_LEFT);

            $result = $shipStationService->getOrder($orderNumber);

            if ($result['success'] && isset($result['orders'][0])) {
                $shipstationOrder = $result['orders'][0];

                DB::table('orders')
                    ->where('id', $order->id)
                    ->update([
                        'shipstation_order_id' => $shipstationOrder['orderId'],
                        'shipstation_order_key' => $shipstationOrder['orderKey'],
                        'shipstation_order_number' => $shipstationOrder['orderNumber']
                    ]);

                $updated++;
            } else {
                $notFound++;
            }

            $bar->advance();
            usleep(100000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Updated: {$updated} orders");
        $this->info("Not found in ShipStation: {$notFound} orders");

        return 0;
    }
}
