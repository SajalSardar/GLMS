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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            // Denormalized from the chapter at creation time (never taken
            // directly from the request) — questions is resolved by direct
            // route-model-binding, so it needs its own TenantScope filter,
            // same as Book/Chapter/ChapterVideo/ChapterMaterial.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('question_type');
            $table->text('question_text');
            $table->text('bn_question_text')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_disk')->nullable();
            $table->unsignedSmallInteger('marks')->default(1);
            $table->string('difficulty')->default('medium');
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
