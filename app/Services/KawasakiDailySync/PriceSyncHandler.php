<?php

namespace App\Services\KawasakiDailySync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PriceSyncHandler
{
    public function syncPrice(int $productId, float $newPrice, float $oldPrice = null): bool
    {
        $updated = DB::table('product_flat')
            ->where('product_id', $productId)
            ->update(['price' => $newPrice]);
        
        if ($updated && $oldPrice !== null) {
            Log::info("[DailySync] Price updated for product {$productId}: {$oldPrice} → {$newPrice}");
        }
        
        return (bool) $updated;
    }
}
