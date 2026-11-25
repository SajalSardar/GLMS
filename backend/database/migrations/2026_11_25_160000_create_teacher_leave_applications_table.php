<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher's own leave request, reviewed by school_admin (never a
     * teacher — unlike student_leave_applications, there is exactly one
     * reviewer role per school). school_id/district_id are denormalized
     * from the applicant's own TeacherUser record at submission time
     * (never taken from the request), same convention as every other
     * TenantScoped table.
     *
     * delegate_teacher_id is nullable: a teacher with no homeroom section
     * has nothing to delegate. There is deliberately no section_id column
     * — "which sections does this affect" is never stored; it's computed
     * live as "every section where class_teacher_id = teacher_id" at
     * authorization-check time (see AttendancePolicy), so the delegation
     * stays correct even if homeroom assignments change later.
     */
    public function up(): void
    {
        Schema::create('teacher_leave_applications', function (Blueprint $table) {
            $table->id();

            // Polymorphic actor columns, matching the final shape every
            // other Auditable table has — no DB-level FK, since the actor
            // can be a User, TeacherUser, StudentUser, or GuardianUser.
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('teacher_id')->constrained('teacher_users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();

            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason');
            // 'pending' | 'approved' | 'rejected' — validated at the
            // application layer, same convention as student_leave_
            // applications.status.
            $table->string('status')->default('pending');

            $table->foreignId('delegate_teacher_id')->nullable()->constrained('teacher_users')->nullOnDelete();

            $table->text('admin_comment')->nullable();
            // The reviewer here is always a staff User (school_admin),
            // never a TeacherUser — unlike student_leave_applications,
            // whose reviewer is the class teacher.
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'status']);
            $table->index(['teacher_id', 'status']);
            // Covers the "currently on leave" predicate reused by both
            // AttendancePolicy's delegate check and the index(current=1)
            // filter: status='approved' AND from_date<=today<=to_date.
            $table->index(['status', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_leave_applications');
    }
};
