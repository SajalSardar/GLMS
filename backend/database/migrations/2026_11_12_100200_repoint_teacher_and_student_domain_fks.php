<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * table => [column, target table, on-delete behavior]. These columns
     * mean "which teacher/student", not "who created this row" — see
     * docs/superpowers/specs/2026-11-11-split-user-tables-by-role-design.md
     * "Domain FKs beyond the profile tables".
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $retargets = [
        ['class_routines', 'teacher_id', 'teacher_users', 'nullOnDelete'],
        ['sections', 'class_teacher_id', 'teacher_users', 'nullOnDelete'],
        ['subject_teacher_assignments', 'teacher_id', 'teacher_users', 'cascadeOnDelete'],
        ['timetable_entries', 'teacher_id', 'teacher_users', 'restrictOnDelete'],
        ['exam_attempts', 'user_id', 'student_users', 'cascadeOnDelete'],
    ];

    /**
     * Run the migrations.
     *
     * MySQL DDL is not transactional, so each retarget below is
     * independently guarded against the real current DB state via
     * Schema::getForeignKeys() (not a downstream proxy signal) — a
     * crash between the dropForeign and foreign(...)->...() calls, or
     * between iterations of the foreach, leaves the migration
     * half-applied, and a retry must be able to resume exactly where
     * it left off instead of failing on an FK that's already gone.
     */
    public function up(): void
    {
        foreach ($this->retargets as [$table, $column, $target, $onDelete]) {
            $this->retargetForeignKey($table, $column, $target, $onDelete);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->retargets) as [$table, $column, , $onDelete]) {
            $this->retargetForeignKey($table, $column, 'users', $onDelete);
        }
    }

    /**
     * Repoint $table.$column's foreign key to $target, guarding the
     * drop and the add independently against the real current DB
     * state so either half can be safely retried after a crash.
     */
    private function retargetForeignKey(string $table, string $column, string $target, string $onDelete): void
    {
        if ($this->hasForeignKey($table, $column) && ! $this->hasForeignKeyReferencing($table, $column, $target)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        }

        if (! $this->hasForeignKeyReferencing($table, $column, $target)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $target, $onDelete) {
                $blueprint->foreign($column)->references('id')->on($target)->{$onDelete}();
            });
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn ($foreignKey) => $foreignKey['columns'] === [$column]
        );
    }

    private function hasForeignKeyReferencing(string $table, string $column, string $targetTable): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn ($foreignKey) => $foreignKey['columns'] === [$column] && $foreignKey['foreign_table'] === $targetTable
        );
    }
};
