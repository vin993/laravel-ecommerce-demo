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
        // FTP Sync Operations Log
        Schema::create('ari_ftp_sync_operations', function (Blueprint $table) {
            $table->id();
            $table->enum('operation_type', ['full_sync', 'incremental_sync', 'image_sync']);
            $table->enum('status', ['started', 'processing', 'completed', 'failed', 'cancelled']);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('files_downloaded')->default(0);
            $table->integer('files_processed')->default(0);
            $table->bigInteger('total_records')->default(0);
            $table->bigInteger('inserted_records')->default(0);
            $table->bigInteger('updated_records')->default(0);
            $table->bigInteger('deleted_records')->default(0);
            $table->json('processed_files')->nullable();
            $table->json('error_details')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['operation_type', 'status', 'started_at']);
        });

        // FTP File Tracking
        Schema::create('ari_ftp_file_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->enum('file_type', ['main', 'update', 'image']);
            $table->string('remote_path');
            $table->bigInteger('file_size');
            $table->string('file_hash', 64)->nullable();
            $table->timestamp('remote_modified_at');
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamp('last_processed_at')->nullable();
            $table->enum('status', ['new', 'downloaded', 'processed', 'error']);
            $table->json('processing_stats')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->unique('filename');
            $table->index(['file_type', 'status']);
            $table->index('remote_modified_at');
        });

        // Data Sync Status by Entity
        Schema::create('ari_entity_sync_status', function (Blueprint $table) {
            $table->id();
            $table->string('entity_name'); // 'partmaster', 'images', 'fitment', etc.
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_incremental_sync_at')->nullable();
            $table->bigInteger('total_records')->default(0);
            $table->json('sync_statistics')->nullable(); // insert/update/delete counts
            $table->json('data_quality_issues')->nullable();
            $table->timestamps();
            
            $table->unique('entity_name');
        });

        // Sync Configuration
        Schema::create('ari_sync_config', function (Blueprint $table) {
            $table->id();
            $table->string('config_key')->unique();
            $table->text('config_value');
            $table->string('data_type')->default('string'); // string, json, boolean, integer
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Data Validation Issues
        Schema::create('ari_data_validation_issues', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('entity_name');
            $table->string('issue_type'); // header_mismatch, missing_data, invalid_format, etc.
            $table->string('severity'); // warning, error, critical
            $table->text('description');
            $table->json('affected_records')->nullable();
            $table->boolean('resolved')->default(false);
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ari_ftp_sync_operations');
            $table->index(['entity_name', 'issue_type', 'severity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ari_data_validation_issues');
        Schema::dropIfExists('ari_sync_config');
        Schema::dropIfExists('ari_entity_sync_status');
        Schema::dropIfExists('ari_ftp_file_tracking');
        Schema::dropIfExists('ari_ftp_sync_operations');
    }
};
