<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::table('product_categories', function (Blueprint $table) {
            if (!$this->indexExists('product_categories', 'idx_category_id')) {
                $table->index('category_id', 'idx_category_id');
            }
            if (!$this->indexExists('product_categories', 'idx_product_id')) {
                $table->index('product_id', 'idx_product_id');
            }
            if (!$this->indexExists('product_categories', 'idx_both')) {
                $table->index(['category_id', 'product_id'], 'idx_both');
            }
        });

        Schema::table('category_translations', function (Blueprint $table) {
            if (!$this->indexExists('category_translations', 'idx_category_locale')) {
                $table->index(['category_id', 'locale'], 'idx_category_locale');
            }
            if (!$this->indexExists('category_translations', 'idx_slug_locale')) {
                $table->index(['slug', 'locale'], 'idx_slug_locale');
            }
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            if (!$this->indexExists('product_attribute_values', 'idx_attribute_id')) {
                $table->index('attribute_id', 'idx_attribute_id');
            }
            if (!$this->indexExists('product_attribute_values', 'idx_text_value')) {
                $table->index(['text_value', 'attribute_id'], 'idx_text_value');
            }
        });

        Schema::table('product_images', function (Blueprint $table) {
            if (!$this->indexExists('product_images', 'idx_product_id')) {
                $table->index('product_id', 'idx_product_id');
            }
        });

        Schema::table('product_flat', function (Blueprint $table) {
            if (!$this->indexExists('product_flat', 'idx_channel_locale')) {
                $table->index(['channel', 'locale'], 'idx_channel_locale');
            }
            if (!$this->indexExists('product_flat', 'idx_visible')) {
                $table->index('visible_individually', 'idx_visible');
            }
        });

        Schema::table('categories', function (Blueprint $table) {
            if (!$this->indexExists('categories', 'idx_parent_status')) {
                $table->index(['parent_id', 'status', 'position'], 'idx_parent_status');
            }
        });
    }

    public function down()
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropIndex('idx_category_id');
            $table->dropIndex('idx_product_id');
            $table->dropIndex('idx_both');
        });

        Schema::table('category_translations', function (Blueprint $table) {
            $table->dropIndex('idx_category_locale');
            $table->dropIndex('idx_slug_locale');
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropIndex('idx_attribute_id');
            $table->dropIndex('idx_text_value');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropIndex('idx_product_id');
        });

        Schema::table('product_flat', function (Blueprint $table) {
            $table->dropIndex('idx_channel_locale');
            $table->dropIndex('idx_visible');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex('idx_parent_status');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $indexes = DB::select("SHOW INDEX FROM {$table}");
        foreach ($indexes as $index) {
            if ($index->Key_name === $indexName) {
                return true;
            }
        }
        return false;
    }
};
