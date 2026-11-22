<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
        });
        Schema::create('videos', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('original_filename');
            $t->string('mime_type');
            $t->unsignedBigInteger('file_size');
            $t->double('duration_seconds')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('video_codec')->nullable();
            $t->string('audio_codec')->nullable();
            $t->string('container_format')->nullable();
            $t->string('status')->index();
            $t->string('storage_provider')->default('local');
            $t->string('storage_path');
            $t->string('provider_video_id')->nullable();
            $t->string('thumbnail_path')->nullable();
            $t->boolean('prototype_available')->default(false)->index();
            $t->boolean('browser_compatible')->default(false);
            $t->text('processing_error')->nullable();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('upload_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('original_filename');
            $t->string('title');
            $t->string('mime_type');
            $t->unsignedBigInteger('expected_size');
            $t->unsignedBigInteger('offset')->default(0);
            $t->unsignedInteger('chunk_bytes');
            $t->string('temporary_path');
            $t->string('status')->index();
            $t->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('expires_at')->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
        Schema::dropIfExists('videos');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
