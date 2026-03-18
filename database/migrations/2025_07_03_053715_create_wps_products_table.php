<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('wps_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wps_product_id')->unique();
            $table->unsignedInteger('bagisto_product_id')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['pending', 'syncing', 'synced', 'error'])->default('pending');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('sync_errors')->nullable();
            $table->integer('total_items')->default(0);
            $table->integer('synced_items')->default(0);
            $table->timestamps();
            
            $table->index('wps_product_id');
            $table->index('bagisto_product_id');
            $table->index('status');
            $table->index('last_synced_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wps_products');
    }
};