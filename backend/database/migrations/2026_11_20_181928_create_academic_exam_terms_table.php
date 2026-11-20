<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * National reference data — the exam-term calendar (First Terminal,
     * Second Terminal, Annual/Final, ...) is the same for every school in
     * the country, same tier as academic_years/holidays. `is_annual`
     * marks exactly one row as the term whose result decides promotion
     * (enforced at the application layer, not here — see
     * StoreAcademicExamTermRequest/UpdateAcademicExamTermRequest).
     */
    public function up(): void
    {
        Schema::create('academic_exam_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_annual')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_exam_terms');
    }
};
