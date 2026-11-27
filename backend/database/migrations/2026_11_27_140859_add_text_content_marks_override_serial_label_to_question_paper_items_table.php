<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renames heading_text -> text_content (same column, now shared by both
     * 'heading' and the new 'text' item type below — plain inserted text
     * reads identically to how a heading's own text is stored, differing
     * only in how it renders), and adds two per-item overrides a teacher
     * can set for a 'question' item without touching the bank question
     * itself: marks_override (this paper's own mark value) and
     * serial_label (this paper's own display number, e.g. "2(a)" — null
     * means "auto-number as usual").
     *
     * RENAME COLUMN is used directly (not Schema::table()->renameColumn(),
     * which needs doctrine/dbal — not installed in this project) — both
     * MySQL 8+ and SQLite 3.25+ support this exact syntax, so it's portable
     * across this app's MySQL-in-production/SQLite-in-tests split without
     * a driver branch.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE question_paper_items RENAME COLUMN heading_text TO text_content');

        Schema::table('question_paper_items', function (Blueprint $table) {
            $table->unsignedInteger('marks_override')->nullable()->after('academic_question_id');
            $table->string('serial_label', 20)->nullable()->after('marks_override');
        });
    }

    public function down(): void
    {
        Schema::table('question_paper_items', function (Blueprint $table) {
            $table->dropColumn(['marks_override', 'serial_label']);
        });

        DB::statement('ALTER TABLE question_paper_items RENAME COLUMN text_content TO heading_text');
    }
};
