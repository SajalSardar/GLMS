<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Per-school, transactional — the one TenantScoped table in this
     * feature. academic_year_id is NOT nullable: it is one quarter of
     * this table's own unique index, and a nullable FK inside a unique
     * constraint would let MySQL/SQLite insert unlimited "duplicate" rows
     * that all happen to have a null year (NULLs never compare equal in
     * a unique index) — silently defeating the one-mark-per-student-per-
     * subject-per-term-per-year guarantee this table exists to enforce.
     */
    public function up(): void
    {
        Schema::create('academic_exam_marks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

            // Denormalized from the student's own profile at entry time —
            // same convention as attendances/student_leave_applications,
            // and what lets a roster query ("every mark for section X,
            // subject Y, term Z") avoid joining through student_profiles.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('cq_marks_obtained')->nullable();
            $table->unsignedSmallInteger('mcq_marks_obtained')->nullable();
            $table->unsignedSmallInteger('practical_marks_obtained')->nullable();

            // Deliberately teacher_users, not users — same reasoning as
            // student_leave_applications.reviewed_by_id.
            $table->foreignId('entered_by_id')->nullable()->constrained('teacher_users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_profile_id', 'subject_id', 'academic_exam_term_id', 'academic_year_id'], 'academic_exam_marks_unique');
            $table->index(['section_id', 'subject_id', 'academic_exam_term_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_exam_marks');
    }
};
