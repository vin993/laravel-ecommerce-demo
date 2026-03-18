<?php

namespace App\Services\AutomatedSync;

use App\Mail\AutomatedSync\SyncSuccessNotification;
use App\Mail\AutomatedSync\SyncFailureNotification;
use App\Models\AutomatedSync\FtpSyncLog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SyncNotificationService
{
    private array $recipients;

    public function __construct()
    {
        $emails = env('AUTOMATED_SYNC_EMAIL', 'your@email.com');
        $this->recipients = array_map('trim', explode(',', $emails));
    }

    public function sendSuccessNotification(FtpSyncLog $syncLog): void
    {
        Log::info('[AutoSync] Sending success notification', [
            'recipients' => $this->recipients,
            'sync_log_id' => $syncLog->id
        ]);

        try {
            Mail::to($this->recipients)->send(new SyncSuccessNotification($syncLog));
            $syncLog->markNotificationSent();
            Log::info('[AutoSync] Success notification sent');
        } catch (\Exception $e) {
            Log::error('[AutoSync] Failed to send success notification', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function sendFailureNotification(FtpSyncLog $syncLog): void
    {
        Log::info('[AutoSync] Sending failure notification', [
            'recipients' => $this->recipients,
            'sync_log_id' => $syncLog->id
        ]);

        try {
            Mail::to($this->recipients)->send(new SyncFailureNotification($syncLog));
            $syncLog->markNotificationSent();
            Log::info('[AutoSync] Failure notification sent');
        } catch (\Exception $e) {
            Log::error('[AutoSync] Failed to send failure notification', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function generateSummaryReport(FtpSyncLog $syncLog): string
    {
        $duration = gmdate('H:i:s', $syncLog->total_duration_seconds);

        $report = "Automated FTP Sync Summary\n";
        $report .= "==========================\n\n";
        $report .= "Sync Date: {$syncLog->sync_date->format('Y-m-d')}\n";
        $report .= "Status: " . strtoupper($syncLog->status) . "\n";
        $report .= "Duration: {$duration}\n\n";

        $report .= "Files Detected: " . count($syncLog->update_files_detected ?? []) . "\n";
        $report .= "Files Processed: " . count($syncLog->update_files_processed ?? []) . "\n\n";

        $report .= "Statistics:\n";
        $report .= "  New Products Created: {$syncLog->new_products_created}\n";
        $report .= "  Products Updated: {$syncLog->products_updated}\n";
        $report .= "  Categories Synced: {$syncLog->categories_synced}\n";
        $report .= "  Brands Synced: {$syncLog->brands_synced}\n";
        $report .= "  Variants Synced: {$syncLog->variants_synced}\n";
        $report .= "  Images Synced: {$syncLog->images_synced}\n\n";

        if ($syncLog->status === 'failed' && $syncLog->error_message) {
            $report .= "Error: {$syncLog->error_message}\n";
        }

        return $report;
    }
}
