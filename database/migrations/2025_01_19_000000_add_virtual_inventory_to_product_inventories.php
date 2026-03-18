<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_inventories', function (Blueprint $table) {
            $table->boolean('virtual_inventory')->default(false)->after('qty');
            $table->integer('virtual_qty_base')->default(0)->after('virtual_inventory');
            $table->timestamp('last_replenished_at')->nullable()->after('virtual_qty_base');
        });
    }

    public function down(): void
    {
        Schema::table('product_inventories', function (Blueprint $table) {
            $table->dropColumn(['virtual_inventory', 'virtual_qty_base', 'last_replenished_at']);
        });
    }
};
