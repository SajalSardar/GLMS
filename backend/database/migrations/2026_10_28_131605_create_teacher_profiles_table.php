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
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Denormalized from user.school_id/district_id at creation time,
            // for scope filtering without joining through users.
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employee_id')->nullable()->unique();
            $table->string('designation')->nullable();
            $table->string('subject_specialization')->nullable();
            $table->string('qualification')->nullable();
            $table->date('joining_date')->nullable();
            $table->string('nid_number')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('present_address')->nullable();
            $table->string('permanent_address')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teacher_profiles');
    }
};
