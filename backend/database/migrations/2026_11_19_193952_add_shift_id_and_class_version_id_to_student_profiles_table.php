<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Denormalized from section_id (section -> shift -> class_version) so
     * ChapterMaterialController can scope a material to the uploading
     * teacher's own class without joining through section every time.
     * Kept in sync only by StudentProfileController::transferSection() —
     * section_id is the only place a student's section ever changes.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('section_id')->constrained()->nullOnDelete();
            $table->foreignId('class_version_id')->nullable()->after('shift_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('class_version_id');
            $table->dropConstrainedForeignId('shift_id');
        });
    }
};
