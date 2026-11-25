<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher's saved, built question paper. school_id/district_id are
     * denormalized from the creating teacher at submission time (never
     * taken from the request), same convention as every other
     * TenantScoped table — TenantScope's existing actor-school_id scoping
     * already produces exactly the desired visibility (same-school
     * teachers and that school's admins see it; national_admin sees
     * everything) with no custom scope needed.
     */
    public function up(): void
    {
        Schema::create('question_papers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('teacher_id')->constrained('teacher_users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->unsignedInteger('time_duration_minutes')->nullable();
            $table->unsignedInteger('full_marks')->nullable();
            $table->text('instructions')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_papers');
    }
};
