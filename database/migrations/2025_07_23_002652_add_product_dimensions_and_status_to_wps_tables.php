<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('wps_product_items', function (Blueprint $table) {
            if (!Schema::hasColumn('wps_product_items', 'weight')) {
                $table->decimal('weight', 8, 2)->nullable()->after('inventory_total');
            }
            if (!Schema::hasColumn('wps_product_items', 'length')) {
                $table->decimal('length', 8, 2)->nullable()->after('weight');
            }
            if (!Schema::hasColumn('wps_product_items', 'width')) {
                $table->decimal('width', 8, 2)->nullable()->after('length');
            }
            if (!Schema::hasColumn('wps_product_items', 'height')) {
                $table->decimal('height', 8, 2)->nullable()->after('width');
            }
            if (!Schema::hasColumn('wps_product_items', 'is_new')) {
                $table->boolean('is_new')->default(false)->after('height');
            }
            if (!Schema::hasColumn('wps_product_items', 'is_featured')) {
                $table->boolean('is_featured')->default(false)->after('is_new');
            }
            if (!Schema::hasColumn('wps_product_items', 'is_available')) {
                $table->boolean('is_available')->default(true)->after('is_featured');
            }
            if (!Schema::hasColumn('wps_product_items', 'cost')) {
                $table->decimal('cost', 10, 2)->nullable()->after('website_price');
            }
            if (!Schema::hasColumn('wps_product_items', 'special_price')) {
                $table->decimal('special_price', 10, 2)->nullable()->after('cost');
            }
            if (!Schema::hasColumn('wps_product_items', 'special_price_from')) {
                $table->date('special_price_from')->nullable()->after('special_price');
            }
            if (!Schema::hasColumn('wps_product_items', 'special_price_to')) {
                $table->date('special_price_to')->nullable()->after('special_price_from');
            }
        });
    }

    public function down()
    {
        Schema::table('wps_product_items', function (Blueprint $table) {
            $table->dropColumn([
                'weight', 'length', 'width', 'height',
                'is_new', 'is_featured', 'is_available',
                'cost', 'special_price', 'special_price_from', 'special_price_to'
            ]);
        });
    }
};