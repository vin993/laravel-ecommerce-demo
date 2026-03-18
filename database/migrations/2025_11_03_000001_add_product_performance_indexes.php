<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        $indexes = [
            ['table' => 'product_flat', 'name' => 'idx_product_flat_channel_locale_status', 'columns' => ['channel', 'locale', 'status']],
            ['table' => 'product_flat', 'name' => 'idx_product_flat_product_id', 'columns' => ['product_id']],
            ['table' => 'product_inventories', 'name' => 'idx_product_inventories_product_id', 'columns' => ['product_id']],
            ['table' => 'product_categories', 'name' => 'idx_product_categories_product_id', 'columns' => ['product_id']],
            ['table' => 'product_categories', 'name' => 'idx_product_categories_category_id', 'columns' => ['category_id']],
            ['table' => 'product_attribute_values', 'name' => 'idx_product_attribute_values_product_id', 'columns' => ['product_id']],
            ['table' => 'product_attribute_values', 'name' => 'idx_product_attribute_values_attribute_id', 'columns' => ['attribute_id']],
        ];

        foreach ($indexes as $index) {
            try {
                $exists = DB::select("SHOW INDEX FROM {$index['table']} WHERE Key_name = ?", [$index['name']]);
                if (empty($exists)) {
                    $columns = implode(',', $index['columns']);
                    DB::statement("CREATE INDEX {$index['name']} ON {$index['table']}({$columns})");
                }
            } catch (\Exception $e) {
                \Log::warning("Index {$index['name']} may already exist: " . $e->getMessage());
            }
        }
    }

    public function down()
    {
        $indexes = [
            ['table' => 'product_flat', 'name' => 'idx_product_flat_channel_locale_status'],
            ['table' => 'product_flat', 'name' => 'idx_product_flat_product_id'],
            ['table' => 'product_inventories', 'name' => 'idx_product_inventories_product_id'],
            ['table' => 'product_categories', 'name' => 'idx_product_categories_product_id'],
            ['table' => 'product_categories', 'name' => 'idx_product_categories_category_id'],
            ['table' => 'product_attribute_values', 'name' => 'idx_product_attribute_values_product_id'],
            ['table' => 'product_attribute_values', 'name' => 'idx_product_attribute_values_attribute_id'],
        ];

        foreach ($indexes as $index) {
            try {
                DB::statement("DROP INDEX {$index['name']} ON {$index['table']}");
            } catch (\Exception $e) {
                \Log::warning("Could not drop index {$index['name']}: " . $e->getMessage());
            }
        }
    }
};
