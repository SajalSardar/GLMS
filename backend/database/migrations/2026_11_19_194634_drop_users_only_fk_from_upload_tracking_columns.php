<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * chapter_materials.uploaded_by_id and books.pdf_uploaded_by_id were
     * FK-constrained to the single `users` table before the student/
     * teacher/guardian user-table split. Since then, a TeacherUser (or
     * any other role-specific actor) uploading has an id from its OWN
     * table, which the `users` FK has no reason to contain — the insert
     * then fails with a foreign key violation (confirmed: the first test
     * to exercise store() as a TeacherUser actor hit this). The
     * polymorphic created_by_type/created_by_id pair (Auditable) already
     * tracks the actual actor correctly and, by the same necessity, has
     * never had an FK at all — these two columns drop theirs to match,
     * keeping the column (still set from $request->user()->id) but no
     * longer enforcing it against one specific user table.
     */
    public function up(): void
    {
        Schema::table('chapter_materials', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by_id']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['pdf_uploaded_by_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapter_materials', function (Blueprint $table) {
            $table->foreign('uploaded_by_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreign('pdf_uploaded_by_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
