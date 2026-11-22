<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('optimization_status')->default('not_requested');
            $table->uuid('optimization_token')->nullable();
            $table->unsignedBigInteger('optimization_reserved_bytes')->default(0);
            $table->text('optimization_error')->nullable();
            $table->string('optimized_path')->nullable();
            $table->unsignedBigInteger('optimized_file_size')->nullable();
            $table->unsignedInteger('optimized_width')->nullable();
            $table->unsignedInteger('optimized_height')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['optimization_status', 'optimization_token', 'optimization_reserved_bytes', 'optimization_error', 'optimized_path', 'optimized_file_size', 'optimized_width', 'optimized_height']);
        });
    }
};
