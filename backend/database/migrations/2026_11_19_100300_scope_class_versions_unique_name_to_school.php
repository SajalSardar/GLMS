<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A ripple from the school_classes restructure that neither the
     * plan nor its task briefs called out: school_classes.name is now
     * national, so the same class_id can be adopted by many schools via
     * class_schools (Task 1). class_versions.school_id was always its
     * own column (untouched by that restructure), but the table's
     * unique index was only ever (class_id, name) — a leftover from when
     * one SchoolClass belonged to exactly one school, so class_id alone
     * already implied a single school. Two schools both creating a
     * "General" version for the same shared class_id now collide on
     * that index (confirmed via SectionBackfillSeeder, which does
     * exactly this for every class_schools row once ClassSeeder assigns
     * one class to several schools). Widening the index to
     * (class_id, school_id, name) is the fix: each school keeps its own
     * independent version-name namespace under a shared class.
     */
    public function up(): void
    {
        Schema::table('class_versions', function (Blueprint $table) {
            // Same MySQL/InnoDB index-vs-foreign-key requirement as
            // 2026_11_19_100100_add_school_id_to_class_subjects_table —
            // see that migration's comment.
            $table->index('class_id', 'class_versions_class_id_index');
            $table->dropUnique(['class_id', 'name']);
            $table->unique(['class_id', 'school_id', 'name']);
            $table->dropIndex('class_versions_class_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_versions', function (Blueprint $table) {
            $table->index('class_id', 'class_versions_class_id_index');
            $table->dropUnique(['class_id', 'school_id', 'name']);
            $table->unique(['class_id', 'name']);
            $table->dropIndex('class_versions_class_id_index');
        });
    }
};
