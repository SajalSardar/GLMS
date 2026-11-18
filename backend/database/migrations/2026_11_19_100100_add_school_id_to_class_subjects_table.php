<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * class_subjects moves from a global fact ("this class teaches these
     * subjects, everywhere") to a per-school fact ("this school's
     * offering of this class teaches these subjects") — matches how the
     * parallel version_subjects (ClassVersion <-> Subject) pivot already
     * works per-school. See
     * docs/superpowers/specs/2026-11-18-school-classes-restructure-design.md.
     */
    public function up(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            // MySQL/InnoDB requires an index covering class_id to support
            // its existing foreign key constraint at all times. The
            // composite unique index below is currently the only index
            // covering class_id, so add a plain fallback index first,
            // drop the old unique, widen it, then drop the fallback —
            // invisible under SQLite, which doesn't enforce this.
            $table->index('class_id', 'class_subjects_class_id_index');
            $table->dropUnique(['class_id', 'subject_id']);
            $table->foreignId('school_id')->after('subject_id')->constrained()->cascadeOnDelete();
            $table->unique(['class_id', 'subject_id', 'school_id']);
            $table->dropIndex('class_subjects_class_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->index('class_id', 'class_subjects_class_id_index');
            $table->dropUnique(['class_id', 'subject_id', 'school_id']);
            $table->dropForeign(['school_id']);
            $table->dropColumn('school_id');
            $table->unique(['class_id', 'subject_id']);
            $table->dropIndex('class_subjects_class_id_index');
        });
    }
};
