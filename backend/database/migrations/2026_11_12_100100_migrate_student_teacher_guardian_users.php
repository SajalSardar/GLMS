<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role name => destination table. Preserves each row's `id` so
     * student_profiles.user_id/teacher_profiles.user_id/guardians.user_id
     * keep pointing at the right row without a remap table — only the
     * FK's target table changes (Task 4).
     *
     * @var array<string, string>
     */
    private array $roleTableMap = [
        'student' => 'student_users',
        'teacher' => 'teacher_users',
        'parent_guardian' => 'guardian_users',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->roleTableMap as $roleName => $table) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');

            if ($roleId === null) {
                continue;
            }

            DB::transaction(function () use ($roleId, $table) {
                $rows = DB::table('users')->where('role_id', $roleId)->get();

                foreach ($rows as $row) {
                    if (DB::table($table)->where('id', $row->id)->exists()) {
                        continue;
                    }

                    DB::table($table)->insert([
                        'id' => $row->id,
                        'role_id' => $row->role_id,
                        'school_id' => $row->school_id,
                        'district_id' => $row->district_id,
                        'name' => $row->name,
                        'email' => $row->email,
                        'phone' => $row->phone,
                        'password' => $row->password,
                        'remember_token' => $row->remember_token,
                        'email_verified_at' => $row->email_verified_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                        'deleted_at' => $row->deleted_at,
                    ]);
                }

                DB::table('users')->where('role_id', $roleId)->delete();
            });
        }

        $this->retargetForeignKey('student_profiles', 'user_id', 'student_users', 'cascadeOnDelete');
        $this->retargetForeignKey('teacher_profiles', 'user_id', 'teacher_users', 'cascadeOnDelete');
        $this->retargetForeignKey('guardians', 'user_id', 'guardian_users', 'nullOnDelete');
    }

    /**
     * Reverse the migrations: copy rows back into users, delete from the
     * new tables.
     */
    public function down(): void
    {
        $this->retargetForeignKey('student_profiles', 'user_id', 'users', 'cascadeOnDelete');
        $this->retargetForeignKey('teacher_profiles', 'user_id', 'users', 'cascadeOnDelete');
        $this->retargetForeignKey('guardians', 'user_id', 'users', 'nullOnDelete');

        foreach ($this->roleTableMap as $roleName => $table) {
            DB::transaction(function () use ($table) {
                $rows = DB::table($table)->get();

                foreach ($rows as $row) {
                    if (DB::table('users')->where('id', $row->id)->exists()) {
                        continue;
                    }

                    DB::table('users')->insert([
                        'id' => $row->id,
                        'role_id' => $row->role_id,
                        'school_id' => $row->school_id,
                        'district_id' => $row->district_id,
                        'name' => $row->name,
                        'email' => $row->email,
                        'phone' => $row->phone,
                        'password' => $row->password,
                        'remember_token' => $row->remember_token,
                        'email_verified_at' => $row->email_verified_at,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                        'deleted_at' => $row->deleted_at,
                    ]);
                }

                DB::table($table)->delete();
            });
        }
    }

    /**
     * Repoint $table.$column's foreign key to $target, guarding the
     * drop and the add independently against the real current DB
     * state (not a downstream proxy signal) so either half can be
     * safely retried after a crash — MySQL DDL is not transactional.
     */
    private function retargetForeignKey(string $table, string $column, string $target, string $onDelete): void
    {
        if ($this->hasForeignKey($table, $column) && ! $this->hasForeignKeyReferencing($table, $column, $target)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->dropForeign([$column]);
            });
        }

        if (! $this->hasForeignKeyReferencing($table, $column, $target)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $target, $onDelete) {
                $blueprint->foreign($column)->references('id')->on($target)->{$onDelete}();
            });
        }
    }

    private function hasForeignKey(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn ($foreignKey) => $foreignKey['columns'] === [$column]
        );
    }

    private function hasForeignKeyReferencing(string $table, string $column, string $targetTable): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn ($foreignKey) => $foreignKey['columns'] === [$column] && $foreignKey['foreign_table'] === $targetTable
        );
    }
};
