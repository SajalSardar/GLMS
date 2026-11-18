<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same reasoning as books/chapters/chapter_materials:
     * chapter_videos.school_id/district_id were denormalized purely for
     * tenant-scope filtering, which is removed.
     */
    public function up(): void
    {
        Schema::table('chapter_videos', function (Blueprint $table) {
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
        Schema::table('chapter_videos', function (Blueprint $table) {
            $table->foreignId('school_id')->after('chapter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
