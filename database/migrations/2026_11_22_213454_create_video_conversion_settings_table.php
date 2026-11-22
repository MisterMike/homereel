<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_conversion_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->boolean('automatic')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_conversion_settings');
    }
};
