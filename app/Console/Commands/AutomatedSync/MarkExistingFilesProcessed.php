<?php

namespace App\Console\Commands\AutomatedSync;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MarkExistingFilesProcessed extends Command
{
    protected $signature = 'sync:mark-existing-processed
                            {--until= : Mark files processed until this date (YYYYMMDD format)}
                            {--exclude-from= : Exclude files from this date onwards (YYYYMMDD format)}
                            {--dry-run : Show what would be marked without actually marking}';

    protected $description = 'Mark existing manually processed update files as processed by automation';

    public function handle()
    {
        $until = $this->option('until');
        $excludeFrom = $this->option('exclude-from');
        $dryRun = $this->option('dry-run');

        if (!$until) {
            $this->error('Please provide --until date in YYYYMMDD format');
            $this->line('Example: php artisan sync:mark-existing-processed --until=20240901 --exclude-from=20241001');
            $this->line('This will mark files up to September 2024, excluding October onwards');
            return Command::FAILURE;
        }

        if (!preg_match('/^\d{8}$/', $until)) {
            $this->error('Invalid date format. Use YYYYMMDD (e.g., 20240901)');
            return Command::FAILURE;
        }

        if ($excludeFrom && !preg_match('/^\d{8}$/', $excludeFrom)) {
            $this->error('Invalid exclude-from date format. Use YYYYMMDD (e.g., 20241001)');
            return Command::FAILURE;
        }

        $this->info('Marking files processed until: ' . $until);
        if ($excludeFrom) {
            $this->info('Excluding files from: ' . $excludeFrom . ' onwards');
        }
        $this->line('');

        $files = DB::table('ari_ftp_file_tracking')
            ->where('file_type', 'update')
            ->whereNull('processed_by_automation')
            ->get();

        $toMark = [];
        $excluded = [];

        foreach ($files as $file) {
            if (preg_match('/Update(\d{8})/', $file->filename, $matches)) {
                $fileDate = $matches[1];

                if ($excludeFrom && $fileDate >= $excludeFrom) {
                    $excluded[] = $file;
                    continue;
                }

                if ($fileDate <= $until) {
                    $toMark[] = $file;
                }
            }
        }

        if (empty($toMark)) {
            $this->info('No files found to mark as processed');
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($toMark) . ' files to mark:');
        $this->line('');

        $tableData = [];
        foreach ($toMark as $file) {
            $tableData[] = [
                $file->id,
                $file->filename,
                $file->status ?? 'N/A',
            ];
        }
        $this->table(['ID', 'Filename', 'Current Status'], $tableData);

        if (!empty($excluded)) {
            $this->line('');
            $this->warn('Excluded ' . count($excluded) . ' files (will be processed by automation):');
            foreach ($excluded as $file) {
                $this->line('  - ' . $file->filename);
            }
        }

        if ($dryRun) {
            $this->warn('');
            $this->warn('DRY RUN MODE - No changes made');
            $this->info('To actually mark these files, run without --dry-run flag');
            return Command::SUCCESS;
        }

        $this->line('');
        if (!$this->confirm('Mark these ' . count($toMark) . ' files as processed by automation?', false)) {
            $this->warn('Cancelled');
            return Command::SUCCESS;
        }

        $fileIds = array_column($toMark, 'id');
        $updated = DB::table('ari_ftp_file_tracking')
            ->whereIn('id', $fileIds)
            ->update([
                'processed_by_automation' => true,
                'status' => 'processed',
                'updated_at' => now(),
            ]);

        $this->info('');
        $this->info('Successfully marked ' . $updated . ' files as processed by automation');
        $this->line('');
        $this->info('Next sync will only process files after ' . $until);

        return Command::SUCCESS;
    }
}
