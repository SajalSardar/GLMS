<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            // Denormalized from the school at creation time (never taken
            // directly from the request) so scope checks can filter/compare
            // without a join — same convention as `schools.division_id`.
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('numeric_level')->nullable();
            $table->string('section')->nullable();
            $table->timestamps();

            $table->unique(['school_id', 'name', 'section']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};
