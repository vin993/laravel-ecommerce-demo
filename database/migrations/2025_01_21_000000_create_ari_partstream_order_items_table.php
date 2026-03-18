<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('ari_partstream_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('order_id');
            $table->string('sku');
            $table->string('name');
            $table->string('brand')->nullable();
            $table->integer('quantity');
            $table->decimal('price', 12, 4);
            $table->decimal('total', 12, 4);
            $table->string('selected_supplier')->default('ari_partstream');
            $table->string('image_url')->nullable();
            $table->text('return_url')->nullable();
            $table->timestamps();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ari_partstream_order_items');
    }
};