<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('oem_discount_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->decimal('percentage', 5, 2)->default(15.00);
            $table->timestamps();
        });

        // Insert default settings
        DB::table('oem_discount_settings')->insert([
            'enabled' => true,
            'percentage' => 15.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        Schema::dropIfExists('oem_discount_settings');
    }
};
