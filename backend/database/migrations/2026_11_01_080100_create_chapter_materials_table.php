<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A chapter's PDF materials: exactly one non-archived 'primary' row
     * per chapter (enforced at the validation layer, not a DB
     * constraint — MySQL has no partial unique index), plus any number
     * of 'supplementary' rows.
     */
    public function up(): void
    {
        Schema::create('chapter_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            // Denormalized from the chapter at creation time (never taken
            // directly from the request) — chapter_materials is resolved
            // by direct route-model-binding, so it needs its own
            // TenantScope filter, same as Book/Chapter.
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title')->nullable();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('disk');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('order_index')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chapter_materials');
    }
};
