<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records which schools have adopted which national class names —
     * the gate that replaces the implicit "a class belongs to one
     * school" fact school_classes.school_id used to carry before
     * school_classes became national content (see
     * docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md).
     */
    public function up(): void
    {
        Schema::create('class_schools', function (Blueprint $table) {
            $table->id();
            // Polymorphic actor columns, matching the final shape every
            // other Auditable table has after
            // 2026_11_12_100300_make_created_by_updated_by_polymorphic.php
            // (no DB-level FK, since the actor can be a User, TeacherUser,
            // StudentUser, or GuardianUser) — defined directly here since
            // class_schools didn't exist for that retrofit to reach.
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();
            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // Denormalized from the school at creation time (never taken
            // directly from the request) — same convention as every other
            // TenantScoped table in this app.
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['class_id', 'school_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('class_schools');
    }
};
