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
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upazila_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->string('eiin')->nullable()->unique();
            $table->enum('type', ['government', 'non_government', 'private'])->default('non_government');
            $table->enum('level', ['primary', 'secondary', 'higher_secondary', 'college'])->default('secondary');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedSmallInteger('established_year')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
