<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automated_ftp_sync_logs', function (Blueprint $table) {
            $table->integer('vehicle_fitments_synced')->default(0)->after('images_synced');
            $table->integer('product_flat_synced')->default(0)->after('vehicle_fitments_synced');
        });
    }

    public function down(): void
    {
        Schema::table('automated_ftp_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['vehicle_fitments_synced', 'product_flat_synced']);
        });
    }
};
