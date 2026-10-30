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
        Schema::table('chapters', function (Blueprint $table) {
            // Human-readable ordinal label (e.g. "1st Chapter" / "১ম অধ্যায়"),
            // distinct from `order_index` which is the plain sort integer.
            $table->string('chapter_number')->nullable()->after('order_index');
            $table->string('bn_chapter_number')->nullable()->after('chapter_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn(['chapter_number', 'bn_chapter_number']);
        });
    }
};
