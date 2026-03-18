<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ds_manufacturer_index', function (Blueprint $table) {
            $table->string('logo_path', 500)->nullable()->after('manufacturer_name');
            $table->string('logo_source', 50)->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('ds_manufacturer_index', function (Blueprint $table) {
            $table->dropColumn(['logo_path', 'logo_source']);
        });
    }
};
