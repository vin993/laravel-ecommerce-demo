<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automated_ftp_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->date('sync_date');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'partial_success'])->default('pending');
            $table->json('update_files_detected')->nullable();
            $table->json('update_files_processed')->nullable();
            $table->integer('new_products_created')->default(0);
            $table->integer('products_updated')->default(0);
            $table->integer('categories_synced')->default(0);
            $table->integer('brands_synced')->default(0);
            $table->integer('variants_synced')->default(0);
            $table->integer('images_synced')->default(0);
            $table->integer('total_duration_seconds')->default(0);
            $table->text('error_message')->nullable();
            $table->text('error_trace')->nullable();
            $table->boolean('notification_sent')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('sync_date');
            $table->index('status');
        });

        Schema::table('ari_ftp_file_tracking', function (Blueprint $table) {
            $table->unsignedBigInteger('auto_sync_log_id')->nullable()->after('id');
            $table->boolean('processed_by_automation')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ari_ftp_file_tracking', function (Blueprint $table) {
            $table->dropColumn(['auto_sync_log_id', 'processed_by_automation']);
        });

        Schema::dropIfExists('automated_ftp_sync_logs');
    }
};
