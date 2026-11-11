<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same 25-table list as the audit-columns project's original
     * migration — see
     * docs/superpowers/plans/2026-11-11-audit-created-by-updated-by.md.
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
     *
     * created_by/updated_by were a flat FK to users.id — now that the
     * actor can be a User, TeacherUser, StudentUser, or GuardianUser, a
     * single FK target isn't possible, so these become polymorphic
     * {column}_type/{column}_id pairs with no DB-level FK. Existing
     * non-null values are backfilled with App\Models\User, since every
     * row created so far was created by a staff actor (the split only
     * just happened).
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'created_by_type')) {
                    $blueprint->dropForeign([$table === 'users' ? 'users_created_by_foreign' : "{$table}_created_by_foreign"]);
                    $blueprint->string('created_by_type')->nullable()->after('created_by');
                    $blueprint->renameColumn('created_by', 'created_by_id');
                }

                if (! Schema::hasColumn($table, 'updated_by_type')) {
                    $blueprint->dropForeign([$table === 'users' ? 'users_updated_by_foreign' : "{$table}_updated_by_foreign"]);
                    $blueprint->string('updated_by_type')->nullable()->after('updated_by');
                    $blueprint->renameColumn('updated_by', 'updated_by_id');
                }
            });

            DB::table($table)->whereNotNull('created_by_id')->update(['created_by_type' => User::class]);
            DB::table($table)->whereNotNull('updated_by_id')->update(['updated_by_type' => User::class]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'created_by_type')) {
                    $blueprint->renameColumn('created_by_id', 'created_by');
                    $blueprint->dropColumn('created_by_type');
                    $blueprint->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                }

                if (Schema::hasColumn($table, 'updated_by_type')) {
                    $blueprint->renameColumn('updated_by_id', 'updated_by');
                    $blueprint->dropColumn('updated_by_type');
                    $blueprint->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
                }
            });
        }
    }
};
