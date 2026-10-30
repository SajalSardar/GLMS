<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A single period of a section's routine — §5. Distinct from the
     * older, simpler `class_routines` table (kept as-is for existing
     * callers): this one is scoped to a Section instead of a plain
     * SchoolClass and carries a draft/published workflow (§5.4).
     */
    public function up(): void
    {
        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            // Denormalized from the section's shift at creation time.
            $table->foreignId('academic_year_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('day_of_week');
            $table->unsignedTinyInteger('period_no');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            // 'draft' | 'published' — validated at the application layer,
            // same convention as day_of_week/role names elsewhere.
            $table->string('status')->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['section_id', 'day_of_week', 'period_no']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
    }
};
