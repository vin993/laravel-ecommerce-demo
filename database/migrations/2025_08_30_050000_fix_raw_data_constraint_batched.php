<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Fix raw_data constraint by making it nullable - batched approach for large tables
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

        foreach ($stagingTables as $tableName) {
            if (Schema::hasTable($tableName)) {
                echo "Processing table: {$tableName}\n";
                
                // Check if the table has data and how much
                $recordCount = DB::table($tableName)->count();
                echo "  Records in table: {$recordCount}\n";
                
                if ($recordCount > 100000) {
                    // For large tables, update NULL values in batches
                    echo "  Large table detected, using batched approach...\n";
                    $this->updateNullValuesBatched($tableName);
                } else {
                    // For smaller tables, update all at once
                    echo "  Small table, updating all at once...\n";
                    DB::table($tableName)
                        ->whereNull('raw_data')
                        ->update(['raw_data' => '{}']);
                }
                
                // Make column nullable (this should be fast as it's just a schema change)
                echo "  Updating column schema...\n";
                DB::statement("ALTER TABLE `{$tableName}` MODIFY COLUMN `raw_data` JSON NULL");
                
                echo "  ✅ Completed {$tableName}\n";
            }
        }
    }

    /**
     * Update NULL values in batches to avoid locking large tables
     */
    private function updateNullValuesBatched(string $tableName): void
    {
        $batchSize = 10000;
        $offset = 0;
        $totalUpdated = 0;
        
        do {
            $updated = DB::table($tableName)
                ->whereNull('raw_data')
                ->limit($batchSize)
                ->update(['raw_data' => '{}']);
                
            $totalUpdated += $updated;
            $offset += $batchSize;
            
            if ($updated > 0) {
                echo "    Updated {$updated} records (total: {$totalUpdated})\n";
            }
            
            // Small delay to prevent overwhelming the database
            usleep(100000); // 100ms
            
        } while ($updated > 0);
        
        echo "    ✅ Total records updated: {$totalUpdated}\n";
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
                DB::statement("ALTER TABLE `{$tableName}` MODIFY COLUMN `raw_data` JSON NOT NULL");
            }
        }
    }
};
