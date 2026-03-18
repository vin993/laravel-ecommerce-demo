<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * These tables store raw data exactly as received from FTP files
     */
    public function up(): void
    {
        // Raw Partmaster data from CSV
        Schema::create('ari_staging_partmaster', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('ari_id', 50); // Original ID from CSV (handle case sensitivity)
            $table->string('manufacturer_id', 50)->nullable();
            $table->string('manufacturer_number_short', 100)->nullable();
            $table->string('manufacturer_number_long', 100)->nullable();
            $table->string('item_name', 200)->nullable();
            $table->text('item_description')->nullable();
            $table->string('weight', 20)->nullable();
            $table->string('height', 20)->nullable();
            $table->string('width', 20)->nullable();
            $table->string('length', 20)->nullable();
            $table->string('volume', 20)->nullable();
            $table->text('general_notes')->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->json('raw_data'); // Store complete original row as JSON
            $table->boolean('processed')->default(false);
            $table->text('processing_errors')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations');
            $table->index(['sync_operation_id', 'update_flag'], 'staging_partmaster_sync_flag_idx');
            $table->index(['ari_id', 'processed'], 'staging_partmaster_ari_processed_idx');
        });

        // Raw Images data from CSV
        Schema::create('ari_staging_images', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('ari_id', 50); // Original ID from CSV
            $table->string('partmaster_id', 50)->nullable();
            $table->string('hi_res_image_name', 200)->nullable();
            $table->string('date_modified', 50)->nullable();
            $table->string('special_image', 10)->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->json('raw_data');
            $table->boolean('processed')->default(false);
            $table->text('processing_errors')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations');
            $table->index(['sync_operation_id', 'processed'], 'staging_images_sync_processed_idx');
            $table->index(['partmaster_id', 'processed'], 'staging_images_part_processed_idx');
        });

        // Raw Fitment data from CSV
        Schema::create('ari_staging_fitment', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('ari_id', 50);
            $table->string('tmmy_id', 50)->nullable();
            $table->string('part_to_app_combo_id', 50)->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->json('raw_data');
            $table->boolean('processed')->default(false);
            $table->text('processing_errors')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations');
            $table->index(['sync_operation_id', 'processed'], 'staging_fitment_sync_processed_idx');
        });

        // Raw Distributor Inventory data from CSV
        Schema::create('ari_staging_distributor_inventory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('ari_id', 50);
            $table->string('part_price_inv_id', 50)->nullable();
            $table->string('qty', 20)->nullable();
            $table->string('distributor_warehouse_id', 50)->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->json('raw_data');
            $table->boolean('processed')->default(false);
            $table->text('processing_errors')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations');
            $table->index(['sync_operation_id', 'processed'], 'staging_inventory_sync_processed_idx');
        });

        // Raw Part Price Inv data from CSV
        Schema::create('ari_staging_part_price_inv', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('ari_id', 50);
            $table->string('distributor_id', 50)->nullable();
            $table->string('distributor_part_number_short', 100)->nullable();
            $table->string('distributor_part_number_long', 100)->nullable();
            $table->string('partmaster_id', 50)->nullable();
            $table->string('distributor_qty', 20)->nullable();
            $table->string('msrp', 20)->nullable();
            $table->string('standard_price', 20)->nullable();
            $table->string('best_price', 20)->nullable();
            $table->string('brand_id', 50)->nullable();
            $table->string('available', 10)->nullable();
            $table->string('distributor_name', 200)->nullable();
            $table->string('discontinued', 10)->nullable();
            $table->string('special_order', 10)->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->json('raw_data');
            $table->boolean('processed')->default(false);
            $table->text('processing_errors')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations');
            $table->index(['sync_operation_id', 'processed'], 'staging_price_sync_processed_idx');
            $table->index(['partmaster_id', 'processed'], 'staging_price_part_processed_idx');
        });

        // Generic staging table for other CSV files
        Schema::create('ari_staging_generic', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('entity_name', 50); // 'brands', 'makes', 'models', etc.
            $table->string('ari_id', 50)->nullable();
            $table->json('raw_data'); // Complete row data
            $table->boolean('processed')->default(false);
            $table->text('processing_errors')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations');
            $table->index(['sync_operation_id', 'entity_name', 'processed'], 'staging_generic_sync_entity_idx');
        });

        // Image File Downloads Tracking
        Schema::create('ari_staging_image_downloads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_operation_id');
            $table->string('brand_name', 50);
            $table->string('archive_name', 100);
            $table->string('image_filename', 200);
            $table->string('local_path', 500)->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->enum('status', ['pending', 'downloaded', 'processed', 'error']);
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->foreign('sync_operation_id')->references('id')->on('ds_ftp_sync_operations');
            $table->index(['brand_name', 'status'], 'staging_images_brand_status_idx');
            $table->unique(['archive_name', 'image_filename'], 'staging_images_unique_file_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ari_staging_image_downloads');
        Schema::dropIfExists('ari_staging_generic');
        Schema::dropIfExists('ari_staging_part_price_inv');
        Schema::dropIfExists('ari_staging_distributor_inventory');
        Schema::dropIfExists('ari_staging_fitment');
        Schema::dropIfExists('ari_staging_images');
        Schema::dropIfExists('ari_staging_partmaster');
    }
};
