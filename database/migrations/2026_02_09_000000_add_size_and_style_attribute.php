<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('attributes')->where('code', 'size_and_style')->exists();
        
        if (!$exists) {
            DB::table('attributes')->insert([
                'code' => 'size_and_style',
                'admin_name' => 'Size and Style',
                'type' => 'text',
                'validation' => null,
                'position' => 26,
                'is_required' => 0,
                'is_unique' => 0,
                'value_per_locale' => 0,
                'value_per_channel' => 0,
                'is_filterable' => 0,
                'is_configurable' => 0,
                'is_visible_on_front' => 0,
                'is_user_defined' => 1,
                'swatch_type' => null,
                'is_comparable' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('attributes')->where('code', 'size_and_style')->delete();
    }
};
