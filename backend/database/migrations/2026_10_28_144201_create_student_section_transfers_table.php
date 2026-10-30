<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * History trail for section transfers — §2.5 ("transfer students
     * between sections with proper history").
     */
    public function up(): void
    {
        Schema::create('student_section_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('to_section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('transferred_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_section_transfers');
    }
};
