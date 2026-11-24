<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_conversion_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('crf')->default(23);
            $table->string('preset')->default('veryfast');
            $table->unsignedSmallInteger('max_height')->default(720);
            $table->unsignedInteger('video_bitrate')->default(2500);
            $table->unsignedSmallInteger('audio_bitrate')->default(128);
        });
        Schema::table('videos', function (Blueprint $table): void {
            $table->json('optimization_profile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('optimization_profile');
        });
        Schema::table('video_conversion_settings', function (Blueprint $table): void {
            $table->dropColumn(['crf', 'preset', 'max_height', 'video_bitrate', 'audio_bitrate']);
        });
    }
};
