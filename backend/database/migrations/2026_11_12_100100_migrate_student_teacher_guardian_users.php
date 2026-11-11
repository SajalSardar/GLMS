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

        Schema::table('student_profiles', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
            $blueprint->foreign('user_id')->references('id')->on('student_users')->cascadeOnDelete();
        });

        Schema::table('teacher_profiles', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
            $blueprint->foreign('user_id')->references('id')->on('teacher_users')->cascadeOnDelete();
        });

        Schema::table('guardians', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
            $blueprint->foreign('user_id')->references('id')->on('guardian_users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations: copy rows back into users, delete from the
     * new tables.
     */
    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
            $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('teacher_profiles', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
            $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('guardians', function (Blueprint $blueprint) {
            $blueprint->dropForeign(['user_id']);
            $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

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
};
