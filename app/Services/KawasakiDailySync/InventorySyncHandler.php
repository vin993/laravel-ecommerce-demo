<?php

namespace App\Services\KawasakiDailySync;

use Illuminate\Support\Facades\DB;

class InventorySyncHandler
{
    public function syncInventory(int $productId, int $qty): void
    {
        DB::table('product_inventories')->updateOrInsert(
            ['product_id' => $productId, 'inventory_source_id' => 1],
            ['qty' => $qty]
        );
    }
}
