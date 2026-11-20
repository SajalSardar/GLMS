<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * National reference data — one row per (class, subject) pair, used
     * by every school in the country, same tier as academic_exam_terms/
     * academic_grade_configures. Each of the three full-marks columns is
     * independently nullable: a subject with no practical component
     * simply leaves practical_full_marks null (never zero), so it's
     * never counted toward a student's total or pass/fail check for that
     * subject — see AcademicExamMark (added in a later phase of this
     * feature) for where that rule is actually applied.
     */
    public function up(): void
    {
        Schema::create('academic_subject_mark_configures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cq_full_marks')->nullable();
            $table->unsignedSmallInteger('mcq_full_marks')->nullable();
            $table->unsignedSmallInteger('practical_full_marks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_subject_mark_configures');
    }
};
