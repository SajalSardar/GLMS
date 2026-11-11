<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = ['student_users', 'teacher_users', 'guardian_users'];

    /**
     * Run the migrations.
     *
     * Each table mirrors users' exact shape — see
     * docs/superpowers/specs/2026-11-11-split-user-tables-by-role-design.md
     * "Full users-table shape, duplicated per table".
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::create($table, function (Blueprint $blueprint) {
                $blueprint->id();
                $blueprint->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
                $blueprint->foreignId('school_id')->nullable()->constrained('schools')->nullOnDelete();
                $blueprint->foreignId('district_id')->nullable()->constrained('districts')->nullOnDelete();
                $blueprint->string('name');
                $blueprint->string('email')->nullable()->unique();
                $blueprint->string('phone')->nullable()->unique();
                $blueprint->string('password');
                $blueprint->rememberToken();
                $blueprint->timestamp('email_verified_at')->nullable();
                $blueprint->timestamps();
                $blueprint->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
