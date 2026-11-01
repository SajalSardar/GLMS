<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chapter_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            // Denormalized from the chapter at creation time — chapter_videos
            // is resolved by direct route-model-binding, so it needs its own
            // TenantScope filter, same as Book/Chapter/ChapterMaterial.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_video_id', 11);
            $table->string('title');
            $table->string('bn_title')->nullable();
            $table->text('description')->nullable();
            $table->string('instructor_name')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('learning_objectives')->nullable();
            $table->unsignedTinyInteger('completion_threshold_percent')->nullable();
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_videos');
    }
};
