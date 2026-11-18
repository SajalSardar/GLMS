<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same reasoning as books (2026_11_18_100100): chapters.school_id/
     * district_id were denormalized purely for tenant-scope filtering,
     * which is removed.
     */
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['school_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->foreignId('school_id')->after('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
