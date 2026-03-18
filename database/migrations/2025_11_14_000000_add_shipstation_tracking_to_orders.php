<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipstation_order_id')->nullable()->after('status');
            $table->string('shipstation_order_key')->nullable()->after('shipstation_order_id');
            $table->string('shipstation_order_number')->nullable()->after('shipstation_order_key');

            $table->index('shipstation_order_id');
            $table->index('shipstation_order_number');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shipstation_order_id']);
            $table->dropIndex(['shipstation_order_number']);
            $table->dropColumn(['shipstation_order_id', 'shipstation_order_key', 'shipstation_order_number']);
        });
    }
};
