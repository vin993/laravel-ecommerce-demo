<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function indexExists($table, $index)
    {
        $exists = DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]);
        return !empty($exists);
    }

    private function createIndexIfNotExists($table, $columns, $indexName)
    {
        if (!$this->indexExists($table, $indexName)) {
            Schema::table($table, function (Blueprint $table) use ($columns, $indexName) {
                $table->index($columns, $indexName);
            });
        }
    }

    public function up()
    {
        $this->createIndexIfNotExists('product_flat', ['channel', 'locale', 'visible_individually'], 'idx_product_flat_channel_locale_visible');
        $this->createIndexIfNotExists('product_flat', ['product_id', 'channel', 'locale'], 'idx_product_flat_product_channel_locale');
        $this->createIndexIfNotExists('product_flat', 'price', 'idx_product_flat_price');
        
        $this->createIndexIfNotExists('product_categories', ['category_id', 'product_id'], 'idx_product_categories_category_product');
        
        $this->createIndexIfNotExists('product_inventories', ['product_id', 'qty'], 'idx_product_inventories_product_qty');
        
        $this->createIndexIfNotExists('product_attribute_values', ['product_id', 'attribute_id'], 'idx_product_attr_values_product_attr');
        
        if (!$this->indexExists('product_attribute_values', 'idx_product_attr_values_attr_text')) {
            DB::statement('CREATE INDEX idx_product_attr_values_attr_text ON product_attribute_values(attribute_id, text_value(100))');
        }
        
        $this->createIndexIfNotExists('product_images', ['product_id', 'position'], 'idx_product_images_product_position');
        
        $this->createIndexIfNotExists('product_vehicle_fitment', ['tmmy_id', 'product_id'], 'idx_vehicle_fitment_tmmy_product');
        
        $this->createIndexIfNotExists('ds_manufacturer_index', 'manufacturer_name', 'idx_manufacturer_name');
        
        $this->createIndexIfNotExists('ds_type_make_model_year', ['vehicle_type_id', 'make_id', 'model_id', 'year_id'], 'idx_tmmy_lookup');
    }

    public function down()
    {
        if ($this->indexExists('product_flat', 'idx_product_flat_channel_locale_visible')) {
            Schema::table('product_flat', function (Blueprint $table) {
                $table->dropIndex('idx_product_flat_channel_locale_visible');
            });
        }
        if ($this->indexExists('product_flat', 'idx_product_flat_product_channel_locale')) {
            Schema::table('product_flat', function (Blueprint $table) {
                $table->dropIndex('idx_product_flat_product_channel_locale');
            });
        }
        if ($this->indexExists('product_flat', 'idx_product_flat_price')) {
            Schema::table('product_flat', function (Blueprint $table) {
                $table->dropIndex('idx_product_flat_price');
            });
        }

        if ($this->indexExists('product_categories', 'idx_product_categories_category_product')) {
            Schema::table('product_categories', function (Blueprint $table) {
                $table->dropIndex('idx_product_categories_category_product');
            });
        }

        if ($this->indexExists('product_inventories', 'idx_product_inventories_product_qty')) {
            Schema::table('product_inventories', function (Blueprint $table) {
                $table->dropIndex('idx_product_inventories_product_qty');
            });
        }

        if ($this->indexExists('product_attribute_values', 'idx_product_attr_values_attr_text')) {
            DB::statement('DROP INDEX idx_product_attr_values_attr_text ON product_attribute_values');
        }

        if ($this->indexExists('product_images', 'idx_product_images_product_position')) {
            Schema::table('product_images', function (Blueprint $table) {
                $table->dropIndex('idx_product_images_product_position');
            });
        }

        if ($this->indexExists('product_vehicle_fitment', 'idx_vehicle_fitment_tmmy_product')) {
            Schema::table('product_vehicle_fitment', function (Blueprint $table) {
                $table->dropIndex('idx_vehicle_fitment_tmmy_product');
            });
        }

        if ($this->indexExists('ds_manufacturer_index', 'idx_manufacturer_name')) {
            Schema::table('ds_manufacturer_index', function (Blueprint $table) {
                $table->dropIndex('idx_manufacturer_name');
            });
        }

        if ($this->indexExists('ds_type_make_model_year', 'idx_tmmy_lookup')) {
            Schema::table('ds_type_make_model_year', function (Blueprint $table) {
                $table->dropIndex('idx_tmmy_lookup');
            });
        }
    }
};
