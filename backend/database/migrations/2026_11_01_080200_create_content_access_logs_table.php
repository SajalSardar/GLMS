<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An immutable log of every PDF download/preview. Exactly one of
     * book_id / chapter_material_id is set per row (whichever was
     * accessed); chapter_id rides along with chapter_material_id for
     * convenient chapter-scoped queries without a join. No polymorphic
     * relation — this codebase uses explicit nullable FKs throughout.
     * Runs after both books' PDF columns (Task 1) and chapter_materials
     * (Task 2), since chapter_material_id's foreign key needs that
     * table to already exist.
     */
    public function up(): void
    {
        Schema::create('content_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_material_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('content_access_logs');
    }
};
