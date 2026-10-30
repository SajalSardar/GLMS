<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A curriculum track within a class (e.g. "Bangla Version" / "English
     * Version"), per docs/Multi-Version-Multi-Shift-Multi-Section-Smart
     * Timetable Management.md §2.3.
     */
    public function up(): void
    {
        Schema::create('class_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            // Denormalized from the class's school at creation time (never
            // taken directly from the request) so scope checks can
            // filter/compare without a join.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_versions');
    }
};
