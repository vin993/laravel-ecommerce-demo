<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create FTP sync operations table
        Schema::create('ds_ftp_sync_operations', function (Blueprint $table) {
            $table->id();
            $table->string('operation_type'); // 'full', 'incremental', etc.
            $table->string('status')->default('started'); // 'started', 'completed', 'failed', etc.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_files_found')->default(0);
            $table->integer('files_downloaded')->default(0);
            $table->integer('files_processed')->default(0);
            $table->integer('total_records')->default(0);
            $table->json('error_details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        
        // Create FTP file tracking table
        Schema::create('ds_ftp_file_trackings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('filename');
            $table->string('file_type'); // 'update', 'csv'
            $table->bigInteger('file_size')->nullable();
            $table->timestamp('file_date')->nullable();
            $table->string('download_status')->default('pending'); // 'pending', 'downloaded', 'failed'
            $table->string('processing_status')->default('pending'); // 'pending', 'processed', 'failed'
            $table->integer('records_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->string('local_path')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations')->onDelete('cascade');
            $table->index(['sync_operation_id', 'filename']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ds_ftp_file_trackings');
        Schema::dropIfExists('ds_ftp_sync_operations');
    }
};
