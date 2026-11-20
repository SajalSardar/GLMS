<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per student per calendar day — homeroom-style daily
     * attendance, not per-subject/period. school_id/district_id/class_id/
     * section_id/shift_id/class_version_id are denormalized from the
     * section being recorded for (never taken directly from the request),
     * same convention as student_leave_applications. Dates covered by an
     * active Holiday are never recorded for at all (see
     * AttendanceController::record()) — the report reads the holiday's
     * name for those dates directly from the Holiday calendar instead of
     * from a row here.
     */
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            // Polymorphic actor columns, same shape as every other
            // Auditable table added after the student/teacher/guardian
            // split (see student_leave_applications' own migration for
            // the full reasoning) — no DB-level FK since the actor can be
            // a User, TeacherUser, StudentUser, or GuardianUser.
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

            $table->date('date');
            // 'present' | 'absent' | 'leave' — validated at the
            // application layer, same convention as student_leave_
            // applications' own status column.
            $table->string('status');
            // Deliberately teacher_users, not users — same reasoning as
            // student_leave_applications.reviewed_by_id (see that
            // migration's own comment on the pre-existing users-table gap
            // this has no reason to repeat). Null when a non-teacher staff
            // actor records on the class teacher's behalf.
            $table->foreignId('marked_by_id')->nullable()->constrained('teacher_users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_profile_id', 'date']);
            $table->index(['section_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
