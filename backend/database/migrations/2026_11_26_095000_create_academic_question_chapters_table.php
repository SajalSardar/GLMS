<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The chapter list backing the national question bank's chapter
     * picker — global, scoped only by class + subject, deliberately NOT
     * the existing per-school `chapters` table (reached through
     * Book -> ClassVersion -> school): that table ties a chapter to one
     * specific school's data, which would break the question bank's
     * "same pool for every school" design. Managed exclusively by
     * national_admin, same tier as the question bank itself.
     */
    public function up(): void
    {
        Schema::create('academic_question_chapters', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->unsignedInteger('chapter_number');
            $table->string('chapter_name');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_class_id', 'subject_id'], 'academic_question_chapters_class_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_question_chapters');
    }
};
