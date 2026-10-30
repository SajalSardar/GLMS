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
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            // Set only when the guardian also has a login account under
            // the parent_guardian role; a guardian record can exist
            // without one (contact-only).
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('nid_number')->nullable()->unique();
            $table->string('occupation')->nullable();
            $table->string('present_address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};
