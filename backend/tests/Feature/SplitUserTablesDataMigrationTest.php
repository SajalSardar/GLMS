<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SplitUserTablesDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_teacher_row_created_before_the_split_migration_ends_up_in_teacher_users(): void
    {
        // Simulate "existed before the split": insert directly into users
        // with role=teacher, matching what the pre-split app would have
        // done, then re-run just the two split migrations to prove they
        // move it. RefreshDatabase already ran every migration once for
        // this test class, so the row below is inserted AFTER the split
        // already ran — to actually exercise the migration's up() logic
        // against pre-existing data, insert the row, roll the two split
        // migrations back, then forward again.
        $role = Role::where('name', 'teacher')->first() ?? Role::create(['name' => 'teacher', 'label' => 'Teacher']);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Pre-Split Teacher',
            'email' => 'pre-split-teacher@example.test',
            'password' => 'hashed-password',
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:rollback', ['--step' => 1])->run();
        $this->artisan('migrate')->run();

        $this->assertDatabaseMissing('users', ['id' => $userId]);
        $this->assertDatabaseHas('teacher_users', ['id' => $userId, 'email' => 'pre-split-teacher@example.test']);
    }

    public function test_running_the_data_migration_twice_does_not_duplicate_rows(): void
    {
        $role = Role::where('name', 'student')->first() ?? Role::create(['name' => 'student', 'label' => 'Student']);

        DB::table('users')->insert([
            'name' => 'Idempotency Check',
            'email' => 'idempotency@example.test',
            'password' => 'hashed-password',
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('migrate:rollback', ['--step' => 1])->run();
        $this->artisan('migrate')->run();
        $this->artisan('migrate:rollback', ['--step' => 1])->run();
        $this->artisan('migrate')->run();

        $this->assertSame(1, DB::table('student_users')->where('email', 'idempotency@example.test')->count());
    }
}
