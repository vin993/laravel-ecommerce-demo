<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('free_shipping_settings', function (Blueprint $table) {
            $table->boolean('flat_rate_enabled')->default(false)->after('enabled');
            $table->decimal('flat_rate_amount', 10, 2)->default(9.99)->after('flat_rate_enabled');
        });

        DB::table('free_shipping_settings')->update([
            'flat_rate_enabled' => false,
            'flat_rate_amount' => 9.99
        ]);
    }

    public function down()
    {
        Schema::table('free_shipping_settings', function (Blueprint $table) {
            $table->dropColumn(['flat_rate_enabled', 'flat_rate_amount']);
        });
    }
};
