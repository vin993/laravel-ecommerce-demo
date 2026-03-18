<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('product_search_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_id');
            $table->string('tag_type', 50)->comment('vehicle_type, vehicle_brand, vehicle_model, part_category, part_subcategory, feature, application');
            $table->string('tag_value', 255);
            $table->unsignedTinyInteger('weight')->default(50)->comment('1-100 relevance weight');
            $table->string('source', 50)->nullable()->comment('description, category, fitment, brand, manual');
            $table->timestamp('created_at')->nullable();

            $table->index('product_id', 'idx_product_id');
            $table->index('tag_type', 'idx_tag_type');
            $table->index('tag_value', 'idx_tag_value');
            $table->index(['tag_type', 'tag_value'], 'idx_composite');
            $table->index(['product_id', 'tag_type'], 'idx_product_composite');
            $table->index(['tag_value', 'weight'], 'idx_value_weight');

            $table->foreign('product_id')
                  ->references('id')
                  ->on('products')
                  ->onDelete('cascade');
        });

        DB::statement('ALTER TABLE product_search_tags ADD INDEX idx_tag_value_fulltext (tag_value) USING BTREE');
    }

    public function down()
    {
        Schema::dropIfExists('product_search_tags');
    }
};
