<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A book sits between Subject and Chapter in the content hierarchy
     * (ClassVersion -> Subject -> Book -> Chapter). Multiple books are
     * allowed per (class_version_id, subject_id) — see
     * docs/superpowers/specs/2026-11-01-content-hierarchy-books-design.md.
     */
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            // Denormalized from the class version's school/district at
            // creation time (never taken directly from the request) so
            // TenantScope can filter/compare without a join — same
            // convention as ClassVersion/AcademicYear.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->unsignedSmallInteger('order_index')->nullable();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_version_id', 'subject_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
