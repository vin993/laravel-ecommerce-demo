<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Dropship\Turn14DropshipService;

class TestTurn14 extends Command
{
    protected $signature = 'test:turn14 {--sku=}';
    protected $description = 'Test Turn14 API';

    public function handle()
    {
        $sku = $this->option('sku');
        $service = new Turn14DropshipService();

        $test = $service->testConnection();

        if ($test['success']) {
            $this->info('Connection: SUCCESS');
            $this->info('Environment: ' . $test['environment']);
        } else {
            $this->error('Connection: FAILED');
            return Command::FAILURE;
        }

        if ($sku) {
            $availability = $service->checkAvailability($sku);

            if ($availability && $availability['available']) {
                $this->info("SKU {$sku}: AVAILABLE");
                $this->table(['Field', 'Value'], [
                    ['Item ID', $availability['turn14_item_id']],
                    ['Inventory', $availability['inventory']],
                    ['Source', $availability['source']]
                ]);
            } else {
                $this->warn("SKU {$sku}: NOT AVAILABLE");
            }
        }

        return Command::SUCCESS;
    }
}
