<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Exception;

class DataStreamValidate extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'datastream:validate 
                            {--fix : Automatically fix issues found}';

    /**
     * The console command description.
     */
    protected $description = 'Validate DataStream database structure and configuration';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fix = $this->option('fix');
        
        $this->info('?? DataStream Validation Report');
        $this->line('==========================================');
        
        $issues = [];
        $fixes = [];
        
        // Check required tables
        $this->info('1. Checking database tables...');
        $tableIssues = $this->checkRequiredTables();
        $issues = array_merge($issues, $tableIssues);
        
        // Check staging table columns
        $this->info('2. Checking staging table structure...');
        $columnIssues = $this->checkStagingTableColumns();
        $issues = array_merge($issues, $columnIssues);
        
        // Check if migrations are pending
        $this->info('3. Checking migrations status...');
        $migrationIssues = $this->checkMigrationsStatus();
        $issues = array_merge($issues, $migrationIssues);
        
        // Check existing data
        $this->info('4. Checking existing data...');
        $dataIssues = $this->checkExistingData();
        $issues = array_merge($issues, $dataIssues);
        
        // Report results
        $this->line('');
        if (empty($issues)) {
            $this->info('? All checks passed! DataStream is ready for sync.');
            return 0;
        } else {
            $this->error('? Issues found:');
            foreach ($issues as $issue) {
                $this->line('  - ' . $issue);
            }
            
            if ($fix) {
                $this->line('');
                $this->info('?? Attempting to fix issues...');
                $this->fixIssues();
            } else {
                $this->line('');
                $this->warn('?? Run with --fix to automatically resolve issues');
                $this->warn('   Or run: php artisan migrate');
            }
            
            return 1;
        }
    }
    
    private function checkRequiredTables(): array
    {
        $issues = [];
        
        $requiredTables = [
            // Staging tables
            'ds_ftp_sync_operations' => 'Main sync tracking table',
            'ari_staging_generic' => 'Generic staging data',
            'ari_staging_partmaster' => 'Parts master data staging',
            'ari_staging_images' => 'Images staging',
            'ari_staging_fitment' => 'Fitment staging',
            'ari_staging_distributor_inventory' => 'Distributor inventory staging',
            'ari_staging_part_price_inv' => 'Part pricing staging',
            
            // Reference tables
            'ds_vehicle_types' => 'Vehicle types reference',
            'ds_makes' => 'Makes reference',
            'ds_models' => 'Models reference',
            'ds_years' => 'Years reference',
            'ds_manufacturers' => 'Manufacturers reference',
            'ds_brands' => 'Brands reference',
            'ds_distributors' => 'Distributors reference',
            'ds_attributes' => 'Attributes reference',
            'ds_groups' => 'Groups reference',
            'ds_categories' => 'Categories reference',
            'ds_applications' => 'Applications reference',
            
            // Target tables
            'ds_products' => 'Products target',
            'ds_pricing' => 'Pricing target',
            'ds_inventory' => 'Inventory target',
            'ds_images' => 'Images target',
            'ds_fitment' => 'Fitment target',
            'ds_product_attributes' => 'Product attributes target',
            'ds_groupings' => 'Groupings target',
            'ds_engines' => 'Engines reference'
        ];
        
        foreach ($requiredTables as $table => $description) {
            try {
                if (!Schema::hasTable($table)) {
                    $issues[] = "Missing table: {$table} ({$description})";
                } else {
                    $count = DB::table($table)->count();
                    $this->line("  ? {$table}: {$count} records");
                }
            } catch (Exception $e) {
                $issues[] = "Error checking table {$table}: " . $e->getMessage();
            }
        }
        
        return $issues;
    }
    
    private function checkStagingTableColumns(): array
    {
        $issues = [];
        
        $stagingTables = [
            'ari_staging_generic',
            'ari_staging_partmaster',
            'ari_staging_images',
            'ari_staging_fitment',
            'ari_staging_distributor_inventory',
            'ari_staging_part_price_inv'
        ];
        
        foreach ($stagingTables as $table) {
            if (Schema::hasTable($table)) {
                // Check for processed_at column
                if (!Schema::hasColumn($table, 'processed_at')) {
                    $issues[] = "Missing processed_at column in {$table}";
                } else {
                    $this->line("  ? {$table}: has processed_at column");
                }
                
                // Check for raw_data column
                if (!Schema::hasColumn($table, 'raw_data')) {
                    $issues[] = "Missing raw_data column in {$table}";
                } else {
                    $this->line("  ? {$table}: has raw_data column");
                }
            }
        }
        
        return $issues;
    }
    
    private function checkMigrationsStatus(): array
    {
        $issues = [];
        
        try {
            // Get pending migrations count
            $pendingMigrations = DB::table('migrations')
                ->where('migration', 'like', '%datastream%')
                ->orWhere('migration', 'like', '%ari_staging%')
                ->count();
                
            if ($pendingMigrations === 0) {
                $issues[] = "No DataStream migrations found in migrations table";
            } else {
                $this->line("  ? Found {$pendingMigrations} DataStream-related migrations");
            }
            
        } catch (Exception $e) {
            $issues[] = "Could not check migrations: " . $e->getMessage();
        }
        
        return $issues;
    }
    
    private function checkExistingData(): array
    {
        $issues = [];
        
        // Check staging data
        $stagingTables = [
            'ari_staging_generic',
            'ari_staging_partmaster',
            'ari_staging_images',
            'ari_staging_fitment',
            'ari_staging_distributor_inventory',
            'ari_staging_part_price_inv'
        ];
        
        $totalStagingRecords = 0;
        foreach ($stagingTables as $table) {
            if (Schema::hasTable($table)) {
                try {
                    $count = DB::table($table)->count();
                    $unprocessedCount = Schema::hasColumn($table, 'processed_at') 
                        ? DB::table($table)->whereNull('processed_at')->count()
                        : $count;
                    
                    $totalStagingRecords += $count;
                    
                    if ($count > 0) {
                        $this->line("  ?? {$table}: {$count} total, {$unprocessedCount} unprocessed");
                    }
                } catch (Exception $e) {
                    $issues[] = "Error checking data in {$table}: " . $e->getMessage();
                }
            }
        }
        
        if ($totalStagingRecords > 0) {
            $this->info("  ?? Total staging records: {$totalStagingRecords}");
        } else {
            $this->warn("  ??  No staging data found (expected after fresh migration)");
        }
        
        return $issues;
    }
    
    private function fixIssues(): void
    {
        try {
            $this->info('Running migrations...');
            $this->call('migrate', ['--force' => true]);
            $this->info('? Migrations completed');
        } catch (Exception $e) {
            $this->error('? Failed to run migrations: ' . $e->getMessage());
        }
    }
}
