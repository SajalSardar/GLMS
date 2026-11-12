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
     *
     * MySQL DDL is not transactional, and each Blueprint command added
     * in a Schema::table() closure compiles to its own sequential
     * ALTER TABLE statement. So every step below is independently
     * guarded against actual current DB state (not just a downstream
     * signal) — a crash between any two steps and a retry must be able
     * to resume exactly where it left off.
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            $this->makeColumnPolymorphic($table, 'created_by');
            $this->makeColumnPolymorphic($table, 'updated_by');

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
            $this->revertColumnPolymorphic($table, 'created_by');
            $this->revertColumnPolymorphic($table, 'updated_by');
        }
    }

    /**
     * Turn {column} into {column}_type/{column}_id on $table, in three
     * independently-guarded sub-steps so a crash between any two of
     * them leaves the table in a state a retry can detect and resume
     * from.
     */
    private function makeColumnPolymorphic(string $table, string $column): void
    {
        $typeColumn = "{$column}_type";
        $idColumn = "{$column}_id";

        // Step 1: add the type column.
        Schema::table($table, function (Blueprint $blueprint) use ($table, $column, $typeColumn) {
            if (! Schema::hasColumn($table, $typeColumn)) {
                $blueprint->string($typeColumn)->nullable()->after($column);
            }
        });

        // Step 2: drop the FK, if it still exists.
        $hasForeign = collect(Schema::getForeignKeys($table))->contains(
            fn ($fk) => $fk['columns'] === [$column]
        );
        if ($hasForeign) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        }

        // Step 3: rename the column, if not already renamed.
        if (Schema::hasColumn($table, $column) && ! Schema::hasColumn($table, $idColumn)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $idColumn) {
                $blueprint->renameColumn($column, $idColumn);
            });
        }
    }

    /**
     * Reverse makeColumnPolymorphic(): rename {column}_id back to
     * {column}, re-add its FK, and drop {column}_type — each step
     * independently guarded. Renaming back happens first since the FK
     * must reference the final {column} name, not {column}_id.
     */
    private function revertColumnPolymorphic(string $table, string $column): void
    {
        $typeColumn = "{$column}_type";
        $idColumn = "{$column}_id";

        // Step 1: rename the column back, if not already done.
        if (Schema::hasColumn($table, $idColumn) && ! Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($idColumn, $column) {
                $blueprint->renameColumn($idColumn, $column);
            });
        }

        // Step 2: re-add the FK, if it doesn't already exist.
        $hasForeign = collect(Schema::getForeignKeys($table))->contains(
            fn ($fk) => $fk['columns'] === [$column]
        );
        if (! $hasForeign && Schema::hasColumn($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->foreign($column)->references('id')->on('users')->nullOnDelete();
            });
        }

        // Step 3: drop the now-unused type column, if it still exists.
        if (Schema::hasColumn($table, $typeColumn)) {
            Schema::table($table, function (Blueprint $blueprint) use ($typeColumn) {
                $blueprint->dropColumn($typeColumn);
            });
        }
    }
};
