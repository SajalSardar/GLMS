<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every table backed by its own Store*Request — an authenticated actor
     * always exists at creation/update time for these. See
     * docs/superpowers/specs/2026-11-11-audit-created-by-updated-by-design.md.
     *
     * On MySQL, adding a foreign-key column is a full table rebuild under
     * a metadata lock, not an online/INPLACE operation — plan downtime or
     * a maintenance window accordingly when running this in production.
     *
     * @var list<string>
     */
    private array $tables = [
        'divisions', 'districts', 'upazilas', 'schools', 'subjects',
        'school_classes', 'academic_years', 'classrooms', 'class_routines',
        'class_versions', 'shifts', 'sections', 'subject_teacher_assignments',
        'timetable_entries', 'books', 'chapters', 'chapter_materials',
        'chapter_videos', 'questions', 'exams', 'roles', 'users',
        'teacher_profiles', 'student_profiles', 'guardians',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'created_by')) {
                    $blueprint->foreignId('created_by')->nullable()->after('id')->constrained('users')->nullOnDelete();
                }

                if (! Schema::hasColumn($table, 'updated_by')) {
                    $blueprint->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'updated_by')) {
                    $blueprint->dropConstrainedForeignId('updated_by');
                }

                if (Schema::hasColumn($table, 'created_by')) {
                    $blueprint->dropConstrainedForeignId('created_by');
                }
            });
        }
    }
};
