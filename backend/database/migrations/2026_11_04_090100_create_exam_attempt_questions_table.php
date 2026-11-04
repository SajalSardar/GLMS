<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A snapshot taken at attempt-start, for every selection mode — not
     * just the random ones — so grading/review always has one uniform,
     * stable reference regardless of later edits to the live exam or
     * question bank. No tenant columns: always reached through its
     * parent ExamAttempt, never queried standalone.
     */
    public function up(): void
    {
        Schema::create('exam_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('order_index')->default(0);
            $table->unsignedSmallInteger('marks');
            $table->timestamps();

            $table->unique(['exam_attempt_id', 'question_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exam_attempt_questions');
    }
};
