<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ordered, drag-and-drop content of a question paper — either a
     * reference to a bank question or an inserted section heading. No
     * Auditable/SoftDeletes: items are fully replaced as a set on every
     * paper update (see QuestionPaperController::update()), so per-row
     * history isn't meaningful.
     */
    public function up(): void
    {
        Schema::create('question_paper_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('question_paper_id')->constrained()->cascadeOnDelete();
            // 'question' | 'heading' — validated at the application layer.
            $table->string('item_type');
            $table->foreignId('academic_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('heading_text')->nullable();
            $table->unsignedInteger('order_index');

            $table->timestamps();

            $table->index(['question_paper_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_paper_items');
    }
};
