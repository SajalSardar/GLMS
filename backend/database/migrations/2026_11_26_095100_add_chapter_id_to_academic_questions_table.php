<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the plain chapter_number/chapter_name fields with a real FK
     * into the new academic_question_chapters table — the chapter picker
     * now selects from a managed list instead of free-typed text.
     */
    public function up(): void
    {
        Schema::table('academic_questions', function (Blueprint $table) {
            $table->dropIndex('academic_questions_class_subject_chapter_index');
            $table->dropColumn(['chapter_number', 'chapter_name']);

            $table->foreignId('chapter_id')
                ->after('subject_id')
                ->constrained('academic_question_chapters')
                ->cascadeOnDelete();
        });

        Schema::table('academic_questions', function (Blueprint $table) {
            $table->index(['school_class_id', 'subject_id'], 'academic_questions_class_subject_index');
        });
    }

    public function down(): void
    {
        Schema::table('academic_questions', function (Blueprint $table) {
            $table->dropIndex('academic_questions_class_subject_index');
            $table->dropConstrainedForeignId('chapter_id');

            $table->unsignedInteger('chapter_number')->after('subject_id');
            $table->string('chapter_name')->after('chapter_number');
        });

        Schema::table('academic_questions', function (Blueprint $table) {
            $table->index(['school_class_id', 'subject_id', 'chapter_number'], 'academic_questions_class_subject_chapter_index');
        });
    }
};
