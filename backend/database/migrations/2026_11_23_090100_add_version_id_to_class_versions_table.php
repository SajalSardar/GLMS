<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * version_id is left nullable at the schema level — this project has
     * no doctrine/dbal dependency, so Blueprint::change() (needed to flip
     * an existing column non-nullable) isn't available. NOT NULL is
     * enforced at the application layer instead: StoreClassVersionRequest/
     * UpdateClassVersionRequest both require version_id, so no code path
     * can create or update a class_versions row without one.
     *
     * Existing free-text `name` values are backfilled by a simple
     * case-insensitive match: anything containing "english" maps to the
     * English row, everything else (including the "General" placeholder
     * SectionBackfillSeeder has used, blank, or already "Bangla") maps to
     * Bangla. No production data is expected yet (confirmed assumption
     * carried over from the school_classes restructure), but this makes
     * the migration safe either way.
     */
    public function up(): void
    {
        Schema::table('class_versions', function (Blueprint $table) {
            $table->foreignId('version_id')->nullable()->after('district_id')->constrained('versions')->restrictOnDelete();
        });

        $banglaId = DB::table('versions')->where('name', 'Bangla')->value('id');
        $englishId = DB::table('versions')->where('name', 'English')->value('id');

        DB::table('class_versions')->where('name', 'like', '%english%')->update(['version_id' => $englishId]);
        DB::table('class_versions')->whereNull('version_id')->update(['version_id' => $banglaId]);

        Schema::table('class_versions', function (Blueprint $table) {
            // Same MySQL/InnoDB index-vs-foreign-key requirement as
            // 2026_11_19_100300_scope_class_versions_unique_name_to_school
            // — class_id's FK currently relies on the unique index being
            // dropped below as its only supporting index (class_id is its
            // leftmost column), so a temporary index keeps the FK
            // constraint satisfied while the old unique is swapped for
            // the new one. SQLite has no such requirement, which is why
            // this only surfaces against real MySQL.
            $table->index('class_id', 'class_versions_class_id_index');
            $table->dropUnique(['class_id', 'school_id', 'name']);
            $table->dropColumn(['name', 'bn_name']);
            $table->unique(['class_id', 'school_id', 'version_id']);
            $table->dropIndex('class_versions_class_id_index');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Rebuilds name/bn_name as plain nullable strings rather than
     * restoring the original NOT NULL `name` — same doctrine/dbal
     * limitation as above. This path is a development convenience, not
     * something exercised in production.
     */
    public function down(): void
    {
        Schema::table('class_versions', function (Blueprint $table) {
            // Same temporary-index workaround as up() above — class_id's
            // FK relies solely on the unique being dropped here.
            $table->index('class_id', 'class_versions_class_id_index');
            $table->dropUnique(['class_id', 'school_id', 'version_id']);
            $table->string('name')->nullable();
            $table->string('bn_name')->nullable();
        });

        DB::table('class_versions')->update(['name' => 'General']);

        Schema::table('class_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_id');
            $table->unique(['class_id', 'school_id', 'name']);
            $table->dropIndex('class_versions_class_id_index');
        });
    }
};
