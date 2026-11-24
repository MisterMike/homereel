<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->unsignedBigInteger('view_count')->default(0);
        });

        Schema::create('video_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64);
            $table->unique(['video_id', 'session_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_views');

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('view_count');
        });
    }
};
