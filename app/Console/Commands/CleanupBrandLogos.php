<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupBrandLogos extends Command
{
    protected $signature = 'brands:cleanup-logos
                            {--brand= : Clean specific brand by name}
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove incorrect brand logos (like Python, Oracle, etc)';

    public function handle()
    {
        $specificBrand = $this->option('brand');
        $dryRun = $this->option('dry-run');

        $incorrectBrands = [
            'Python',
            'Oracle',
            'Java',
            'Swift',
        ];

        if ($specificBrand) {
            $incorrectBrands = [$specificBrand];
        }

        $this->info('Searching for incorrect logos...');

        $brands = DB::table('ds_manufacturer_index')
            ->whereIn('manufacturer_name', $incorrectBrands)
            ->whereNotNull('logo_path')
            ->get();

        if ($brands->isEmpty()) {
            $this->info('No incorrect logos found.');
            return 0;
        }

        $this->table(
            ['Brand Name', 'Logo Path', 'Source'],
            $brands->map(fn($b) => [$b->manufacturer_name, $b->logo_path, $b->logo_source])
        );

        if ($dryRun) {
            $this->warn('DRY RUN - No changes made. Remove --dry-run to actually delete.');
            return 0;
        }

        if (!$this->confirm("Delete logos for {$brands->count()} brands?", false)) {
            $this->info('Cancelled.');
            return 0;
        }

        $deletedCount = 0;

        foreach ($brands as $brand) {
            if ($brand->logo_path && Storage::disk('public')->exists($brand->logo_path)) {
                Storage::disk('public')->delete($brand->logo_path);
                $this->line("Deleted file: {$brand->logo_path}");
            }

            DB::table('ds_manufacturer_index')
                ->where('manufacturer_id', $brand->manufacturer_id)
                ->update([
                    'logo_path' => null,
                    'logo_source' => null
                ]);

            $deletedCount++;
        }

        $this->info("Cleanup completed! Removed {$deletedCount} incorrect logos.");
        $this->line("Run scrape command again to fetch correct logos.");

        return 0;
    }
}
