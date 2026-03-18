<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ari_partstream_order_items', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 4)->default(0)->after('total');
            $table->decimal('base_tax_amount', 12, 4)->default(0)->after('tax_amount');
        });
    }

    public function down()
    {
        Schema::table('ari_partstream_order_items', function (Blueprint $table) {
            $table->dropColumn(['tax_amount', 'base_tax_amount']);
        });
    }
};
