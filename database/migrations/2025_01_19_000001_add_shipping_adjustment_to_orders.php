<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('additional_shipping_amount', 12, 2)->default(0)->after('shipping_amount');
            $table->string('additional_shipping_stripe_invoice_id')->nullable()->after('additional_shipping_amount');
            $table->string('additional_shipping_invoice_status')->nullable()->after('additional_shipping_stripe_invoice_id');
            $table->decimal('pending_payment_amount', 12, 2)->default(0)->after('additional_shipping_invoice_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'additional_shipping_amount',
                'additional_shipping_stripe_invoice_id',
                'additional_shipping_invoice_status',
                'pending_payment_amount'
            ]);
        });
    }
};
