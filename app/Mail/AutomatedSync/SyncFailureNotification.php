<?php

namespace App\Mail\AutomatedSync;

use App\Models\AutomatedSync\FtpSyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SyncFailureNotification extends Mailable
{
    use Queueable, SerializesModels;

    public FtpSyncLog $syncLog;

    public function __construct(FtpSyncLog $syncLog)
    {
        $this->syncLog = $syncLog;
    }

    public function build()
    {
        $subject = 'Automated FTP Sync FAILED - ' . $this->syncLog->sync_date->format('M d, Y');

        return $this->subject($subject)
            ->view('emails.automated-sync.failure')
            ->with([
                'syncLog' => $this->syncLog,
                'duration' => gmdate('H:i:s', $this->syncLog->total_duration_seconds),
                'filesDetected' => count($this->syncLog->update_files_detected ?? []),
            ]);
    }
}
