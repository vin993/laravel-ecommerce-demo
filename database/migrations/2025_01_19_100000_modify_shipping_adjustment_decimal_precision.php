<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('additional_shipping_amount', 12, 2)->default(0)->change();
            $table->decimal('pending_payment_amount', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('additional_shipping_amount', 12, 4)->default(0)->change();
            $table->decimal('pending_payment_amount', 12, 4)->default(0)->change();
        });
    }
};
