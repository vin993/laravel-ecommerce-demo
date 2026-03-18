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
        // Table to track sync history and state
        Schema::create('kawasaki_sync_state', function (Blueprint $table) {
            $table->id();
            $table->dateTime('sync_date');
            $table->string('file_name');
            $table->string('file_checksum', 64)->nullable();
            $table->integer('items_processed')->default(0);
            $table->integer('items_created')->default(0);
            $table->integer('items_updated')->default(0);
            $table->integer('items_skipped')->default(0);
            $table->integer('prices_changed')->default(0);
            $table->integer('inventory_updated')->default(0);
            $table->integer('images_added')->default(0);
            $table->integer('variants_grouped')->default(0);
            $table->integer('duration_seconds')->default(0);
            $table->enum('status', ['running', 'completed', 'failed'])->default('running');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index('sync_date');
            $table->index('status');
        });

        // Table to store product snapshots for change detection
        Schema::create('kawasaki_product_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->text('last_xml_data'); // JSON snapshot of XML node
            $table->string('checksum', 64);
            $table->dateTime('last_synced_at');
            $table->timestamps();
            
            $table->index('sku');
            $table->index('last_synced_at');
            $table->index('checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kawasaki_product_snapshots');
        Schema::dropIfExists('kawasaki_sync_state');
    }
};
