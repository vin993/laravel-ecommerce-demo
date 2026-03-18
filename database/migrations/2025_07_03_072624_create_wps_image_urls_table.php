<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('wps_image_urls', function (Blueprint $table) {
            $table->id();
            $table->string('path')->index();
            $table->text('source_url');
            $table->string('filename');
            $table->timestamp('created_at');
            
            $table->unique('path');
        });
    }

    public function down()
    {
        Schema::dropIfExists('wps_image_urls');
    }
};