<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('free_shipping_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('threshold', 10, 2)->default(75.00);
            $table->string('header_text')->default('FREE SHIPPING on orders $75 and up!');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        DB::table('free_shipping_settings')->insert([
            'threshold' => 75.00,
            'header_text' => 'FREE SHIPPING on orders $75 and up!',
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('free_shipping_settings');
    }
};
