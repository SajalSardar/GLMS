<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable raw device log — one row per punch, never summarized away,
     * so there is always a full audit trail of every scan. No SoftDeletes/
     * Auditable/TenantScoped: this is a device log, not an admin-editable
     * resource, and school_id is reachable via teacher_profile_id when
     * needed rather than denormalized here.
     */
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kiosk_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->timestamp('punched_at');

            $table->timestamps();
            $table->index(['teacher_profile_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
