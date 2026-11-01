<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A book has at most one complete PDF — flat nullable columns rather
     * than a child table, since there's no multiplicity to model (unlike
     * chapter_materials, which allows many supplementary files).
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('pdf_original_filename')->nullable();
            $table->string('pdf_stored_path')->nullable();
            $table->string('pdf_disk')->nullable();
            $table->string('pdf_mime_type')->nullable();
            $table->unsignedBigInteger('pdf_size_bytes')->nullable();
            $table->foreignId('pdf_uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pdf_uploaded_at')->nullable();
            $table->string('pdf_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['pdf_uploaded_by_id']);
            $table->dropColumn([
                'pdf_original_filename',
                'pdf_stored_path',
                'pdf_disk',
                'pdf_mime_type',
                'pdf_size_bytes',
                'pdf_uploaded_by_id',
                'pdf_uploaded_at',
                'pdf_status',
            ]);
        });
    }
};
