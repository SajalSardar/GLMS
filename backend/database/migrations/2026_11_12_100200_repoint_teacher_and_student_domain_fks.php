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
     */
    public function up(): void
    {
        foreach ($this->retargets as [$table, $column, $target, $onDelete]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $target, $onDelete) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($target)->{$onDelete}();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->retargets) as [$table, $column, $target, $onDelete]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $onDelete) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on('users')->{$onDelete}();
            });
        }
    }
};
