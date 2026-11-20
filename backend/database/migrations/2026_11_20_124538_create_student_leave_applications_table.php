<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A student's own leave request, reviewed by the class teacher of
     * their section (or a staff actor with student_leave_applications.
     * update). school_id/district_id/class_id/section_id/shift_id/
     * class_version_id are denormalized from the applying student's own
     * profile at submission time (never taken directly from the request,
     * same convention as every other TenantScoped table) — this is what
     * lets ClassRoutine-style exact-match scoping find "leave
     * applications from students in my section" without joining through
     * student_profiles every time. All six are required: a student with
     * an incomplete profile (no section assigned yet) has no one scoped
     * to review them, so submission is rejected before a row is ever
     * written — see StoreStudentLeaveApplicationRequest.
     * academic_year_id is nullable like every other table that carries
     * it (student_profiles has no academic_year_id of its own; this is
     * denormalized from AcademicYear::where('is_current', true) instead).
     */
    public function up(): void
    {
        Schema::create('student_leave_applications', function (Blueprint $table) {
            $table->id();
            // Polymorphic actor columns, matching the final shape every
            // other Auditable table has after
            // 2026_11_12_100300_make_created_by_updated_by_polymorphic.php
            // (no DB-level FK, since the actor can be a User, TeacherUser,
            // StudentUser, or GuardianUser) — defined directly here since
            // this table didn't exist for that retrofit to reach.
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();

            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason');
            // 'pending' | 'approved' | 'rejected' — validated at the
            // application layer, same convention as class_routines'
            // day_of_week and timetable_entries' status.
            $table->string('status')->default('pending');
            $table->text('teacher_comment')->nullable();
            // Deliberately the teacher_users table, not users — class_
            // routines.teacher_id still points at the now-empty `users`
            // table from before the student/teacher/guardian split (see
            // 2026_11_12_100100_migrate_student_teacher_guardian_users.php),
            // a pre-existing gap this new table has no reason to repeat.
            $table->foreignId('reviewed_by_id')->nullable()->constrained('teacher_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['section_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_leave_applications');
    }
};
