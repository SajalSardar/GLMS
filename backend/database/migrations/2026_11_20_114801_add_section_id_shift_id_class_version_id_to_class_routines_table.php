<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only, all nullable — existing rows and callers keep working
     * exactly as before. Same reasoning as student_profiles'/
     * chapter_materials' own section_id/shift_id/class_version_id: once a
     * routine entry carries these, ClassRoutineController::mine() can
     * match it against a student's own profile scope without a join.
     * class_version_id/shift_id are denormalized from section_id (a
     * section belongs to exactly one shift, which belongs to exactly one
     * class_version) rather than trusted from the request — resolved in
     * ClassRoutineController, never set independently.
     */
    public function up(): void
    {
        Schema::table('class_routines', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('class_id')->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->after('section_id')->constrained()->nullOnDelete();
            $table->foreignId('class_version_id')->nullable()->after('shift_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_routines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_version_id');
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
