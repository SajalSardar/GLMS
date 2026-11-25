<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds version_id (the national Bangla/English catalog — narrows which
     * chapter list a question's chapter_id can be picked from, same role it
     * plays on the Chapters management page) and replaces the plain
     * chapter_number/chapter_name fields with chapter_id, a real FK into
     * the existing `chapters` table (Subject -> Book -> Chapter), instead
     * of a second, parallel chapter list.
     */
    public function up(): void
    {
        Schema::table('academic_questions', function (Blueprint $table) {
            $table->foreignId('version_id')->after('subject_id')->constrained('versions')->cascadeOnDelete();
        });

        // school_class_id's foreign key is backed by the composite index
        // below being dropped (its leftmost column) — on MySQL, dropping it
        // without a replacement index already in place fails with "needed
        // in a foreign key constraint". The new index must exist before the
        // old one is dropped so MySQL always has a valid supporting index
        // for that FK.
        Schema::table('academic_questions', function (Blueprint $table) {
            $table->index(['school_class_id', 'subject_id'], 'academic_questions_class_subject_index');
        });

        Schema::table('academic_questions', function (Blueprint $table) {
            $table->dropIndex('academic_questions_class_subject_chapter_index');
            $table->dropColumn(['chapter_number', 'chapter_name']);

            $table->foreignId('chapter_id')
                ->after('version_id')
                ->constrained('chapters')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Same ordering constraint as up(): the composite index must exist
        // (which needs chapter_number re-added first) before the newer
        // index is dropped, so school_class_id's foreign key always has a
        // valid supporting index.
        Schema::table('academic_questions', function (Blueprint $table) {
            $table->unsignedInteger('chapter_number')->after('subject_id');
            $table->string('chapter_name')->after('chapter_number');
        });

        Schema::table('academic_questions', function (Blueprint $table) {
            $table->index(['school_class_id', 'subject_id', 'chapter_number'], 'academic_questions_class_subject_chapter_index');
        });

        Schema::table('academic_questions', function (Blueprint $table) {
            $table->dropIndex('academic_questions_class_subject_index');
            $table->dropConstrainedForeignId('chapter_id');
            $table->dropConstrainedForeignId('version_id');
        });
    }
};
