<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Create missing target tables for DataStream transformation
     */
    public function up(): void
    {
        // Products table (simplified version for transformer compatibility)
        if (!Schema::hasTable('ds_products')) {
            Schema::create('ds_products', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_part_id')->unique();
                $table->string('part_number', 100)->nullable();
                $table->unsignedBigInteger('ds_manufacturer_id')->nullable();
                $table->unsignedBigInteger('ds_brand_id')->nullable();
                $table->string('name', 200)->nullable();
                $table->text('description')->nullable();
                $table->timestamps();
                
                $table->index(['ds_part_id', 'part_number'], 'ds_products_part_number_idx');
                $table->index(['ds_manufacturer_id', 'ds_brand_id'], 'ds_products_mfr_brand_idx');
            });
        }

        // Pricing table (simplified version for transformer compatibility)
        if (!Schema::hasTable('ds_pricing')) {
            Schema::create('ds_pricing', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_part_id');
                $table->unsignedBigInteger('ds_distributor_id');
                $table->decimal('list_price', 10, 2)->nullable();
                $table->decimal('your_price', 10, 2)->nullable();
                $table->timestamp('effective_date')->nullable();
                $table->timestamps();
                
                $table->unique(['ds_part_id', 'ds_distributor_id'], 'ds_pricing_part_distributor_unique');
                $table->index(['ds_part_id', 'effective_date'], 'ds_pricing_part_date_idx');
            });
        }

        // Inventory table (simplified version for transformer compatibility)
        if (!Schema::hasTable('ds_inventory')) {
            Schema::create('ds_inventory', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_part_id');
                $table->unsignedBigInteger('ds_distributor_id');
                $table->integer('quantity_available')->default(0);
                $table->integer('quantity_on_order')->default(0);
                $table->timestamps();
                
                $table->unique(['ds_part_id', 'ds_distributor_id'], 'ds_inventory_part_distributor_unique');
                $table->index(['ds_part_id', 'quantity_available'], 'ds_inventory_part_qty_idx');
            });
        }

        // Fitment table (simplified version for transformer compatibility)
        if (!Schema::hasTable('ds_fitment')) {
            Schema::create('ds_fitment', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_part_id');
                $table->unsignedBigInteger('ds_application_id')->nullable();
                $table->unsignedBigInteger('ds_make_id')->nullable();
                $table->unsignedBigInteger('ds_model_id')->nullable();
                $table->unsignedBigInteger('ds_year_id')->nullable();
                $table->unsignedBigInteger('ds_engine_id')->nullable();
                $table->timestamps();
                
                $table->index(['ds_part_id', 'ds_application_id'], 'ds_fitment_part_app_idx');
                $table->index(['ds_make_id', 'ds_model_id', 'ds_year_id'], 'ds_fitment_mmy_idx');
            });
        }

        // Product attributes table (simplified version for transformer compatibility)
        if (!Schema::hasTable('ds_product_attributes')) {
            Schema::create('ds_product_attributes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_part_id');
                $table->unsignedBigInteger('ds_attribute_id');
                $table->string('attribute_value', 500)->nullable();
                $table->timestamps();
                
                $table->unique(['ds_part_id', 'ds_attribute_id'], 'ds_product_attrs_part_attr_unique');
                $table->index('ds_part_id', 'ds_product_attrs_part_idx');
            });
        }

        // Groupings table (simplified version for transformer compatibility)
        if (!Schema::hasTable('ds_groupings')) {
            Schema::create('ds_groupings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_part_id');
                $table->unsignedBigInteger('ds_group_id');
                $table->timestamps();
                
                $table->unique(['ds_part_id', 'ds_group_id'], 'ds_groupings_part_group_unique');
                $table->index(['ds_group_id', 'ds_part_id'], 'ds_groupings_group_part_idx');
            });
        }

        // Engines table (for reference data)
        if (!Schema::hasTable('ds_engines')) {
            Schema::create('ds_engines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_engine_id')->unique();
                $table->string('name', 100)->nullable();
                $table->decimal('displacement', 8, 2)->nullable();
                $table->timestamps();
                
                $table->index(['ds_engine_id', 'name'], 'ds_engines_id_name_idx');
            });
        }

        // Categories table (for reference data) 
        if (!Schema::hasTable('ds_categories')) {
            Schema::create('ds_categories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_category_id')->unique();
                $table->string('name', 100)->nullable();
                $table->unsignedBigInteger('parent_category_id')->nullable();
                $table->timestamps();
                
                $table->index(['ds_category_id', 'parent_category_id'], 'ds_categories_id_parent_idx');
            });
        }

        // Groups table (for reference data)
        if (!Schema::hasTable('ds_groups')) {
            Schema::create('ds_groups', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ds_group_id')->unique();
                $table->string('name', 100)->nullable();
                $table->unsignedBigInteger('parent_group_id')->nullable();
                $table->integer('level')->default(1);
                $table->timestamps();
                
                $table->index(['ds_group_id', 'parent_group_id'], 'ds_groups_id_parent_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ds_groups');
        Schema::dropIfExists('ds_categories');
        Schema::dropIfExists('ds_engines');
        Schema::dropIfExists('ds_groupings');
        Schema::dropIfExists('ds_product_attributes');
        Schema::dropIfExists('ds_fitment');
        Schema::dropIfExists('ds_images');
        Schema::dropIfExists('ds_inventory');
        Schema::dropIfExists('ds_pricing');
        Schema::dropIfExists('ds_products');
    }
};
