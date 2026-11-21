<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A plain, append-only audit log — no Auditable/SoftDeletes/
     * TenantScoped, matching student_section_transfers' own minimal
     * shape exactly. Null to_* columns mean "retained" — a row is
     * written for every student in a promotion batch, promoted or not,
     * so the log shows the full decision, not just the moves.
     */
    public function up(): void
    {
        Schema::create('student_class_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('from_section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('to_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('to_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('to_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('to_class_version_id')->nullable()->constrained('class_versions')->nullOnDelete();

            $table->string('result');
            $table->decimal('gpa', 3, 2)->nullable();
            $table->foreignId('promoted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('promoted_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_class_promotions');
    }
};
