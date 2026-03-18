<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DataImportService\KawasakiAutomatedXmlImportService;
use App\Services\DataStream\FtpService;
use Exception;

class KawasakiSyncProductImagesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kawasaki:sync-images 
                            {path? : The path to the XML file or directory (optional if --ftp is used)} 
                            {--ftp : Download from FTP}
                            {--dry-run : Run without updating database}
                            {--reset : Start from the beginning}';

    protected $description = 'Sync images for existing Kawasaki products from XML files';

    public function handle()
    {
        $path = $this->argument('path');
        $dryRun = $this->option('dry-run');
        $useFtp = $this->option('ftp');
        $reset = $this->option('reset');

        $this->info("Starting Kawasaki Product Image Sync" . ($dryRun ? " [DRY RUN]" : ""));

        try {
            // Use the same FTP service binding as the import command
            $kawasakiFtp = app(\App\Services\DataStream\FtpService::class);
            $importService = new KawasakiAutomatedXmlImportService($kawasakiFtp);
            $importService->setCommand($this);

            if ($reset) {
                $this->info("Clearing checkpoint...");
                $importService->clearCheckpoint();
            }

            if ($useFtp) {
                $this->info("Connecting to Kawasaki FTP...");
                $stats = $importService->importFromFtp(true, $dryRun, true);
            } else {
                if (!$path) {
                    $this->error("Path is required unless --ftp is specified.");
                    return 1;
                }
                $stats = $importService->importFromXml($path, true, $dryRun, true);
            }

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Images Synced', $stats['updated']],
                    ['Skipped', $stats['skipped']],
                    ['Failed', $stats['failed']],
                ]
            );

            $this->info('Kawasaki product image sync completed successfully.');
            return 0;

        } catch (Exception $e) {
            $this->error('Kawasaki product image sync failed: ' . $e->getMessage());
            return 1;
        }
    }
}
