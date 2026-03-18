<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

class MigrateProductImagesToS3 extends Command
{
    protected $signature = 'images:migrate-to-s3
                            {--batch=100 : Number of images to upload per batch}
                            {--skip=0 : Skip first N images}
                            {--test : Test mode - upload only 10 images}
                            {--resume : Resume from last position}';

    protected $description = 'Migrate product images from local storage to S3';

    private $progressFile = '/tmp/s3-migration-progress.json';

    public function handle()
    {
        $batch = (int) $this->option('batch');
        $skip = (int) $this->option('skip');
        $test = $this->option('test');
        $resume = $this->option('resume');

        $this->info('=== Product Images to S3 Migration ===');
        $this->newLine();

        $localPath = storage_path('app/public/product');

        if (!is_dir($localPath)) {
            $this->error("Local product folder not found: {$localPath}");
            return 1;
        }

        $progress = $this->loadProgress();

        if ($resume && isset($progress['last_processed'])) {
            $skip = $progress['last_processed'];
            $this->info("Resuming from position: {$skip}");
        }

        $this->info("Scanning files in: {$localPath}");
        $files = $this->scanFiles($localPath);
        $totalFiles = count($files);

        $this->info("Total files found: " . number_format($totalFiles));

        if ($test) {
            $this->warn("TEST MODE: Uploading only 10 files");
            $files = array_slice($files, 0, 10);
        } elseif ($skip > 0) {
            $files = array_slice($files, $skip);
            $this->info("Skipping first {$skip} files");
        }

        $this->newLine();

        $uploaded = 0;
        $skipped = 0;
        $errors = 0;
        $processedCount = $skip;

        $bar = $this->output->createProgressBar(count($files));
        $bar->start();

        foreach (array_chunk($files, $batch) as $batchFiles) {
            foreach ($batchFiles as $file) {
                try {
                    $relativePath = str_replace($localPath . '/', '', $file);
                    $s3Path = 'product/' . $relativePath;

                    if (Storage::disk('s3')->exists($s3Path)) {
                        $skipped++;
                        $bar->advance();
                        $processedCount++;
                        continue;
                    }

                    $fileContents = file_get_contents($file);

                    if ($fileContents === false) {
                        $this->error("Failed to read: {$file}");
                        $errors++;
                        $bar->advance();
                        $processedCount++;
                        continue;
                    }

                    Storage::disk('s3')->put($s3Path, $fileContents);

                    $uploaded++;
                    $processedCount++;

                    if ($uploaded % 50 == 0) {
                        $this->saveProgress([
                            'last_processed' => $processedCount,
                            'uploaded' => $uploaded,
                            'skipped' => $skipped,
                            'errors' => $errors,
                            'timestamp' => now()->toDateTimeString()
                        ]);
                    }

                } catch (\Exception $e) {
                    $this->error("Error uploading {$file}: " . $e->getMessage());
                    $errors++;
                }

                $bar->advance();
            }

            usleep(100000);
        }

        $bar->finish();
        $this->newLine(2);

        $this->saveProgress([
            'last_processed' => $processedCount,
            'uploaded' => $uploaded,
            'skipped' => $skipped,
            'errors' => $errors,
            'completed' => true,
            'timestamp' => now()->toDateTimeString()
        ]);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Files', number_format($totalFiles)],
                ['Uploaded', number_format($uploaded)],
                ['Skipped (already exists)', number_format($skipped)],
                ['Errors', number_format($errors)],
                ['Processed Position', number_format($processedCount)]
            ]
        );

        if ($errors > 0) {
            $this->warn("Migration completed with {$errors} errors. Check logs above.");
        } else {
            $this->info("Migration completed successfully!");
        }

        $this->newLine();
        $this->info("Next steps:");
        $this->line("1. Verify upload: php artisan images:verify-s3");
        $this->line("2. Update config to use S3 as default storage");
        $this->line("3. Test product images on website");

        return 0;
    }

    private function scanFiles($path)
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function loadProgress()
    {
        if (file_exists($this->progressFile)) {
            return json_decode(file_get_contents($this->progressFile), true) ?? [];
        }
        return [];
    }

    private function saveProgress($data)
    {
        file_put_contents($this->progressFile, json_encode($data, JSON_PRETTY_PRINT));
    }
}
