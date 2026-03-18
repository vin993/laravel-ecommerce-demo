<?php

namespace App\Console\Commands\WPS;

use App\Services\WPS\WpsProductFlatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PopulateProductFlat extends Command
{
    protected $signature = 'wps:populate-flat 
                            {--limit=100 : Number of products to process}
                            {--clear : Clear existing flat data first}';

    protected $description = 'Populate product_flat table for WPS products';

    protected $flatService;

    public function __construct(WpsProductFlatService $flatService)
    {
        parent::__construct();
        $this->flatService = $flatService;
    }

    public function handle()
    {
        try {
            if ($this->option('clear')) {
                $this->info('Clearing existing product flat data...');
                $deleted = $this->flatService->clearProductFlat();
                $this->info("Cleared {$deleted} records");
            }

            $limit = $this->option('limit');
            $this->info("Populating product flat table (limit: {$limit})...");
            
            $result = $this->flatService->populateProductFlat($limit);
            
            $this->info("Products populated: {$result['populated']}");
            $this->error("Errors: {$result['errors']}");
            
            if ($result['errors'] > 0) {
                $this->warn('Check WPS logs for detailed error information');
            }
            
        } catch (\Exception $e) {
            $this->error('Command failed: ' . $e->getMessage());
            Log::channel('wps')->error('Populate flat command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }
}