<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * All nullable, left NULL for every existing row on purpose — there is
     * no reliable way to backfill which section a material already
     * uploaded today was meant for, so an existing material keeps being
     * visible to the whole chapter (today's behavior). Only a NEWLY
     * uploaded material, created by a teacher who is the class_teacher of
     * a section matching the chapter's own class_version, gets these set
     * (see ChapterMaterialController::store()) — a student outside that
     * exact section then can't see or download it
     * (ChapterMaterialController::index()/ChapterMaterialPolicy::view()).
     */
    public function up(): void
    {
        Schema::table('chapter_materials', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('chapter_id')->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->after('school_id')->constrained()->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->after('district_id')->constrained()->nullOnDelete();
            $table->foreignId('class_version_id')->nullable()->after('shift_id')->constrained()->nullOnDelete();
            $table->foreignId('class_id')->nullable()->after('class_version_id')->constrained('school_classes')->nullOnDelete();
            $table->foreignId('section_id')->nullable()->after('class_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapter_materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
            $table->dropConstrainedForeignId('class_id');
            $table->dropConstrainedForeignId('class_version_id');
            $table->dropConstrainedForeignId('shift_id');
            $table->dropConstrainedForeignId('district_id');
            $table->dropConstrainedForeignId('school_id');
        });
    }
};
