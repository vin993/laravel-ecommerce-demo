<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add processed_at column to staging tables for tracking transformation status
     */
    public function up(): void
    {
        $stagingTables = [
            'ari_staging_partmaster',
            'ari_staging_images',
            'ari_staging_fitment',
            'ari_staging_distributor_inventory',
            'ari_staging_part_price_inv',
            'ari_staging_generic'
        ];

        foreach ($stagingTables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (!Schema::hasColumn($table->getTable(), 'processed_at')) {
                        $table->timestamp('processed_at')->nullable()->after('processing_errors');
                        $table->index(['processed_at', 'sync_operation_id'], $table->getTable() . '_processed_sync_idx');
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $stagingTables = [
            'ari_staging_partmaster',
            'ari_staging_images',
            'ari_staging_fitment',
            'ari_staging_distributor_inventory',
            'ari_staging_part_price_inv',
            'ari_staging_generic'
        ];

        foreach ($stagingTables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $table) {
                    if (Schema::hasColumn($table->getTable(), 'processed_at')) {
                        $table->dropIndex($table->getTable() . '_processed_sync_idx');
                        $table->dropColumn('processed_at');
                    }
                });
            }
        }
    }
};
