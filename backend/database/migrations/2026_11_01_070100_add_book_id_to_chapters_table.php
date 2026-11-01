<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Chapter moves from belonging directly to Subject to belonging to
     * Book (Subject -> Book -> Chapter), and becomes tenant-scoped via
     * the same denormalized school_id/district_id convention as Book.
     */
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropUnique(['subject_id', 'name']);
            $table->dropForeign(['subject_id']);
            $table->dropColumn('subject_id');

            $table->foreignId('book_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->after('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();

            $table->unique(['book_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropUnique(['book_id', 'name']);
            $table->dropForeign(['book_id']);
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['book_id', 'school_id', 'district_id']);

            $table->foreignId('subject_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['subject_id', 'name']);
        });
    }
};
