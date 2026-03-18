<?php

namespace App\Models\DataStream;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FtpSyncOperation extends Model
{
    use HasFactory;

    protected $table = 'ds_ftp_sync_operations';

    protected $fillable = [
        'operation_type',
        'status',
        'started_at',
        'completed_at',
        'total_files_found',
        'files_downloaded',
        'files_processed',
        'total_records',
        'error_details',
        'notes'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'error_details' => 'array'
    ];

    // Relationship to file tracking
    public function fileTrackings()
    {
        return $this->hasMany(FtpFileTracking::class, 'sync_operation_id');
    }
}
