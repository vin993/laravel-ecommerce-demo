<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixVariantImages extends Command
{
    protected $signature = 'ari:fix-variant-images {--batch=500 : Number of records per batch} {--dry-run : Preview without making changes}';

    protected $description = 'Delete wrong images from variants that have NO ds_images source (corrupted data)';

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $isDryRun = $this->option('dry-run');

        $this->info('Strategy: Delete images from variants that have');
        $this->info('1. Images assigned in product_images table');
        $this->info('2. NO source image in ds_images (confirmed corrupted)');
        $this->info('3. Parent product has NO images');
        $this->newLine();

        $totalVariants = DB::table('products')->whereNotNull('parent_id')->count();
        $this->info("Total variant products: {$totalVariants}");

        $variantsWithImages = DB::table('products as p')
            ->join('product_images as vi', 'p.id', '=', 'vi.product_id')
            ->whereNotNull('p.parent_id')
            ->distinct('p.id')
            ->count('p.id');

        $this->info("Variants with images: {$variantsWithImages}");

        if ($isDryRun) {
            $this->warn('DRY RUN MODE');
            $this->newLine();

            $samples = DB::table('products as p')
                ->join('product_images as vi', 'p.id', '=', 'vi.product_id')
                ->join('product_flat as pf', 'p.id', '=', 'pf.product_id')
                ->join('products as parent', 'p.parent_id', '=', 'parent.id')
                ->join('product_flat as parent_flat', 'parent.id', '=', 'parent_flat.product_id')
                ->leftJoin('product_images as pi', 'parent.id', '=', 'pi.product_id')
                ->whereNotNull('p.parent_id')
                ->whereNull('pi.id')
                ->whereRaw('LOWER(pf.name) = LOWER(parent_flat.name)')
                ->select('p.id', 'p.sku', 'pf.name', 'vi.path')
                ->limit(10)
                ->get();

            $this->info('Sample: Variants with images where parent has NO images (will be deleted):');
            $this->table(['ID', 'SKU', 'Name', 'Image Path'],
                $samples->map(fn($s) => [$s->id, $s->sku, substr($s->name, 0, 40), substr($s->path, 0, 45)]));

            $count = DB::table('products as p')
                ->join('product_images as vi', 'p.id', '=', 'vi.product_id')
                ->join('products as parent', 'p.parent_id', '=', 'parent.id')
                ->join('product_flat as pf', 'p.id', '=', 'pf.product_id')
                ->join('product_flat as parent_flat', 'parent.id', '=', 'parent_flat.product_id')
                ->leftJoin('product_images as pi', 'parent.id', '=', 'pi.product_id')
                ->whereNotNull('p.parent_id')
                ->whereNull('pi.id')
                ->whereRaw('LOWER(pf.name) = LOWER(parent_flat.name)')
                ->count();

            $this->newLine();
            $this->info("Total images to be deleted: {$count}");

            return 0;
        }

        $totalToDelete = DB::table('products as p')
            ->join('product_images as vi', 'p.id', '=', 'vi.product_id')
            ->join('products as parent', 'p.parent_id', '=', 'parent.id')
            ->join('product_flat as pf', 'p.id', '=', 'pf.product_id')
            ->join('product_flat as parent_flat', 'parent.id', '=', 'parent_flat.product_id')
            ->leftJoin('product_images as pi', 'parent.id', '=', 'pi.product_id')
            ->whereNotNull('p.parent_id')
            ->whereNull('pi.id')
            ->whereRaw('LOWER(pf.name) = LOWER(parent_flat.name)')
            ->count();

        $this->info("Starting cleanup... ({$totalToDelete} images to delete)");
        $bar = $this->output->createProgressBar($totalToDelete);
        $bar->start();

        $deleted = 0;
        $processed = 0;

        while ($processed < $totalToDelete) {
            $images = DB::table('products as p')
                ->join('product_images as vi', 'p.id', '=', 'vi.product_id')
                ->join('products as parent', 'p.parent_id', '=', 'parent.id')
                ->join('product_flat as pf', 'p.id', '=', 'pf.product_id')
                ->join('product_flat as parent_flat', 'parent.id', '=', 'parent_flat.product_id')
                ->leftJoin('product_images as pi', 'parent.id', '=', 'pi.product_id')
                ->whereNotNull('p.parent_id')
                ->whereNull('pi.id')
                ->whereRaw('LOWER(pf.name) = LOWER(parent_flat.name)')
                ->select('vi.id as image_id')
                ->skip($processed)
                ->take($batch)
                ->get();

            if ($images->isEmpty()) {
                break;
            }

            foreach ($images as $img) {
                DB::table('product_images')->where('id', $img->image_id)->delete();
                $deleted++;
                $bar->advance();
            }

            $processed += $images->count();
            gc_collect_cycles();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info('Completed!');
        $this->info("Total images deleted: {$deleted}");

        return 0;
    }
}
