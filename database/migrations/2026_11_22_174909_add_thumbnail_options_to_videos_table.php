<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->double('thumbnail_offset_seconds')->nullable();
            $table->text('thumbnail_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', fn (Blueprint $table) => $table->dropColumn(['thumbnail_offset_seconds', 'thumbnail_error']));
    }
};
