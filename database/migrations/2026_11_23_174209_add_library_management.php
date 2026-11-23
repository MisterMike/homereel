<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_contributor')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('invitation_token', 64)->nullable();
            $table->timestamp('invitation_expires_at')->nullable();
        });
        Schema::create('albums', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
        Schema::table('videos', function (Blueprint $table): void {
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('album_id');
        });
        Schema::dropIfExists('albums');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['is_contributor', 'is_active', 'invitation_token', 'invitation_expires_at']);
        });
    }
};
