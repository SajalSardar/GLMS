<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            // Denormalized from the class version at creation time (never
            // taken directly from the request) — exams is resolved by
            // direct route-model-binding, so it needs its own TenantScope
            // filter, same as Book/Chapter/Question.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->string('selection_mode');
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->unsignedTinyInteger('max_attempts')->nullable();
            $table->unsignedTinyInteger('passing_score_percent')->nullable();
            $table->dateTime('available_from')->nullable();
            $table->dateTime('available_until')->nullable();
            $table->string('status')->default('draft');
            $table->unsignedSmallInteger('random_question_count')->nullable();
            $table->string('random_difficulty')->nullable();
            $table->unsignedSmallInteger('marks_per_question')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['class_version_id', 'subject_id', 'academic_year_id', 'name'],
                'exams_version_subject_year_name_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exams');
    }
};
