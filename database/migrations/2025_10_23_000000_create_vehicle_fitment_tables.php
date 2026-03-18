<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ds_vehicle_types', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('description', 100)->nullable();
            $table->char('update_flag', 1)->default('I');
            $table->index('description');
        });

        Schema::create('ds_makes', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('description', 255)->nullable();
            $table->char('update_flag', 1)->default('I');
            $table->index('description');
        });

        Schema::create('ds_models', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('description', 255)->nullable();
            $table->char('update_flag', 1)->default('I');
            $table->index('description');
        });

        Schema::create('ds_years', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('description', 50)->nullable();
            $table->char('update_flag', 1)->default('I');
            $table->index('description');
        });

        Schema::create('ds_type_make_model_year', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('vehicle_type_id')->nullable();
            $table->unsignedBigInteger('make_id')->nullable();
            $table->unsignedBigInteger('model_id')->nullable();
            $table->unsignedBigInteger('year_id')->nullable();
            $table->char('update_flag', 1)->default('I');
            
            $table->index('vehicle_type_id');
            $table->index('make_id');
            $table->index('model_id');
            $table->index('year_id');
            $table->index(['vehicle_type_id', 'make_id', 'model_id', 'year_id'], 'tmmy_composite');
        });

        Schema::create('ds_applications', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('description', 255)->nullable();
            $table->char('update_flag', 1)->default('I');
            $table->index('description');
        });

        Schema::create('ds_application_combination', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('application1')->default(0);
            $table->unsignedBigInteger('application2')->default(0);
            $table->unsignedBigInteger('application3')->default(0);
            $table->unsignedBigInteger('application4')->default(0);
            $table->unsignedBigInteger('application5')->default(0);
            $table->unsignedBigInteger('fitment_notes_id')->default(0);
            $table->char('update_flag', 1)->default('I');
            
            $table->index('application1');
            $table->index('application2');
        });

        Schema::create('ds_part_to_app_combo', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('partmaster_id');
            $table->unsignedBigInteger('application_combo_id');
            $table->char('update_flag', 1)->default('I');
            
            $table->index('partmaster_id');
            $table->index('application_combo_id');
            $table->index(['partmaster_id', 'application_combo_id'], 'part_app_combo');
        });

        Schema::create('ds_fitment', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('tmmy_id');
            $table->unsignedBigInteger('part_to_app_combo_id');
            $table->char('update_flag', 1)->default('I');
            
            $table->index('tmmy_id');
            $table->index('part_to_app_combo_id');
            $table->index(['tmmy_id', 'part_to_app_combo_id'], 'fitment_composite');
        });

        Schema::create('product_vehicle_fitment', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->unsignedBigInteger('tmmy_id');
            $table->timestamps();
            
            $table->index('product_id');
            $table->index('tmmy_id');
            $table->unique(['product_id', 'tmmy_id']);
            
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_vehicle_fitment');
        Schema::dropIfExists('ds_fitment');
        Schema::dropIfExists('ds_part_to_app_combo');
        Schema::dropIfExists('ds_application_combination');
        Schema::dropIfExists('ds_applications');
        Schema::dropIfExists('ds_type_make_model_year');
        Schema::dropIfExists('ds_years');
        Schema::dropIfExists('ds_models');
        Schema::dropIfExists('ds_makes');
        Schema::dropIfExists('ds_vehicle_types');
    }
};
