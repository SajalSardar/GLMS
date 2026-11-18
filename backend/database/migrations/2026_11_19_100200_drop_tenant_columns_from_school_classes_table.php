<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * school_classes becomes a national list of class names — the same
     * class exists once, shared by every school that adopts it via the
     * new class_schools pivot (see
     * docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md).
     * `section` (a per-school curriculum stream) is dropped outright, not
     * relocated — that distinction is already expressible through the
     * existing ClassVersion/Shift/Section hierarchy.
     */
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropUnique(['school_id', 'name', 'section']);
            $table->dropColumn(['school_id', 'district_id', 'section']);

            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropUnique(['name']);

            $table->foreignId('school_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
            $table->string('section')->nullable();

            $table->unique(['school_id', 'name', 'section']);
        });
    }
};
