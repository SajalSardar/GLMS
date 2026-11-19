<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A practice exam is created by a student (or on a guest's behalf)
     * from an ad-hoc chapter selection, not by a teacher/admin through
     * the normal exam-management flow — it must never appear in any
     * exam listing. See docs/superpowers/specs/2026-11-19-practice-exam-redesign-design.md.
     */
    public function up(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->boolean('is_practice')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('exams', function (Blueprint $table) {
            $table->dropColumn('is_practice');
        });
    }
};
