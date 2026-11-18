<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The same gap as 2026_11_19_100300_scope_class_versions_unique_name_to_school,
     * found during review of this same task: class_routines' unique index
     * was only ever (class_id, day_of_week, period_no) — a leftover from
     * when one SchoolClass belonged to exactly one school, so class_id
     * alone implied a single school. Once ClassSeeder shares a class
     * across every school in a grade band, two different schools both
     * scheduling the same class's Sunday/period-1 slot collide on that
     * index (and, below the DB layer, StoreClassRoutineRequest's own
     * uniqueness rule has the identical narrower scope — fixed alongside
     * this migration). Widening the index to
     * (class_id, school_id, day_of_week, period_no) is the fix: each
     * school keeps its own independent schedule under a shared class.
     */
    public function up(): void
    {
        Schema::table('class_routines', function (Blueprint $table) {
            $table->dropUnique(['class_id', 'day_of_week', 'period_no']);
            $table->unique(['class_id', 'school_id', 'day_of_week', 'period_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_routines', function (Blueprint $table) {
            $table->dropUnique(['class_id', 'school_id', 'day_of_week', 'period_no']);
            $table->unique(['class_id', 'day_of_week', 'period_no']);
        });
    }
};
