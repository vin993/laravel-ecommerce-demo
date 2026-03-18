<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('wps_product_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wps_product_id');
            $table->unsignedBigInteger('wps_item_id')->unique();
            $table->unsignedInteger('bagisto_product_id')->nullable();
            $table->string('sku')->unique();
            $table->string('name');
            $table->decimal('list_price', 10, 2)->default(0);
            $table->decimal('dealer_price', 10, 2)->default(0);
            $table->decimal('website_price', 10, 2)->nullable();
            $table->string('status', 50)->default('STK');
            $table->boolean('drop_ship_eligible')->default(false);
            $table->integer('inventory_total')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->json('sync_errors')->nullable();
            $table->timestamps();
            
            $table->index('wps_product_id');
            $table->index('wps_item_id');
            $table->index('bagisto_product_id');
            $table->index('sku');
            $table->index('status');
            $table->index('drop_ship_eligible');
            $table->index('inventory_total');
            $table->index('last_synced_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wps_product_items');
    }
};