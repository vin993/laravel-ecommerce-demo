<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * CRITICAL: These indexes prevent server crashes from expensive queries on 632K records
     */
    public function up(): void
    {
        // Add indexes to product_flat table for frequently filtered columns
        Schema::table('product_flat', function (Blueprint $table) {
            // Index for channel + locale + status (most common WHERE clause)
            $table->index(['channel', 'locale', 'status'], 'idx_pf_channel_locale_status');

            // Index for product_id (frequently joined)
            $table->index('product_id', 'idx_pf_product_id');

            // Index for price range filtering
            $table->index('price', 'idx_pf_price');

            // Index for SKU searches
            $table->index('sku', 'idx_pf_sku');

            // Composite index for visible products
            $table->index(['visible_individually', 'status'], 'idx_pf_visible_status');
        });

        // Add indexes to product_categories for faster joins
        Schema::table('product_categories', function (Blueprint $table) {
            // Index for category lookups
            if (!$this->indexExists('product_categories', 'idx_pc_category_id')) {
                $table->index('category_id', 'idx_pc_category_id');
            }

            // Index for product lookups
            if (!$this->indexExists('product_categories', 'idx_pc_product_id')) {
                $table->index('product_id', 'idx_pc_product_id');
            }
        });

        // Add indexes to product_attribute_values for brand filtering
        Schema::table('product_attribute_values', function (Blueprint $table) {
            // Composite index for attribute + value lookups (brand filtering)
            if (!$this->indexExists('product_attribute_values', 'idx_pav_attr_value')) {
                $table->index(['attribute_id', 'text_value'], 'idx_pav_attr_value');
            }

            // Index for product lookups
            if (!$this->indexExists('product_attribute_values', 'idx_pav_product_id')) {
                $table->index('product_id', 'idx_pav_product_id');
            }
        });

        // Add indexes to product_inventories for stock checks
        Schema::table('product_inventories', function (Blueprint $table) {
            // Composite index for product + qty
            if (!$this->indexExists('product_inventories', 'idx_pi_product_qty')) {
                $table->index(['product_id', 'qty'], 'idx_pi_product_qty');
            }
        });

        echo "✅ Critical performance indexes added successfully\n";
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_flat', function (Blueprint $table) {
            $table->dropIndex('idx_pf_channel_locale_status');
            $table->dropIndex('idx_pf_product_id');
            $table->dropIndex('idx_pf_price');
            $table->dropIndex('idx_pf_sku');
            $table->dropIndex('idx_pf_visible_status');
        });

        Schema::table('product_categories', function (Blueprint $table) {
            if ($this->indexExists('product_categories', 'idx_pc_category_id')) {
                $table->dropIndex('idx_pc_category_id');
            }
            if ($this->indexExists('product_categories', 'idx_pc_product_id')) {
                $table->dropIndex('idx_pc_product_id');
            }
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            if ($this->indexExists('product_attribute_values', 'idx_pav_attr_value')) {
                $table->dropIndex('idx_pav_attr_value');
            }
            if ($this->indexExists('product_attribute_values', 'idx_pav_product_id')) {
                $table->dropIndex('idx_pav_product_id');
            }
        });

        Schema::table('product_inventories', function (Blueprint $table) {
            if ($this->indexExists('product_inventories', 'idx_pi_product_qty')) {
                $table->dropIndex('idx_pi_product_qty');
            }
        });
    }

    /**
     * Check if an index exists
     */
    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $dbName = $connection->getDatabaseName();

        $exists = DB::select(
            "SELECT COUNT(*) as count FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$dbName, $table, $index]
        );

        return $exists[0]->count > 0;
    }
};
