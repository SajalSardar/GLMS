<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The academic holiday calendar — deliberately NOT TenantScoped
     * (no school_id/district_id): a national holiday and the standard
     * Friday/Saturday weekend are the same for every school on the
     * platform, same reasoning as school_classes/subjects being national
     * reference data rather than per-school. Only a national_admin may
     * create/update/delete (see HolidayPolicy); every other actor
     * (teacher, student, school_admin, ...) only ever reads it.
     *
     * Two kinds of row, distinguished by `type`:
     *  - 'weekly': a recurring day-of-week holiday (Friday, Saturday) —
     *    `day_of_week` is set, `start_date`/`end_date` stay null, and it
     *    applies indefinitely (every week, every year).
     *  - 'government' (or 'other'): a specific dated holiday — a single
     *    day sets start_date = end_date, a multi-day holiday (e.g. Eid)
     *    spans start_date..end_date; `day_of_week` stays null.
     *
     * This shape is what a future attendance feature queries against
     * (per this task's own stated purpose) — a single table covering
     * both "is this weekday always off" and "is this specific date off"
     * without two separate lookups.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            // Nullable: a weekly holiday applies every year, so it isn't
            // tied to one. A government/other holiday is normally scoped
            // to the academic year it falls in, but can be left null too
            // (e.g. a holiday whose year hasn't been set up yet).
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');
            $table->string('day_of_week')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'start_date', 'end_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
