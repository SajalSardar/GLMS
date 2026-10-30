<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: `class_id` (pointing at school_classes) is left
     * untouched so the existing StudentProfile API keeps working exactly
     * as before. `section_id` is a new, optional pointer into the new
     * Section structure for schools that adopt it.
     */
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('class_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
