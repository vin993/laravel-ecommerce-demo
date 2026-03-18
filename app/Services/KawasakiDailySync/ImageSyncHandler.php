<?php

namespace App\Services\KawasakiDailySync;

use Illuminate\Support\Facades\DB;

class ImageSyncHandler
{
    public function syncImages(int $productId, array $imagePaths): int
    {
        $added = 0;
        $position = 0;
        
        foreach ($imagePaths as $path) {
            $exists = DB::table('product_images')
                ->where('product_id', $productId)
                ->where('path', $path)
                ->exists();
            
            if (!$exists) {
                DB::table('product_images')->insert([
                    'product_id' => $productId,
                    'path' => $path,
                    'type' => 'images',
                    'position' => $position++,
                ]);
                $added++;
            }
        }
        
        return $added;
    }
}
