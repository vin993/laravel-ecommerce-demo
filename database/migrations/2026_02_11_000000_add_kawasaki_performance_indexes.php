<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations for Kawasaki performance optimization
     */
    public function up(): void
    {
        // Index for brand filtering (most critical - used on every Kawasaki page load)
        DB::statement('
            CREATE INDEX idx_pav_kawasaki_brand 
            ON product_attribute_values(attribute_id, text_value, product_id, channel, locale)
            WHERE attribute_id = 25
        ');

        // Index for category filtering
        DB::statement('
            CREATE INDEX idx_product_categories_lookup 
            ON product_categories(category_id, product_id)
        ');

        // Index for product_flat Kawasaki queries
        DB::statement('
            CREATE INDEX idx_product_flat_kawasaki 
            ON product_flat(status, visible_individually, parent_id, channel, locale, product_id)
        ');

        // Index for price range queries
        DB::statement('
            CREATE INDEX idx_product_flat_price 
            ON product_flat(price, status, parent_id)
        ');
    }

    /**
     * Reverse the migrations
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_pav_kawasaki_brand ON product_attribute_values');
        DB::statement('DROP INDEX IF EXISTS idx_product_categories_lookup ON product_categories');
        DB::statement('DROP INDEX IF EXISTS idx_product_flat_kawasaki ON product_flat');
        DB::statement('DROP INDEX IF EXISTS idx_product_flat_price ON product_flat');
    }
};
