<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Main DataStream tables for products, pricing, inventory, and fitment
     */
    public function up(): void
    {
        // Part Master - Main product catalog
        Schema::create('ds_partmaster', function (Blueprint $table) {
            $table->id('part_id'); // Use original ID from CSV
            $table->unsignedBigInteger('manufacturer_id');
            $table->string('manufacturer_number_short', 100)->nullable();
            $table->string('manufacturer_number_long', 100)->nullable();
            $table->string('item_name', 200)->nullable();
            $table->text('item_description')->nullable();
            $table->decimal('weight', 10, 2)->nullable();
            $table->decimal('height', 10, 2)->nullable();
            $table->decimal('width', 10, 2)->nullable();
            $table->decimal('length', 10, 2)->nullable();
            $table->decimal('volume', 10, 2)->nullable();
            $table->text('general_notes')->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->timestamp('last_modified')->nullable();
            $table->timestamps();
            
            $table->foreign('manufacturer_id')->references('manufacturer_id')->on('ds_manufacturers');
            $table->index(['manufacturer_id', 'manufacturer_number_short'], 'ds_partmaster_mfr_id_number_idx');
            $table->index('update_flag', 'ds_partmaster_update_flag_idx');
        });

        // Part Price Inventory - Distributor pricing and availability
        Schema::create('ds_part_price_inv', function (Blueprint $table) {
            $table->id('part_price_inv_id');
            $table->unsignedBigInteger('distributor_id');
            $table->string('distributor_part_number_short', 100)->nullable();
            $table->string('distributor_part_number_long', 100)->nullable();
            $table->unsignedBigInteger('part_id');
            $table->integer('distributor_qty')->nullable();
            $table->decimal('msrp', 10, 2)->nullable();
            $table->decimal('standard_price', 10, 2)->nullable();
            $table->decimal('best_price', 10, 2)->nullable();
            $table->unsignedBigInteger('brand_id');
            $table->string('available', 10)->nullable();
            $table->string('distributor_name', 100)->nullable();
            $table->boolean('discontinued')->default(false);
            $table->boolean('special_order')->default(false);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('distributor_id')->references('distributor_id')->on('ds_distributors');
            $table->foreign('part_id')->references('part_id')->on('ds_partmaster');
            $table->foreign('brand_id')->references('brand_id')->on('ds_brands');
            $table->index(['part_id', 'distributor_id'], 'ds_price_part_distributor_idx');
            $table->index(['distributor_part_number_short', 'brand_id'], 'ds_price_dist_number_brand_idx');
        });

        // Distributor Inventory - Stock levels by warehouse
        Schema::create('ds_distributor_inventory', function (Blueprint $table) {
            $table->id('inventory_id');
            $table->unsignedBigInteger('part_price_inv_id');
            $table->unsignedBigInteger('distributor_warehouse_id');
            $table->integer('qty')->default(0);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('part_price_inv_id')->references('part_price_inv_id')->on('ds_part_price_inv');
            $table->foreign('distributor_warehouse_id')->references('distributor_warehouse_id')->on('ds_distributor_warehouses');
            $table->index(['part_price_inv_id', 'distributor_warehouse_id'], 'ds_inventory_price_warehouse_idx');
        });

        // Images - Product images
        Schema::create('ds_images', function (Blueprint $table) {
            $table->id('image_id');
            $table->unsignedBigInteger('part_id');
            $table->string('hi_res_image_name', 200)->nullable();
            $table->timestamp('date_modified')->nullable();
            $table->boolean('special_image')->default(false);
            $table->string('local_image_path', 500)->nullable();
            $table->string('image_hash', 64)->nullable();
            $table->integer('image_size')->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('part_id')->references('part_id')->on('ds_partmaster');
            $table->index(['part_id', 'special_image'], 'ds_images_part_special_idx');
            $table->index('hi_res_image_name', 'ds_images_hi_res_name_idx');
        });

        // Categories - Product categorization
        Schema::create('ds_categories', function (Blueprint $table) {
            $table->id('category_id');
            $table->unsignedBigInteger('level_master_id');
            $table->unsignedBigInteger('part_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('level_master_id')->references('level_master_id')->on('ds_level_master');
            $table->foreign('part_id')->references('part_id')->on('ds_partmaster');
            $table->index(['part_id', 'level_master_id'], 'ds_categories_part_level_idx');
        });

        // Part to Application Combination - Links parts to vehicle applications
        Schema::create('ds_part_to_app_combo', function (Blueprint $table) {
            $table->id('part_to_app_combo_id');
            $table->unsignedBigInteger('part_id');
            $table->unsignedBigInteger('application_combination_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('part_id')->references('part_id')->on('ds_partmaster');
            $table->foreign('application_combination_id')->references('application_combination_id')->on('ds_application_combinations');
            $table->index(['part_id', 'application_combination_id'], 'ds_part_app_combo_part_app_idx');
        });

        // Fitment - Vehicle compatibility data
        Schema::create('ds_fitment', function (Blueprint $table) {
            $table->id('fitment_id');
            $table->unsignedBigInteger('tmmy_id');
            $table->unsignedBigInteger('part_to_app_combo_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('tmmy_id')->references('tmmy_id')->on('ds_type_make_model_year');
            $table->foreign('part_to_app_combo_id')->references('part_to_app_combo_id')->on('ds_part_to_app_combo');
            $table->index(['tmmy_id', 'part_to_app_combo_id'], 'ds_fitment_tmmy_part_combo_idx');
        });

        // Attributes to Parts - Product specifications
        Schema::create('ds_attributes_to_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('part_id');
            $table->unsignedBigInteger('attribute_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('part_id')->references('part_id')->on('ds_partmaster');
            $table->foreign('attribute_id')->references('attribute_id')->on('ds_attributes');
            $table->index(['part_id', 'attribute_id'], 'ds_attr_parts_part_attr_idx');
        });

        // Part Groupings - Product groupings and subcategories
        Schema::create('ds_part_groupings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('part_id');
            $table->unsignedBigInteger('group_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('part_id')->references('part_id')->on('ds_partmaster');
            $table->foreign('group_id')->references('group_id')->on('ds_groups');
            $table->index(['part_id', 'group_id'], 'ds_part_groups_part_group_idx');
        });

        // Associated Parts - Related/accessory parts
        Schema::create('ds_associated_parts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('part_id_1');
            $table->unsignedBigInteger('part_id_2');
            $table->boolean('required_part')->default(false);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('part_id_1')->references('part_id')->on('ds_partmaster');
            $table->foreign('part_id_2')->references('part_id')->on('ds_partmaster');
            $table->index(['part_id_1', 'part_id_2'], 'ds_assoc_parts_part1_part2_idx');
        });

        // Kits - Product kits
        Schema::create('ds_kits', function (Blueprint $table) {
            $table->id('kit_id');
            $table->unsignedBigInteger('primary_part_id');
            $table->unsignedBigInteger('replacement_part_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('primary_part_id')->references('part_id')->on('ds_partmaster');
            $table->foreign('replacement_part_id')->references('part_id')->on('ds_partmaster');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ds_kits');
        Schema::dropIfExists('ds_associated_parts');
        Schema::dropIfExists('ds_part_groupings');
        Schema::dropIfExists('ds_attributes_to_parts');
        Schema::dropIfExists('ds_fitment');
        Schema::dropIfExists('ds_part_to_app_combo');
        Schema::dropIfExists('ds_categories');
        Schema::dropIfExists('ds_images');
        Schema::dropIfExists('ds_distributor_inventory');
        Schema::dropIfExists('ds_part_price_inv');
        Schema::dropIfExists('ds_partmaster');
    }
};
