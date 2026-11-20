<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * National reference data — one grade scale applies to every school
     * in the country (same tier as academic_years/holidays/
     * academic_exam_terms). Each row is a percentage range mapped to a
     * grade name and grade point — e.g. 80.00-100.00 -> A+ -> 5.00.
     * Ranges must not overlap among active rows (enforced in
     * StoreAcademicGradeConfigureRequest/UpdateAcademicGradeConfigureRequest,
     * not here) — AcademicGradeConfigure::forPercent() assumes exactly one
     * active row matches any given percentage.
     */
    public function up(): void
    {
        Schema::create('academic_grade_configures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->decimal('min_percent', 5, 2);
            $table->decimal('max_percent', 5, 2);
            $table->string('grade_name');
            $table->string('bn_grade_name')->nullable();
            $table->decimal('grade_point', 3, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['min_percent', 'max_percent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_grade_configures');
    }
};
