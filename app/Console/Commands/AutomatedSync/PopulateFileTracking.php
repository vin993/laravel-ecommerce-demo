<?php

namespace App\Console\Commands\AutomatedSync;

use App\Services\DataStream\FtpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateFileTracking extends Command
{
    protected $signature = 'sync:populate-tracking';

    protected $description = 'Populate ari_ftp_file_tracking table with FTP files';

    private FtpService $ftpService;

    public function __construct(FtpService $ftpService)
    {
        parent::__construct();
        $this->ftpService = $ftpService;
    }

    public function handle()
    {
        $this->info('Connecting to FTP and listing all files...');

        $this->ftpService->connect();
        $allFiles = $this->ftpService->listFiles();
        $this->ftpService->disconnect();

        $this->info('Found ' . count($allFiles) . ' files on FTP server');
        $this->line('');

        $updateFiles = array_filter($allFiles, function($file) {
            return str_contains($file['name'], 'Update') && str_ends_with($file['name'], '.7z');
        });

        $this->info('Found ' . count($updateFiles) . ' update files');
        $this->line('');

        $added = 0;
        $existing = 0;

        foreach ($updateFiles as $file) {
            $exists = DB::table('ari_ftp_file_tracking')
                ->where('filename', $file['name'])
                ->exists();

            if (!$exists) {
                DB::table('ari_ftp_file_tracking')->insert([
                    'filename' => $file['name'],
                    'file_type' => 'update',
                    'remote_path' => $file['path'],
                    'file_size' => $file['size'],
                    'remote_modified_at' => $file['modified'] ?? now(),
                    'status' => 'pending',
                    'processed_by_automation' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $added++;
                $this->line('Added: ' . $file['name']);
            } else {
                $existing++;
            }
        }

        $this->line('');
        $this->info('Summary:');
        $this->line('  Added: ' . $added);
        $this->line('  Already exists: ' . $existing);
        $this->line('');
        $this->info('Now you can run: php artisan sync:mark-existing-processed');

        return Command::SUCCESS;
    }
}
