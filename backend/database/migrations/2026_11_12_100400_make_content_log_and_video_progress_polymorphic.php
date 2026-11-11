<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Unlike exam_attempts.user_id (student-only, confirmed via
     * RolePermissionSeeder — see Task 5), the actor here can genuinely
     * be any of the four user tables: any authenticated role can
     * download/preview a book or chapter material, and
     * UpdateChapterVideoProgressRequest::authorize() literally allows
     * any authenticated user. Both become user_type/user_id with no FK,
     * same shape as created_by/updated_by (Task 7). Unlike
     * created_by/updated_by, the id column here is already named
     * user_id, so no renameColumn() step is needed — only add
     * user_type and drop the now-impossible single-table FK.
     *
     * Same resumability discipline as Task 7's migration
     * (2026_11_12_100300_make_created_by_updated_by_polymorphic.php):
     * MySQL DDL is not transactional and each Blueprint command added
     * in a Schema::table() closure compiles to its own sequential
     * ALTER TABLE statement, so every step below — including the
     * chapter_video_progress unique index swap — is independently
     * guarded against actual current DB state (not a downstream proxy
     * signal), so a crash between any two steps and a retry can resume
     * exactly where it left off.
     */
    public function up(): void
    {
        $this->dropUniqueIfExists('chapter_video_progress', ['chapter_video_id', 'user_id']);

        $this->dropUserForeignKey('content_access_logs');
        $this->addUserTypeColumn('content_access_logs');

        $this->dropUserForeignKey('chapter_video_progress');
        $this->addUserTypeColumn('chapter_video_progress');

        $this->addUniqueIfMissing('chapter_video_progress', ['chapter_video_id', 'user_type', 'user_id']);

        DB::table('content_access_logs')->whereNotNull('user_id')->update(['user_type' => User::class]);
        DB::table('chapter_video_progress')->whereNotNull('user_id')->update(['user_type' => User::class]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropUniqueIfExists('chapter_video_progress', ['chapter_video_id', 'user_type', 'user_id']);

        $this->dropUserTypeColumn('chapter_video_progress');
        $this->addUserForeignKey('chapter_video_progress', cascade: true);

        $this->dropUserTypeColumn('content_access_logs');
        $this->addUserForeignKey('content_access_logs', cascade: false);

        $this->addUniqueIfMissing('chapter_video_progress', ['chapter_video_id', 'user_id']);
    }

    /**
     * Add the user_type column to $table, if it isn't already there.
     */
    private function addUserTypeColumn(string $table): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($table) {
            if (! Schema::hasColumn($table, 'user_type')) {
                $blueprint->string('user_type')->nullable()->after('user_id');
            }
        });
    }

    /**
     * Drop the now-unused user_type column from $table, if it's there.
     */
    private function dropUserTypeColumn(string $table): void
    {
        if (Schema::hasColumn($table, 'user_type')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('user_type');
            });
        }
    }

    /**
     * Drop $table's user_id foreign key, if it still exists. Checked
     * against the real current DB state via Schema::getForeignKeys(),
     * not a column-presence proxy.
     */
    private function dropUserForeignKey(string $table): void
    {
        if ($this->hasUserForeignKey($table)) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign(["{$table}_user_id_foreign"]);
            });
        }
    }

    /**
     * Re-add $table's user_id foreign key, if it doesn't already
     * exist.
     */
    private function addUserForeignKey(string $table, bool $cascade): void
    {
        if (! $this->hasUserForeignKey($table)) {
            Schema::table($table, function (Blueprint $blueprint) use ($cascade) {
                $foreign = $blueprint->foreign('user_id')->references('id')->on('users');
                $cascade ? $foreign->cascadeOnDelete() : $foreign->nullOnDelete();
            });
        }
    }

    private function hasUserForeignKey(string $table): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn ($foreignKey) => $foreignKey['columns'] === ['user_id']
        );
    }

    /**
     * Drop a unique index on $table matching exactly $columns, if one
     * currently exists. Checked against the real current DB state via
     * Schema::getIndexes(), not a downstream proxy signal.
     */
    private function dropUniqueIfExists(string $table, array $columns): void
    {
        if ($this->hasUniqueIndex($table, $columns)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                $blueprint->dropUnique($columns);
            });
        }
    }

    /**
     * Add a unique index on $table over exactly $columns, if one
     * doesn't already exist.
     */
    private function addUniqueIfMissing(string $table, array $columns): void
    {
        if (! $this->hasUniqueIndex($table, $columns)) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                $blueprint->unique($columns);
            });
        }
    }

    private function hasUniqueIndex(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn ($index) => $index['unique'] && $index['columns'] === $columns
        );
    }
};
