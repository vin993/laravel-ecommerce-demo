<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\DataImportService\KawasakiAutomatedXmlImportService;
use App\Services\DataStream\KawasakiFtpService;
use Exception;

class KawasakiImportXmlDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kawasaki:xml-import 
                            {path? : The path to the XML file or directory (optional if --ftp is used)} 
                            {--images : Sync images found in XML}
                            {--ftp : Download from FTP}
                            {--dry-run : Run without updating database}';

    protected $description = 'Import Kawasaki product data from XML files using the new Kawasaki FTP service';

    public function handle()
    {
        $path = $this->argument('path');
        $syncImages = $this->option('images');
        $dryRun = $this->option('dry-run');
        $useFtp = $this->option('ftp');

        $this->info("Starting Kawasaki XML Import" . ($dryRun ? " [DRY RUN]" : ""));

        try {
            $kawasakiFtp = app(KawasakiFtpService::class);
            $importService = new KawasakiAutomatedXmlImportService($kawasakiFtp);
            $importService->setCommand($this);

            if ($useFtp) {
                $this->info("Connecting to Kawasaki FTP...");
                $stats = $importService->importFromFtp($syncImages, $dryRun);
            } else {
                if (!$path) {
                    $this->error("Path is required unless --ftp is specified.");
                    return 1;
                }
                $stats = $importService->importFromXml($path, $syncImages, $dryRun);
            }

            $this->table(
                ['Metric', 'Count'],
                [
                    ['Created', $stats['created']],
                    ['Updated', $stats['updated']],
                    ['Skipped', $stats['skipped']],
                    ['Failed', $stats['failed']],
                ]
            );

            $this->info('Kawasaki import completed successfully.');
            return 0;

        } catch (Exception $e) {
            $this->error('Kawasaki import failed: ' . $e->getMessage());
            return 1;
        }
    }
}
