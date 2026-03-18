<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix raw_data constraint by making it nullable with default empty JSON
     */
    public function up(): void
    {
        // Update all staging tables to make raw_data nullable with default
        $stagingTables = [
            'ari_staging_partmaster',
            'ari_staging_images', 
            'ari_staging_fitment',
            'ari_staging_distributor_inventory',
            'ari_staging_part_price_inv',
            'ari_staging_generic'
        ];

        foreach ($stagingTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                // First update existing NULL values to empty JSON
                DB::table($tableName)
                    ->whereNull('raw_data')
                    ->update(['raw_data' => '{}']);
                    
                // Then make the column nullable (without default since JSON columns can't have defaults in MySQL)
                Schema::table($tableName, function (Blueprint $table) {
                    $table->json('raw_data')->nullable()->change();
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

        foreach ($stagingTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->json('raw_data')->nullable(false)->change();
                });
            }
        }
    }
};
