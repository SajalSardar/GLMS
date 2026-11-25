<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The national question bank — global, not tenant-scoped. No
     * school_id/district_id columns at all: every school's teachers
     * search and draw from the exact same pool, managed exclusively by
     * national_admin. chapter_number/chapter_name are plain denormalized
     * fields, NOT a FK into the existing per-school `chapters` table
     * (which is reached through Book -> ClassVersion -> school and would
     * force the same chapter to be re-entered once per school).
     */
    public function up(): void
    {
        Schema::create('academic_questions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->unsignedInteger('chapter_number');
            $table->string('chapter_name');

            // 'cq' | 'mcq' — validated at the application layer, same
            // convention as teacher_leave_applications.status.
            $table->string('question_type');
            $table->text('question_text');
            // MCQ only: four option texts as a JSON array. Null for CQ.
            $table->json('options')->nullable();
            $table->unsignedInteger('marks');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_class_id', 'subject_id', 'chapter_number'], 'academic_questions_class_subject_chapter_index');
            $table->index('question_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_questions');
    }
};
