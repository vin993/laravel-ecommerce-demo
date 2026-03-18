<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $attr = DB::table('attributes')->where('code', 'size_and_style')->first();
        
        if ($attr && $attr->id == 0) {
            $maxId = DB::table('attributes')->max('id');
            $newId = $maxId + 1;
            
            DB::table('attributes')
                ->where('code', 'size_and_style')
                ->update(['id' => $newId]);
            
            DB::table('product_attribute_values')
                ->where('attribute_id', 0)
                ->where('channel', 'maddparts')
                ->update(['attribute_id' => $newId]);
        }
    }

    public function down(): void
    {
        // No rollback needed
    }
};
