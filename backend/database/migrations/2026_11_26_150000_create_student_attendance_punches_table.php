<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable raw device log for student self-check-in punches — mirrors
     * attendance_punches' shape exactly, kept as its own table rather than
     * a shared polymorphic one, matching this codebase's established
     * convention of separate teacher/student domain tables (e.g.
     * teacher_leave_applications vs student_leave_applications). No
     * SoftDeletes/Auditable/TenantScoped: a device log, not an
     * admin-editable resource.
     */
    public function up(): void
    {
        Schema::create('student_attendance_punches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kiosk_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->timestamp('punched_at');

            $table->timestamps();
            $table->index(['student_profile_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_attendance_punches');
    }
};
