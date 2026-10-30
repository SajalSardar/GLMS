<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A named time block (school-configurable, not hardcoded to
     * "Morning/Day/Night") under a class version — §2.4.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();
            // Denormalized from the version's school at creation time.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            // Array of day names (App\Models\ClassRoutine::DAYS_OF_WEEK),
            // e.g. ["sunday","monday","tuesday","wednesday","thursday"].
            $table->json('days_of_week');
            $table->unsignedSmallInteger('period_duration_minutes')->nullable();
            // Array of {start_time, end_time, label} break intervals.
            $table->json('breaks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_version_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
