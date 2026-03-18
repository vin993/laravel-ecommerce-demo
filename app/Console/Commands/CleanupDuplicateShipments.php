<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicateShipments extends Command
{
    protected $signature = 'cleanup:duplicate-shipments {order_id?}';

    protected $description = 'Clean up duplicate shipment records, keeping only the latest one per order';

    public function handle()
    {
        $orderId = $this->argument('order_id');

        if ($orderId) {
            $this->cleanupOrder($orderId);
        } else {
            $this->cleanupAll();
        }

        return 0;
    }

    protected function cleanupOrder($orderId)
    {
        $shipments = DB::table('shipments')
            ->where('order_id', $orderId)
            ->orderBy('id', 'desc')
            ->get();

        if ($shipments->count() <= 1) {
            $this->info("Order #{$orderId} has only one shipment. Nothing to clean up.");
            return;
        }

        $keep = $shipments->first();
        $deleteIds = $shipments->skip(1)->pluck('id')->toArray();

        DB::table('shipments')->whereIn('id', $deleteIds)->delete();

        $this->info("Cleaned up order #{$orderId}:");
        $this->info("  Kept shipment ID: {$keep->id} (Tracking: {$keep->track_number})");
        $this->info("  Deleted " . count($deleteIds) . " duplicate(s)");
    }

    protected function cleanupAll()
    {
        $ordersWithDuplicates = DB::table('shipments')
            ->select('order_id', DB::raw('COUNT(*) as count'))
            ->groupBy('order_id')
            ->having('count', '>', 1)
            ->get();

        if ($ordersWithDuplicates->isEmpty()) {
            $this->info("No duplicate shipments found.");
            return;
        }

        $this->info("Found " . $ordersWithDuplicates->count() . " orders with duplicate shipments.");
        $this->line('');

        foreach ($ordersWithDuplicates as $order) {
            $this->cleanupOrder($order->order_id);
        }

        $this->line('');
        $this->info("Cleanup complete!");
    }
}
