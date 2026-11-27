<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drops the academic_question_id FK in favor of embedding a question
     * item's own full content (question_type/question_text/options/marks)
     * directly on the row — a teacher may edit any part of it while
     * building a paper (wording, options, marks) without that edit
     * touching the shared bank question it started from, and without
     * needing a "which fields are overridden" distinction at all, since
     * every question item is now simply its own self-contained copy.
     *
     * The FK's nullOnDelete() was never actually exercised in practice —
     * AcademicQuestionController::destroy() only ever soft-deletes, which
     * doesn't fire a DB-level FK action — so dropping it loses no real
     * behavior; "survives the bank question being removed" already came
     * entirely from academicQuestion()->withTrashed(), not the FK.
     *
     * Also adds indent_level: a plain visual nesting depth (0 = a normal
     * item, 1+ = indented further to the right, like nested <li>s) —
     * independent of numbering, which is still driven by serial_label.
     */
    public function up(): void
    {
        Schema::table('question_paper_items', function (Blueprint $table) {
            $table->string('question_type')->nullable()->after('item_type');
            $table->longText('question_text')->nullable()->after('question_type');
            $table->json('options')->nullable()->after('question_text');
            $table->unsignedInteger('marks')->nullable()->after('marks_override');
            $table->unsignedTinyInteger('indent_level')->default(0)->after('serial_label');
        });

        // Backfill every existing 'question' item from the bank question
        // it still points to, before the FK disappears — marks_override
        // (if set) wins over the bank question's own marks, same
        // precedence the old read-time fallback used.
        DB::table('question_paper_items')
            ->where('item_type', 'question')
            ->whereNotNull('academic_question_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $item) {
                $question = DB::table('academic_questions')->where('id', $item->academic_question_id)->first();

                if ($question === null) {
                    return;
                }

                DB::table('question_paper_items')->where('id', $item->id)->update([
                    'question_type' => $question->question_type,
                    'question_text' => $question->question_text,
                    'options' => $question->options,
                    'marks' => $item->marks_override ?? $question->marks,
                ]);
            });

        Schema::table('question_paper_items', function (Blueprint $table) {
            $table->dropForeign(['academic_question_id']);
            $table->dropColumn(['academic_question_id', 'marks_override']);
        });
    }

    /**
     * Structural rollback only — academic_question_id/marks_override
     * return as empty columns, not repopulated from question_text (there
     * is no reliable way back from an edited copy to "which bank question
     * was this originally"), matching how the previous migration's own
     * down() already didn't attempt to resurrect data it couldn't derive.
     */
    public function down(): void
    {
        Schema::table('question_paper_items', function (Blueprint $table) {
            $table->foreignId('academic_question_id')->nullable()->after('item_type')->constrained()->nullOnDelete();
            $table->unsignedInteger('marks_override')->nullable()->after('academic_question_id');
            $table->dropColumn(['question_type', 'question_text', 'options', 'marks', 'indent_level']);
        });
    }
};
