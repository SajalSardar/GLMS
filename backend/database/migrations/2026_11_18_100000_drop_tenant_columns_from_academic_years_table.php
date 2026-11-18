<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * academic_years becomes national calendar data — the same academic
     * year applies to every school in Bangladesh — so the per-school
     * tenant columns and the (school_id, name) uniqueness are no longer
     * meaningful. No production data exists yet (dev/test only), so this
     * is a clean column drop, not a data migration.
     */
    public function up(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropUnique(['school_id', 'name']);
            $table->dropColumn(['school_id', 'district_id']);

            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique(['name']);

            $table->foreignId('school_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();

            $table->unique(['school_id', 'name']);
        });
    }
};
