<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * books.school_id/district_id were denormalized from class_version_id
     * purely for tenant-scope filtering. That filtering is removed — any
     * authenticated actor with books.view can see any book — so these
     * columns are dropped. books.class_version_id is unchanged: a book is
     * still reached through one school's own class_versions row (see the
     * spec's "Rejected alternative" for why re-parenting to a global
     * class is explicitly out of scope).
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['school_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('school_id')->after('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
