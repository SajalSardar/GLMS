<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Video-progress tracking is removed outright — no replacement. No
     * production data exists (dev/test only), so this is a clean table
     * drop.
     */
    public function up(): void
    {
        Schema::dropIfExists('chapter_video_progress');
    }

    /**
     * Reverse the migrations. Recreates the table in its final shape
     * (post 2026_11_12_100400_make_content_log_and_video_progress_polymorphic),
     * not its original shape.
     */
    public function down(): void
    {
        Schema::create('chapter_video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_video_id')->constrained()->cascadeOnDelete();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('not_started');
            $table->unsignedInteger('max_position_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['chapter_video_id', 'user_type', 'user_id']);
        });
    }
};
