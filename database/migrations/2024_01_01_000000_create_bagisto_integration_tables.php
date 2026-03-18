<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Integration tables to map DataStream data to Bagisto products
     */
    public function up(): void
    {
        // DataStream to Bagisto Product Mapping
        Schema::create('ds_bagisto_product_mapping', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ds_part_id');
            $table->unsignedInteger('bagisto_product_id');
            $table->enum('sync_status', ['pending', 'synced', 'error', 'updated']);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('sync_errors')->nullable();
            $table->json('transformation_notes')->nullable(); // Store any data transformations applied
            $table->timestamps();
            
            $table->foreign('ds_part_id')->references('part_id')->on('ds_partmaster');
            $table->foreign('bagisto_product_id')->references('id')->on('products');
            $table->unique(['ds_part_id', 'bagisto_product_id'], 'ds_product_mapping_unique_idx');
            $table->index(['sync_status', 'last_synced_at'], 'ds_product_mapping_sync_status_idx');
        });

        // DataStream to Bagisto Category Mapping
        Schema::create('ds_bagisto_category_mapping', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ds_level_master_id');
            $table->unsignedInteger('bagisto_category_id');
            $table->string('category_path', 500)->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'error']);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->foreign('ds_level_master_id')->references('level_master_id')->on('ds_level_master');
            if (Schema::hasTable('categories')) {
                $table->foreign('bagisto_category_id')->references('id')->on('categories');
            }
            $table->unique(['ds_level_master_id', 'bagisto_category_id'], 'ds_category_mapping_unique_idx');
        });

        // DataStream to Bagisto Attribute Mapping
        Schema::create('ds_bagisto_attribute_mapping', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ds_attribute_type_id');
            $table->unsignedInteger('bagisto_attribute_id');
            $table->string('transformation_rule')->nullable(); // How to transform the data
            $table->enum('sync_status', ['pending', 'synced', 'error']);
            $table->timestamps();
            
            $table->foreign('ds_attribute_type_id')->references('attribute_type_id')->on('ds_attribute_types');
            $table->foreign('bagisto_attribute_id')->references('id')->on('attributes');
            $table->unique(['ds_attribute_type_id', 'bagisto_attribute_id'], 'ds_attribute_mapping_unique_idx');
        });

        // Vehicle Fitment Custom Table (Bagisto doesn't have this by default)
        Schema::create('ds_vehicle_fitment', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bagisto_product_id');
            $table->string('vehicle_type', 50);
            $table->string('make', 100);
            $table->string('model', 100);
            $table->string('year_start', 10);
            $table->string('year_end', 10)->nullable();
            $table->text('fitment_notes')->nullable();
            $table->boolean('verified')->default(false);
            $table->timestamps();
            
            $table->foreign('bagisto_product_id')->references('id')->on('products');
            $table->index(['vehicle_type', 'make', 'model'], 'ds_fitment_vehicle_make_model_idx');
            $table->index(['bagisto_product_id', 'vehicle_type'], 'ds_fitment_product_vehicle_idx');
        });

        // Sync Operations Log for Integration
        Schema::create('ds_bagisto_sync_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ftp_sync_operation_id')->nullable();
            $table->enum('operation_type', ['product_sync', 'category_sync', 'attribute_sync', 'image_sync', 'inventory_sync']);
            $table->enum('status', ['started', 'processing', 'completed', 'failed']);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('records_processed')->default(0);
            $table->integer('records_created')->default(0);
            $table->integer('records_updated')->default(0);
            $table->integer('records_failed')->default(0);
            $table->json('processing_stats')->nullable();
            $table->json('error_summary')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->foreign('ftp_sync_operation_id')->references('id')->on('ari_ftp_sync_operations');
            $table->index(['operation_type', 'status', 'started_at'], 'ds_sync_log_op_status_time_idx');
        });

        // Product Pricing History (for price change tracking)
        Schema::create('ds_product_pricing_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bagisto_product_id');
            $table->unsignedBigInteger('ds_part_price_inv_id');
            $table->decimal('old_price', 10, 2)->nullable();
            $table->decimal('new_price', 10, 2);
            $table->string('price_type', 20); // 'msrp', 'standard', 'best'
            $table->string('distributor_name', 100);
            $table->timestamp('changed_at');
            $table->timestamps();
            
            $table->foreign('bagisto_product_id')->references('id')->on('products');
            $table->foreign('ds_part_price_inv_id')->references('part_price_inv_id')->on('ds_part_price_inv');
            $table->index(['bagisto_product_id', 'changed_at'], 'ds_pricing_history_product_time_idx');
        });

        // Inventory Sync Status
        Schema::create('ds_inventory_sync_status', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('bagisto_product_id');
            $table->unsignedBigInteger('ds_part_id');
            $table->integer('total_available_qty')->default(0);
            $table->json('warehouse_quantities')->nullable(); // JSON of warehouse_id => qty
            $table->timestamp('last_inventory_sync')->nullable();
            $table->boolean('stock_alert_sent')->default(false);
            $table->integer('low_stock_threshold')->default(5);
            $table->timestamps();
            
            $table->foreign('bagisto_product_id')->references('id')->on('products');
            $table->foreign('ds_part_id')->references('part_id')->on('ds_partmaster');
            $table->unique(['bagisto_product_id', 'ds_part_id'], 'ds_inventory_sync_unique_idx');
        });

        // Data Quality Issues
        Schema::create('ds_data_quality_issues', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 50); // 'product', 'category', 'attribute', etc.
            $table->unsignedBigInteger('entity_id');
            $table->string('issue_type', 100); // 'missing_image', 'invalid_price', 'duplicate_sku', etc.
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->text('description');
            $table->json('issue_details')->nullable();
            $table->boolean('resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            
            $table->index(['entity_type', 'issue_type', 'severity'], 'ds_quality_entity_issue_severity_idx');
            $table->index(['resolved', 'severity'], 'ds_quality_resolved_severity_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ds_data_quality_issues');
        Schema::dropIfExists('ds_inventory_sync_status');
        Schema::dropIfExists('ds_product_pricing_history');
        Schema::dropIfExists('ds_bagisto_sync_log');
        Schema::dropIfExists('ds_vehicle_fitment');
        Schema::dropIfExists('ds_bagisto_attribute_mapping');
        Schema::dropIfExists('ds_bagisto_category_mapping');
        Schema::dropIfExists('ds_bagisto_product_mapping');
    }
};
