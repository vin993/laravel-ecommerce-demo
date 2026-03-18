<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Reference/lookup tables for DataStream FTP data
     */
    public function up(): void
    {
        // Brands
        Schema::create('ds_brands', function (Blueprint $table) {
            $table->id('brand_id'); // Use original IDs from CSV
            $table->string('brand_name', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Manufacturers
        Schema::create('ds_manufacturers', function (Blueprint $table) {
            $table->id('manufacturer_id');
            $table->string('manufacturer_name', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Vehicle Types
        Schema::create('ds_vehicle_types', function (Blueprint $table) {
            $table->id('vehicle_type_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Makes
        Schema::create('ds_makes', function (Blueprint $table) {
            $table->id('make_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Models  
        Schema::create('ds_models', function (Blueprint $table) {
            $table->id('model_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Years
        Schema::create('ds_years', function (Blueprint $table) {
            $table->id('year_id');
            $table->string('description', 10);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Type Make Model Year combinations
        Schema::create('ds_type_make_model_year', function (Blueprint $table) {
            $table->id('tmmy_id');
            $table->unsignedBigInteger('vehicle_type_id');
            $table->unsignedBigInteger('make_id');
            $table->unsignedBigInteger('model_id');
            $table->unsignedBigInteger('year_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('vehicle_type_id')->references('vehicle_type_id')->on('ds_vehicle_types');
            $table->foreign('make_id')->references('make_id')->on('ds_makes');
            $table->foreign('model_id')->references('model_id')->on('ds_models');
            $table->foreign('year_id')->references('year_id')->on('ds_years');
            
            $table->index(['vehicle_type_id', 'make_id', 'model_id', 'year_id'], 'ds_tmmy_vehicle_make_model_year_idx');
        });

        // Distributors
        Schema::create('ds_distributors', function (Blueprint $table) {
            $table->id('distributor_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Distributor Warehouses
        Schema::create('ds_distributor_warehouses', function (Blueprint $table) {
            $table->id('distributor_warehouse_id');
            $table->unsignedBigInteger('distributor_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('distributor_id')->references('distributor_id')->on('ds_distributors');
        });

        // Attribute Types
        Schema::create('ds_attribute_types', function (Blueprint $table) {
            $table->id('attribute_type_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Attributes
        Schema::create('ds_attributes', function (Blueprint $table) {
            $table->id('attribute_id');
            $table->unsignedBigInteger('attribute_type_id');
            $table->string('description', 200);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('attribute_type_id')->references('attribute_type_id')->on('ds_attribute_types');
        });

        // Applications
        Schema::create('ds_applications', function (Blueprint $table) {
            $table->id('application_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        // Application Combinations
        Schema::create('ds_application_combinations', function (Blueprint $table) {
            $table->id('application_combination_id');
            $table->unsignedBigInteger('application1')->nullable();
            $table->unsignedBigInteger('application2')->nullable();
            $table->unsignedBigInteger('application3')->nullable();
            $table->unsignedBigInteger('application4')->nullable();
            $table->unsignedBigInteger('application5')->nullable();
            $table->unsignedBigInteger('fitment_notes_id')->nullable();
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('application1')->references('application_id')->on('ds_applications');
            $table->foreign('application2')->references('application_id')->on('ds_applications');
            $table->foreign('application3')->references('application_id')->on('ds_applications');
            $table->foreign('application4')->references('application_id')->on('ds_applications');
            $table->foreign('application5')->references('application_id')->on('ds_applications');
        });

        // Category/Level System
        Schema::create('ds_level_two', function (Blueprint $table) {
            $table->id('level_two_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        Schema::create('ds_level_three', function (Blueprint $table) {
            $table->id('level_three_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        Schema::create('ds_level_four', function (Blueprint $table) {
            $table->id('level_four_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        Schema::create('ds_level_five', function (Blueprint $table) {
            $table->id('level_five_id');
            $table->string('description', 100);
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
        });

        Schema::create('ds_level_master', function (Blueprint $table) {
            $table->id('level_master_id');
            $table->unsignedBigInteger('level_two_id');
            $table->unsignedBigInteger('level_three_id');
            $table->unsignedBigInteger('level_four_id');
            $table->unsignedBigInteger('level_five_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('level_two_id')->references('level_two_id')->on('ds_level_two');
            $table->foreign('level_three_id')->references('level_three_id')->on('ds_level_three');
            $table->foreign('level_four_id')->references('level_four_id')->on('ds_level_four');
            $table->foreign('level_five_id')->references('level_five_id')->on('ds_level_five');
        });

        // Groups
        Schema::create('ds_groups', function (Blueprint $table) {
            $table->id('group_id');
            $table->string('group_name', 100);
            $table->string('group_image', 200)->nullable();
            $table->text('group_description')->nullable();
            $table->unsignedBigInteger('manufacturer_id');
            $table->string('update_flag', 5)->nullable();
            $table->timestamps();
            
            $table->foreign('manufacturer_id')->references('manufacturer_id')->on('ds_manufacturers');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ds_groups');
        Schema::dropIfExists('ds_level_master');
        Schema::dropIfExists('ds_level_five');
        Schema::dropIfExists('ds_level_four');
        Schema::dropIfExists('ds_level_three');
        Schema::dropIfExists('ds_level_two');
        Schema::dropIfExists('ds_application_combinations');
        Schema::dropIfExists('ds_applications');
        Schema::dropIfExists('ds_attributes');
        Schema::dropIfExists('ds_attribute_types');
        Schema::dropIfExists('ds_distributor_warehouses');
        Schema::dropIfExists('ds_distributors');
        Schema::dropIfExists('ds_type_make_model_year');
        Schema::dropIfExists('ds_years');
        Schema::dropIfExists('ds_models');
        Schema::dropIfExists('ds_makes');
        Schema::dropIfExists('ds_vehicle_types');
        Schema::dropIfExists('ds_manufacturers');
        Schema::dropIfExists('ds_brands');
    }
};
