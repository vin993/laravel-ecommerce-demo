<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price and inventory index
        Schema::create('ds_price_index', function (Blueprint $table) {
            $table->string('partmaster_id', 50)->primary();
            $table->decimal('msrp', 10, 2)->nullable();
            $table->decimal('standard_price', 10, 2)->nullable();
            $table->decimal('best_price', 10, 2)->nullable();
            $table->integer('quantity')->default(0);
            $table->string('distributor_id', 20)->nullable();
            $table->index('partmaster_id');
        });

        // Image index
        Schema::create('ds_image_index', function (Blueprint $table) {
            $table->id();
            $table->string('partmaster_id', 50);
            $table->text('image_url');
            $table->tinyInteger('position')->default(1);
            $table->index(['partmaster_id', 'position']);
        });

        // Attribute index
        Schema::create('ds_attribute_index', function (Blueprint $table) {
            $table->id();
            $table->string('partmaster_id', 50);
            $table->string('attribute_id', 50);
            $table->text('attribute_description');
            $table->index('partmaster_id');
        });

        // Manufacturer index
        Schema::create('ds_manufacturer_index', function (Blueprint $table) {
            $table->string('manufacturer_id', 50)->primary();
            $table->string('manufacturer_name', 255);
            $table->integer('bagisto_category_id')->nullable();
            $table->index('manufacturer_id');
        });

        // Kit index for filtering
        Schema::create('ds_kit_index', function (Blueprint $table) {
            $table->string('primary_partmaster_id', 50)->primary();
            $table->index('primary_partmaster_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ds_price_index');
        Schema::dropIfExists('ds_image_index');
        Schema::dropIfExists('ds_attribute_index');
        Schema::dropIfExists('ds_manufacturer_index');
        Schema::dropIfExists('ds_kit_index');
    }
};
