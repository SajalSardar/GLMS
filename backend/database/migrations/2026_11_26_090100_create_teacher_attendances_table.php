<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per teacher per day, mirroring attendances' shape for
     * students. `status` is present|absent|leave — never 'holiday'; a
     * holiday date is computed at read time from the Holiday calendar,
     * exactly like Attendance already does, and is never written here.
     * check_in_at/check_out_at are the earliest/latest punch of the day
     * (see AttendancePunchController::store()); corrected_by_id/
     * correction_note are set only when a human overrides a day via
     * TeacherAttendanceController::correct() — distinguishing "the device
     * recorded this" from "a human overrode it."
     *
     * corrected_by_id is deliberately NOT constrained to the `users`
     * table: after the user-table split, an actor-id column FK'd to one
     * specific user table breaks for any actor whose id comes from a
     * different table — see
     * 2026_11_19_194634_drop_users_only_fk_from_upload_tracking_columns.php,
     * which removed this exact pattern from chapter_materials.uploaded_by_id
     * and books.pdf_uploaded_by_id. The actor correcting an attendance day
     * is always a staff User in practice (teacher_attendances.update is
     * only ever granted to national_admin/school_admin roles today), but
     * unlike Attendance.marked_by_id (which FKs to teacher_users
     * specifically), this column stays unconstrained rather than FK'd to
     * any one table, to stay defensive against a future role
     * reassignment — matching the precedent migration's own resolution.
     */
    public function up(): void
    {
        Schema::create('teacher_attendances', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('created_by');
            $table->nullableMorphs('updated_by');

            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->string('status');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();

            $table->unsignedBigInteger('corrected_by_id')->nullable();
            $table->text('correction_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['teacher_profile_id', 'date']);
            $table->index(['school_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_attendances');
    }
};
