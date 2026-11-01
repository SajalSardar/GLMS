<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * max_position_seconds is a high-water mark (the furthest playback
     * position ever reported), not a sum of watched segments — see
     * this plan's Global Constraints for why.
     */
    public function up(): void
    {
        Schema::create('chapter_video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('not_started');
            $table->unsignedInteger('max_position_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['chapter_video_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_video_progress');
    }
};
