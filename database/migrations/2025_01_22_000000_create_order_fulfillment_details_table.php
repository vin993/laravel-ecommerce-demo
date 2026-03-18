<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $orderIdType = DB::select("SHOW COLUMNS FROM orders WHERE Field = 'id'")[0]->Type ?? 'int';

        Schema::create('order_fulfillment_details', function (Blueprint $table) use ($orderIdType) {
            $table->id();

            if (str_contains($orderIdType, 'bigint')) {
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('order_item_id')->nullable();
            } else {
                $table->unsignedInteger('order_id');
                $table->unsignedInteger('order_item_id')->nullable();
            }

            $table->string('item_sku')->nullable();
            $table->string('supplier')->nullable();
            $table->string('fulfillment_type');
            $table->string('status')->default('pending');
            $table->text('request_data')->nullable();
            $table->text('response_data')->nullable();
            $table->text('error_message')->nullable();
            $table->string('external_order_id')->nullable();
            $table->string('external_po_number')->nullable();
            $table->string('tracking_number')->nullable();
            $table->decimal('item_price', 10, 2)->nullable();
            $table->integer('item_quantity')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'supplier']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_fulfillment_details');
    }
};
