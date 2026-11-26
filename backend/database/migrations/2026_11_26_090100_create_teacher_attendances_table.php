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

            $table->foreignId('corrected_by_id')->nullable()->constrained('users')->nullOnDelete();
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
