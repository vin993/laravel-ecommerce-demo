<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_cache', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 100);
            $table->string('supplier', 50);
            $table->boolean('is_available')->default(false);
            $table->decimal('price', 10, 2)->nullable();
            $table->integer('inventory')->nullable();
            $table->string('dropshipper_item_id', 100)->nullable();
            $table->timestamp('cached_at')->useCurrent();
            $table->timestamp('expires_at');

            $table->unique(['sku', 'supplier']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_cache');
    }
};
