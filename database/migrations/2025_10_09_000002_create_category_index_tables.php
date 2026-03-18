<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ds_level_two_index', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('description', 255);
        });

        Schema::create('ds_level_three_index', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('description', 255);
        });

        Schema::create('ds_level_four_index', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('description', 255);
        });

        Schema::create('ds_level_five_index', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('description', 255);
        });

        Schema::create('ds_level_master_index', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('level_two_id', 20)->nullable();
            $table->string('level_three_id', 20)->nullable();
            $table->string('level_four_id', 20)->nullable();
            $table->string('level_five_id', 20)->nullable();
            $table->integer('bagisto_category_id')->nullable();
            $table->index(['level_two_id', 'level_three_id', 'level_four_id', 'level_five_id'], 'level_combo_index');
        });

        Schema::create('ds_category_product_index', function (Blueprint $table) {
            $table->id();
            $table->string('partmaster_id', 50);
            $table->string('level_master_id', 20);
            $table->index('partmaster_id');
            $table->index('level_master_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ds_level_two_index');
        Schema::dropIfExists('ds_level_three_index');
        Schema::dropIfExists('ds_level_four_index');
        Schema::dropIfExists('ds_level_five_index');
        Schema::dropIfExists('ds_level_master_index');
        Schema::dropIfExists('ds_category_product_index');
    }
};
