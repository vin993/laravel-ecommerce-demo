<?php

namespace App\Models\AutomatedSync;

use Illuminate\Database\Eloquent\Model;

class FtpSyncLog extends Model
{
    protected $table = 'automated_ftp_sync_logs';

    protected $fillable = [
        'sync_date',
        'status',
        'update_files_detected',
        'update_files_processed',
        'new_products_created',
        'products_updated',
        'categories_synced',
        'brands_synced',
        'variants_synced',
        'images_synced',
        'vehicle_fitments_synced',
        'product_flat_synced',
        'total_duration_seconds',
        'error_message',
        'error_trace',
        'notification_sent',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'sync_date' => 'date',
        'update_files_detected' => 'array',
        'update_files_processed' => 'array',
        'notification_sent' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function markAsRunning(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'total_duration_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : 0,
        ]);
    }

    public function markAsFailed(string $errorMessage, string $errorTrace = null): void
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $errorMessage,
            'error_trace' => $errorTrace,
            'total_duration_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : 0,
        ]);
    }

    public function markAsPartialSuccess(): void
    {
        $this->update([
            'status' => 'partial_success',
            'completed_at' => now(),
            'total_duration_seconds' => $this->started_at ? now()->diffInSeconds($this->started_at) : 0,
        ]);
    }

    public function addProcessedFile(string $fileName): void
    {
        $processed = $this->update_files_processed ?? [];
        $processed[] = $fileName;
        $this->update(['update_files_processed' => $processed]);
    }

    public function incrementStats(array $stats): void
    {
        $this->increment('new_products_created', $stats['created'] ?? 0);
        $this->increment('products_updated', $stats['updated'] ?? 0);
        $this->increment('categories_synced', $stats['categories'] ?? 0);
        $this->increment('brands_synced', $stats['brands'] ?? 0);
        $this->increment('variants_synced', $stats['variants'] ?? 0);
        $this->increment('images_synced', $stats['images'] ?? 0);
        $this->increment('vehicle_fitments_synced', $stats['vehicle_fitments'] ?? 0);
        $this->increment('product_flat_synced', $stats['product_flat'] ?? 0);
    }

    public function markNotificationSent(): void
    {
        $this->update(['notification_sent' => true]);
    }
}
