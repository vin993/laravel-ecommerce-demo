<?php

namespace App\Models\DataStream;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FtpFileTracking extends Model
{
    use HasFactory;

    protected $table = 'ds_ftp_file_tracking';

    protected $fillable = [
        'filename',
        'file_type',
        'remote_path',
        'file_size',
        'file_hash',
        'remote_modified_at',
        'last_downloaded_at',
        'last_processed_at',
        'status',
        'sync_operation_id'
    ];

    protected $casts = [
        'remote_modified_at' => 'datetime',
        'last_downloaded_at' => 'datetime',
        'last_processed_at' => 'datetime'
    ];

    // Relationship to sync operation
    public function syncOperation()
    {
        return $this->belongsTo(FtpSyncOperation::class, 'sync_operation_id');
    }
}
