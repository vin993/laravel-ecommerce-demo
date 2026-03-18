<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ds_variant_groups', function (Blueprint $table) {
            $table->id();
            $table->string('variant_group_id', 100);
            $table->string('partmaster_id', 50);
            $table->string('base_name', 255);
            $table->string('base_sku', 100);
            $table->string('variant_type', 50)->nullable();
            $table->string('variant_value', 100)->nullable();
            $table->integer('group_size')->default(1);
            $table->index('variant_group_id');
            $table->index('partmaster_id');
            $table->index('base_sku');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ds_variant_groups');
    }
};
