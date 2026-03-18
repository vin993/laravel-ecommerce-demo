<?php

namespace App\Console\Commands\WPS;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class WpsFullSyncCommand extends Command
{
    protected $signature = 'wps:full-sync 
                            {--dry-run : Show what would be executed without running}
                            {--skip-products : Skip product sync step}
                            {--skip-bagisto : Skip Bagisto product creation}
                            {--skip-categories : Skip category sync}
                            {--skip-images : Skip image sync}
                            {--skip-inventory : Skip inventory sync}
                            {--skip-flat : Skip product flat table population}
                            {--products-limit=100 : Limit for Bagisto product creation}
                            {--images-limit=20 : Limit for image sync}
                            {--categories-limit=50 : Limit for category sync}';

    protected $description = 'Complete WPS sync orchestrator - runs all sync operations in correct order';

    public function handle()
    {
        $startTime = microtime(true);
        $this->displayHeader();

        if ($this->option('dry-run')) {
            $this->showDryRun();
            return 0;
        }

        try {
            $this->runFullSync();
            $this->displaySuccess($startTime);
            return 0;
        } catch (\Exception $e) {
            $this->displayError($e);
            return 1;
        }
    }

    protected function displayHeader()
    {
        $this->newLine();
        $this->line('┌─────────────────────────────────────────┐');
        $this->line('│     🚀 WPS FULL SYNC ORCHESTRATOR       │');
        $this->line('│                                         │');
        $this->line('│  Complete sync from WPS API to Bagisto │');
        $this->line('└─────────────────────────────────────────┘');
        $this->newLine();
        $this->info('⏱️  Start Time: ' . now()->format('Y-m-d H:i:s'));
        $this->newLine();
    }

    protected function showDryRun()
    {
        $this->info('🔍 DRY RUN - Showing execution plan:');
        $this->newLine();

        $steps = $this->getExecutionSteps();
        
        foreach ($steps as $index => $step) {
            $status = $step['skip'] ? '⏭️  SKIP' : '▶️  RUN';
            $this->line(sprintf('%2d. %s %s', $index + 1, $status, $step['description']));
            if (!empty($step['command'])) {
                $this->line('    Command: ' . $step['command']);
            }
            if (!empty($step['notes'])) {
                $this->line('    Notes: ' . $step['notes']);
            }
            $this->newLine();
        }

        $this->warn('This was a dry run. Use without --dry-run to execute.');
    }

    protected function runFullSync()
    {
        $steps = $this->getExecutionSteps();
        $completedSteps = 0;
        $totalSteps = count(array_filter($steps, fn($step) => !$step['skip']));

        foreach ($steps as $index => $step) {
            if ($step['skip']) {
                $this->line(sprintf('⏭️  Step %d: SKIPPED - %s', $index + 1, $step['description']));
                continue;
            }

            $stepStartTime = microtime(true);
            $this->info(sprintf('▶️  Step %d/%d: %s', $completedSteps + 1, $totalSteps, $step['description']));
            
            if (!empty($step['warning'])) {
                $this->warn('⚠️  ' . $step['warning']);
            }

            try {
                $result = $this->executeStep($step);
                $stepTime = round(microtime(true) - $stepStartTime, 2);
                
                $this->line(sprintf('✅ Step %d completed in %ss', $completedSteps + 1, $stepTime));
                if ($result && is_array($result)) {
                    foreach ($result as $key => $value) {
                        $this->line("   • {$key}: {$value}");
                    }
                }
                
                $completedSteps++;
            } catch (\Exception $e) {
                $this->error(sprintf('❌ Step %d failed: %s', $completedSteps + 1, $e->getMessage()));
                
                if ($step['critical']) {
                    throw new \Exception("Critical step failed: {$step['description']} - {$e->getMessage()}");
                } else {
                    $this->warn('⚠️  Non-critical step failed, continuing...');
                    $completedSteps++;
                }
            }
            
            $this->newLine();
        }

        $this->info("🎉 Sync orchestration completed! {$completedSteps}/{$totalSteps} steps executed successfully.");
    }

    protected function getExecutionSteps()
    {
        return [
            [
                'description' => 'Sync Products from WPS API',
                'command' => 'wps:sync-products',
                'options' => [],
                'skip' => $this->option('skip-products'),
                'critical' => true,
                'notes' => 'Fetches all products and items from WPS API with enhanced data including dimensions'
            ],
            [
                'description' => 'Create Bagisto Products',
                'command' => 'wps:create-bagisto-products',
                'options' => ['--limit' => $this->option('products-limit')],
                'skip' => $this->option('skip-bagisto'),
                'critical' => true,
                'notes' => 'Creates actual Bagisto products from synced WPS data'
            ],
            [
                'description' => 'Sync Categories',
                'command' => 'wps:sync-categories',
                'options' => ['--limit' => $this->option('categories-limit')],
                'skip' => $this->option('skip-categories'),
                'critical' => false,
                'warning' => 'This step makes additional API calls to fetch category data'
            ],
            [
                'description' => 'Sync Product Images',
                'command' => 'wps:sync-images',
                'options' => ['--limit' => $this->option('images-limit')],
                'skip' => $this->option('skip-images'),
                'critical' => false,
                'warning' => 'This step downloads/processes images and may take significant time'
            ],
            [
                'description' => 'Sync Inventory',
                'command' => 'wps:sync-inventory',
                'options' => ['--from-api' => true],
                'skip' => $this->option('skip-inventory'),
                'critical' => false,
                'notes' => 'Updates inventory quantities from WPS API to Bagisto'
            ],
            [
                'description' => 'Populate Product Flat Table',
                'command' => 'wps:populate-flat',
                'options' => ['--limit' => 1000],
                'skip' => $this->option('skip-flat'),
                'critical' => false,
                'notes' => 'Populates Bagisto product_flat table for better performance'
            ]
        ];
    }

    protected function executeStep($step)
    {
        $command = $step['command'];
        $options = $step['options'] ?? [];
        
        // Convert options to artisan call format
        $artisanOptions = [];
        foreach ($options as $key => $value) {
            if (is_bool($value) && $value) {
                $artisanOptions[] = $key;
            } elseif (!is_bool($value)) {
                $artisanOptions[$key] = $value;
            }
        }

        $exitCode = Artisan::call($command, $artisanOptions);
        
        if ($exitCode !== 0) {
            throw new \Exception("Command '{$command}' failed with exit code {$exitCode}");
        }

        // Try to extract useful information from the command output
        $output = Artisan::output();
        return $this->parseCommandOutput($output);
    }

    protected function parseCommandOutput($output)
    {
        $result = [];
        
        // Look for common patterns in command outputs
        if (preg_match('/Products?\s+(?:synced|created):\s*(\d+)/i', $output, $matches)) {
            $result['products'] = $matches[1];
        }
        
        if (preg_match('/Items?\s+synced:\s*(\d+)/i', $output, $matches)) {
            $result['items'] = $matches[1];
        }
        
        if (preg_match('/(?:Inventory\s+records?\s+updated|Images?\s+(?:synced|processed)):\s*(\d+)/i', $output, $matches)) {
            $result['processed'] = $matches[1];
        }
        
        if (preg_match('/Errors?:\s*(\d+)/i', $output, $matches)) {
            $result['errors'] = $matches[1];
        }
        
        if (preg_match('/Total\s+time:\s*([\d.]+)s/i', $output, $matches)) {
            $result['time'] = $matches[1] . 's';
        }

        return $result;
    }

    protected function displaySuccess($startTime)
    {
        $totalTime = round(microtime(true) - $startTime, 2);
        
        $this->newLine();
        $this->line('┌─────────────────────────────────────────┐');
        $this->line('│            🎉 SYNC COMPLETED!           │');
        $this->line('└─────────────────────────────────────────┘');
        $this->newLine();
        $this->info('📊 Full Sync Statistics:');
        $this->line("   • Total execution time: {$totalTime}s");
        $this->line('   • End time: ' . now()->format('Y-m-d H:i:s'));
        $this->newLine();
        $this->info('🔍 Next steps:');
        $this->line('   • Check individual command logs for detailed results');
        $this->line('   • Verify data in Bagisto admin panel');
        $this->line('   • Run wps:create-bagisto-products --stats for summary');
        $this->newLine();
    }

    protected function displayError(\Exception $e)
    {
        $this->newLine();
        $this->error('❌ SYNC FAILED!');
        $this->error('Error: ' . $e->getMessage());
        $this->newLine();
        $this->warn('🔧 Troubleshooting:');
        $this->line('   • Check WPS API connectivity');
        $this->line('   • Review WPS logs: storage/logs/wps.log');
        $this->line('   • Verify Bagisto configuration');
        $this->line('   • Run individual commands to isolate the issue');
        $this->newLine();

        Log::channel('wps')->error('WPS full sync failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
