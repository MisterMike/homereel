<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('web_status')->default('not_requested');
            $table->uuid('web_token')->nullable();
            $table->unsignedBigInteger('web_reserved_bytes')->default(0);
            $table->json('web_profile')->nullable();
            $table->text('web_error')->nullable();
            $table->string('web_path')->nullable();
            $table->unsignedBigInteger('web_file_size')->nullable();
            $table->unsignedInteger('web_width')->nullable();
            $table->unsignedInteger('web_height')->nullable();
            $table->timestamp('ready_notified_at')->nullable();
            $table->unsignedBigInteger('source_bitrate')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['web_status', 'web_token', 'web_reserved_bytes', 'web_profile', 'web_error', 'web_path', 'web_file_size', 'web_width', 'web_height', 'ready_notified_at', 'source_bitrate']);
        });
    }
};
