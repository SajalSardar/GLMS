<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A version's configured subject list and required weekly periods —
     * §2.3 ("configure version-specific subjects") and §5.1 ("subjects
     * and required weekly periods").
     */
    public function up(): void
    {
        Schema::create('version_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('required_weekly_periods')->nullable();
            $table->timestamps();

            $table->unique(['class_version_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('version_subjects');
    }
};
