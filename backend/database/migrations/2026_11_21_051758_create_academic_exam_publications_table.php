<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A row's mere existence means "published" — this table is never
     * updated, only created and (via SoftDeletes) soft-deleted; it never
     * touches academic_exam_marks. The publisher is captured via the
     * polymorphic created_by_id/created_by_type (always App\Models\User
     * — a school_admin/national_admin, never a teacher), so no extra
     * "published_by" column is needed.
     */
    public function up(): void
    {
        Schema::create('academic_exam_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['section_id', 'academic_exam_term_id', 'academic_year_id'], 'academic_exam_publications_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_exam_publications');
    }
};
