# Split `users` by Role Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move student/teacher/guardian accounts out of the shared `users` table into their own `student_users`/`teacher_users`/`guardian_users` tables, update every FK and Policy that assumed a single actor model, and go polymorphic on every column where the actor can genuinely be more than one of the four tables.

**Architecture:** Three new tables mirror `users`' exact shape, so `TenantScope` and `hasPermission()` keep working unchanged on any of the four models. Sanctum needs no guard changes (its token table is already polymorphic). Every Policy's actor parameter loosens from `User` to `Illuminate\Contracts\Auth\Authenticatable`. Domain FKs that always mean "a teacher"/"a student" get a straight FK retarget; the two columns where the actor can truly be any of the four tables (`content_access_logs.user_id`, `chapter_video_progress.user_id`) and the audit-columns project's `created_by`/`updated_by` all go polymorphic (`{column}_type`/`{column}_id`, no DB-level FK).

**Tech Stack:** Laravel 13 (PHP 8.5), PHPUnit, sqlite in-memory for tests, MySQL in production.

**Spec:** `docs/superpowers/specs/2026-11-11-split-user-tables-by-role-design.md`

## Global Constraints

- Staff roles (`national_admin`, `district_officer`, `school_admin`, `office_staff`, `it_admin`, `auditor`, `trainer`) stay in `users`. `student`/`teacher`/`parent_guardian` move to `student_users`/`teacher_users`/`guardian_users`.
- The three new tables mirror `users`' exact column shape: `role_id`, `school_id`, `district_id`, `name`, `email`, `phone`, `password`, `remember_token`, `email_verified_at`, timestamps, soft-deletes.
- `student_profiles`, `teacher_profiles`, `guardians` are untouched except their `user_id` FK target table.
- No new Sanctum guards/providers. No change to the Role/Permission system or its seeders. New Policies (`TeacherUserPolicy`/`StudentUserPolicy`/`GuardianUserPolicy`) reuse `users.view`/`users.create`/`users.update`/`users.delete` as their permission strings.
- Every migration that alters an existing table must be re-runnable after a partial failure (`Schema::hasColumn()`/equivalent guard), matching the convention from the audit-columns project's migration.
- Every task that touches a `.php` file ends with `vendor/bin/pint --dirty --format agent` before the commit step.
- Every existing test must keep passing.

---

### Task 1: New tables — `student_users`, `teacher_users`, `guardian_users`

**Files:**
- Create: `database/migrations/2026_11_12_100000_create_student_teacher_guardian_users_tables.php`

**Interfaces:**
- Produces: three tables with `users`' exact column shape. Every later task that references `student_users`/`teacher_users`/`guardian_users` depends on this.

- [ ] **Step 1: Write the migration**

```php
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
```

- [ ] **Step 2: Verify it applies cleanly**

Run: `php artisan test tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS — confirms the new migration coexists with every existing table without breaking `RefreshDatabase`'s from-scratch migrate.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_11_12_100000_create_student_teacher_guardian_users_tables.php
git commit -m "Add student_users, teacher_users, guardian_users tables"
```

---

### Task 2: `StudentUser`, `TeacherUser`, `GuardianUser` models + factories

**Files:**
- Create: `app/Models/StudentUser.php`, `app/Models/TeacherUser.php`, `app/Models/GuardianUser.php`
- Create: `database/factories/StudentUserFactory.php`, `database/factories/TeacherUserFactory.php`, `database/factories/GuardianUserFactory.php`
- Test: `tests/Unit/StudentTeacherGuardianUserModelTest.php` (new)

**Interfaces:**
- Consumes: Task 1's tables.
- Produces: `StudentUser`, `TeacherUser`, `GuardianUser` — each with `role()`, `school()`, `district()`, `profile()` (to `StudentProfile`/`TeacherProfile`/`Guardian` respectively), `hasPermission(string): bool`. Every later task that builds a teacher/student/guardian actor uses these.

- [ ] **Step 1: Write the three models**

`app/Models/TeacherUser.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Database\Factories\TeacherUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role_id', 'school_id', 'district_id'])]
#[Hidden(['password', 'remember_token'])]
class TeacherUser extends Authenticatable
{
    /** @use HasFactory<TeacherUserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoped;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class, 'user_id');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->role?->loadMissing('permissions')->hasPermission($permission) ?? false;
    }
}
```

`app/Models/StudentUser.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Database\Factories\StudentUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role_id', 'school_id', 'district_id'])]
#[Hidden(['password', 'remember_token'])]
class StudentUser extends Authenticatable
{
    /** @use HasFactory<StudentUserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoped;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(StudentProfile::class, 'user_id');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->role?->loadMissing('permissions')->hasPermission($permission) ?? false;
    }
}
```

`app/Models/GuardianUser.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Database\Factories\GuardianUserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'role_id', 'school_id', 'district_id'])]
#[Hidden(['password', 'remember_token'])]
class GuardianUser extends Authenticatable
{
    /** @use HasFactory<GuardianUserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, TenantScoped;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Guardian::class, 'user_id');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->role?->loadMissing('permissions')->hasPermission($permission) ?? false;
    }
}
```

- [ ] **Step 2: Write the three factories**

`database/factories/TeacherUserFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\TeacherUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeacherUser>
 */
class TeacherUserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
```

`database/factories/StudentUserFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\StudentUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<StudentUser>
 */
class StudentUserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
```

`database/factories/GuardianUserFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\GuardianUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<GuardianUser>
 */
class GuardianUserFactory extends Factory
{
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
```

- [ ] **Step 3: Write the failing test**

Create `tests/Unit/StudentTeacherGuardianUserModelTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentTeacherGuardianUserModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_user_has_role_and_permission_shape(): void
    {
        $role = Role::create(['name' => 'teacher', 'label' => 'Teacher']);
        $teacher = TeacherUser::factory()->create(['role_id' => $role->id]);

        $this->assertSame($role->id, $teacher->role->id);
        $this->assertFalse($teacher->hasPermission('questions.create'));
    }

    public function test_student_user_has_role_and_permission_shape(): void
    {
        $role = Role::create(['name' => 'student', 'label' => 'Student']);
        $student = StudentUser::factory()->create(['role_id' => $role->id]);

        $this->assertSame($role->id, $student->role->id);
    }

    public function test_guardian_user_has_role_and_permission_shape(): void
    {
        $role = Role::create(['name' => 'parent_guardian', 'label' => 'Parent/Guardian']);
        $guardian = GuardianUser::factory()->create(['role_id' => $role->id]);

        $this->assertSame($role->id, $guardian->role->id);
    }
}
```

- [ ] **Step 4: Run it to verify it passes**

Run: `php artisan test tests/Unit/StudentTeacherGuardianUserModelTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/StudentUser.php app/Models/TeacherUser.php app/Models/GuardianUser.php database/factories/StudentUserFactory.php database/factories/TeacherUserFactory.php database/factories/GuardianUserFactory.php tests/Unit/StudentTeacherGuardianUserModelTest.php
git commit -m "Add StudentUser/TeacherUser/GuardianUser models and factories"
```

---

### Task 3: Data migration — move existing rows out of `users`

**Files:**
- Create: `database/migrations/2026_11_12_100100_migrate_student_teacher_guardian_users.php`
- Create: `tests/Feature/SplitUserTablesDataMigrationTest.php`

**Interfaces:**
- Consumes: Tasks 1-2's tables/models.
- Produces: every pre-existing `student`/`teacher`/`parent_guardian` row in `users` now lives in the matching new table with the same `id`; `student_profiles.user_id`/`teacher_profiles.user_id`/`guardians.user_id` values are unchanged (same `id`s, new FK target applied in Task 4).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
    }

    /**
     * Reverse the migrations: copy rows back into users, delete from the
     * new tables.
     */
    public function down(): void
    {
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
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/SplitUserTablesDataMigrationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
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
```

- [ ] **Step 3: Run it to verify it passes**

Run: `php artisan test tests/Feature/SplitUserTablesDataMigrationTest.php`
Expected: PASS (2 tests)

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_12_100100_migrate_student_teacher_guardian_users.php tests/Feature/SplitUserTablesDataMigrationTest.php
git commit -m "Add data migration moving student/teacher/guardian rows out of users"
```

---

### Task 4: Repoint `student_profiles`/`teacher_profiles`/`guardians` FKs and relations

**Files:**
- Modify: `database/migrations/2026_11_12_100100_migrate_student_teacher_guardian_users.php` (same migration — add the FK retarget to its `up()`/`down()`)
- Modify: `app/Models/StudentProfile.php`, `app/Models/TeacherProfile.php`, `app/Models/Guardian.php`

**Interfaces:**
- Consumes: Tasks 1-3.
- Produces: `StudentProfile::user()` → `StudentUser`, `TeacherProfile::user()` → `TeacherUser`, `Guardian::user()` → `GuardianUser`. Every later task that calls `$profile->user` gets the right model.

- [ ] **Step 1: Add the FK retarget to the data migration's `up()`/`down()`**

SQLite (the test DB) can't drop/re-add a foreign key constraint via `ALTER TABLE` the way MySQL can, so the FK retarget is expressed as a full column rebuild using `Schema::table()` — append this to the SAME migration file from Task 3, at the end of `up()` (after the per-role loop) and the start of `down()` (before the per-role loop):

In `up()`, after the `foreach ($this->roleTableMap ...)` loop:

```php
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
```

In `down()`, before the `foreach ($this->roleTableMap ...)` loop:

```php
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
```

Add `use Illuminate\Database\Schema\Blueprint;` and `use Illuminate\Support\Facades\Schema;` to the migration's top imports.

- [ ] **Step 2: Repoint the three model relations**

In `app/Models/StudentProfile.php`, change:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

to:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(StudentUser::class);
    }
```

In `app/Models/TeacherProfile.php`, change:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

to:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class);
    }
```

In `app/Models/Guardian.php`, change:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

to:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(GuardianUser::class);
    }
```

- [ ] **Step 3: Run the data migration test again (it now also exercises the FK retarget) plus the full profile/guardian test files**

Run: `php artisan test tests/Feature/SplitUserTablesDataMigrationTest.php tests/Unit/StudentProfilePolicyTest.php`
Expected: both still PASS — the FK retarget doesn't change any row's data, only which table it's constrained against.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_12_100100_migrate_student_teacher_guardian_users.php app/Models/StudentProfile.php app/Models/TeacherProfile.php app/Models/Guardian.php
git commit -m "Repoint student_profiles/teacher_profiles/guardians FKs and relations to the new tables"
```

---

### Task 5: Domain `teacher_id`/`class_teacher_id`/`exam_attempts.user_id` FK repoints

**Files:**
- Create: `database/migrations/2026_11_12_100200_repoint_teacher_and_student_domain_fks.php`
- Modify: `app/Models/ClassRoutine.php`, `app/Models/Section.php`, `app/Models/SubjectTeacherAssignment.php`, `app/Models/TimetableEntry.php`, `app/Models/ExamAttempt.php`

**Interfaces:**
- Consumes: Tasks 1-3 (`teacher_users`/`student_users` must exist and be populated before this FK retarget runs — this migration's timestamp is after Task 3's).
- Produces: `ClassRoutine::teacher()`, `Section::classTeacher()`, `SubjectTeacherAssignment::teacher()`, `TimetableEntry::teacher()` → `TeacherUser`; `ExamAttempt::user()` → `StudentUser`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * table => [column, target table, on-delete behavior]. These columns
     * mean "which teacher/student", not "who created this row" — see
     * docs/superpowers/specs/2026-11-11-split-user-tables-by-role-design.md
     * "Domain FKs beyond the profile tables".
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private array $retargets = [
        ['class_routines', 'teacher_id', 'teacher_users', 'nullOnDelete'],
        ['sections', 'class_teacher_id', 'teacher_users', 'nullOnDelete'],
        ['subject_teacher_assignments', 'teacher_id', 'teacher_users', 'cascadeOnDelete'],
        ['timetable_entries', 'teacher_id', 'teacher_users', 'restrictOnDelete'],
        ['exam_attempts', 'user_id', 'student_users', 'cascadeOnDelete'],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->retargets as [$table, $column, $target, $onDelete]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $target, $onDelete) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on($target)->{$onDelete}();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->retargets) as [$table, $column, $target, $onDelete]) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $onDelete) {
                $blueprint->dropForeign([$column]);
                $blueprint->foreign($column)->references('id')->on('users')->{$onDelete}();
            });
        }
    }
};
```

- [ ] **Step 2: Repoint the five model relations**

In `app/Models/ClassRoutine.php`, change:

```php
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
```

to:

```php
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }
```

In `app/Models/Section.php`, change:

```php
    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'class_teacher_id');
    }
```

to:

```php
    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'class_teacher_id');
    }
```

In `app/Models/SubjectTeacherAssignment.php`, change:

```php
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
```

to:

```php
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }
```

In `app/Models/TimetableEntry.php`, change:

```php
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }
```

to:

```php
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }
```

(`TimetableEntry::publisher()`, which stays on `User::class, 'published_by'`, is unchanged — only `teacher()` moves.)

In `app/Models/ExamAttempt.php`, change:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

to:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(StudentUser::class);
    }
```

- [ ] **Step 3: Run the relevant existing test files**

Run: `php artisan test tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php tests/Feature/ShiftAndSectionAuthorizationTest.php tests/Unit/ExamAttemptTenantScopeTest.php`
Expected: these will likely FAIL at this point, because the test actors are still built as plain `User::factory()` rows (not yet valid `teacher_users`/`student_users` rows) — Task 19 fixes every such test file across the whole plan in one sweep. Confirm the failures are specifically about the FK/relation (e.g. "foreign key constraint failed" or a null relation), not something else; if a failure looks unrelated to this task's change, stop and investigate before moving on.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_12_100200_repoint_teacher_and_student_domain_fks.php app/Models/ClassRoutine.php app/Models/Section.php app/Models/SubjectTeacherAssignment.php app/Models/TimetableEntry.php app/Models/ExamAttempt.php
git commit -m "Repoint teacher_id/class_teacher_id/exam_attempts.user_id FKs to the new tables"
```

---

### Task 6: Update the 11 `Store*Request`/`Update*Request` validations

**Files:**
- Modify: `app/Http/Requests/Api/V1/StoreTeacherProfileRequest.php`
- Modify: `app/Http/Requests/Api/V1/StoreStudentProfileRequest.php`
- Modify: `app/Http/Requests/Api/V1/StoreGuardianRequest.php`
- Modify: `app/Http/Requests/Api/V1/StoreClassRoutineRequest.php`, `UpdateClassRoutineRequest.php`
- Modify: `app/Http/Requests/Api/V1/StoreSectionRequest.php`, `UpdateSectionRequest.php`
- Modify: `app/Http/Requests/Api/V1/StoreSubjectTeacherAssignmentRequest.php`, `UpdateSubjectTeacherAssignmentRequest.php`
- Modify: `app/Http/Requests/Api/V1/StoreTimetableEntryRequest.php`, `UpdateTimetableEntryRequest.php`

**Interfaces:**
- Consumes: Tasks 1-5 (the new tables and FK targets must exist for these `Rule::exists()` checks to validate against the right table).

- [ ] **Step 1: `StoreTeacherProfileRequest.php`**

Change:

```php
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->withoutTrashed(),
                Rule::unique('teacher_profiles', 'user_id')->withoutTrashed(),
            ],
```

to:

```php
            'user_id' => [
                'required', 'integer',
                Rule::exists('teacher_users', 'id')->withoutTrashed(),
                Rule::unique('teacher_profiles', 'user_id')->withoutTrashed(),
            ],
```

- [ ] **Step 2: `StoreStudentProfileRequest.php`**

Find its `'user_id' => [... Rule::exists('users', 'id') ...]` line (same shape as Task 1's) and change `Rule::exists('users', 'id')` to `Rule::exists('student_users', 'id')`.

- [ ] **Step 3: `StoreGuardianRequest.php`**

Change:

```php
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->withoutTrashed(),
                Rule::unique('guardians', 'user_id')->withoutTrashed(),
            ],
```

to:

```php
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('guardian_users', 'id')->withoutTrashed(),
                Rule::unique('guardians', 'user_id')->withoutTrashed(),
            ],
```

- [ ] **Step 4: `StoreClassRoutineRequest.php`**

Change:

```php
            'teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
```

to:

```php
            'teacher_id' => ['nullable', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
```

- [ ] **Step 5: `UpdateClassRoutineRequest.php`**

Same single-line change as Step 4 (identical rule text in this file).

- [ ] **Step 6: `StoreSectionRequest.php`**

Change:

```php
            'class_teacher_id' => ['nullable', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
```

to:

```php
            'class_teacher_id' => ['nullable', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
```

- [ ] **Step 7: `UpdateSectionRequest.php`**

Same single-line change as Step 6 (identical rule text in this file).

- [ ] **Step 8: `StoreSubjectTeacherAssignmentRequest.php`**

Change:

```php
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
```

to:

```php
            'teacher_id' => ['required', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
```

- [ ] **Step 9: `UpdateSubjectTeacherAssignmentRequest.php`**

Same single-line change as Step 8 (identical rule text in this file).

- [ ] **Step 10: `StoreTimetableEntryRequest.php`**

Change:

```php
            'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
```

to:

```php
            'teacher_id' => ['required', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
```

- [ ] **Step 11: `UpdateTimetableEntryRequest.php`**

Change:

```php
            'teacher_id' => ['sometimes', 'required', 'integer', Rule::exists('users', 'id')->withoutTrashed()],
```

to:

```php
            'teacher_id' => ['sometimes', 'required', 'integer', Rule::exists('teacher_users', 'id')->withoutTrashed()],
```

- [ ] **Step 12: Run a quick syntax/lint pass**

Run: `php -l app/Http/Requests/Api/V1/StoreTeacherProfileRequest.php && php -l app/Http/Requests/Api/V1/StoreStudentProfileRequest.php && php -l app/Http/Requests/Api/V1/StoreGuardianRequest.php && php -l app/Http/Requests/Api/V1/StoreClassRoutineRequest.php && php -l app/Http/Requests/Api/V1/UpdateClassRoutineRequest.php && php -l app/Http/Requests/Api/V1/StoreSectionRequest.php && php -l app/Http/Requests/Api/V1/UpdateSectionRequest.php && php -l app/Http/Requests/Api/V1/StoreSubjectTeacherAssignmentRequest.php && php -l app/Http/Requests/Api/V1/UpdateSubjectTeacherAssignmentRequest.php && php -l app/Http/Requests/Api/V1/StoreTimetableEntryRequest.php && php -l app/Http/Requests/Api/V1/UpdateTimetableEntryRequest.php`
Expected: "No syntax errors detected" for all 11.

- [ ] **Step 13: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/StoreTeacherProfileRequest.php app/Http/Requests/Api/V1/StoreStudentProfileRequest.php app/Http/Requests/Api/V1/StoreGuardianRequest.php app/Http/Requests/Api/V1/StoreClassRoutineRequest.php app/Http/Requests/Api/V1/UpdateClassRoutineRequest.php app/Http/Requests/Api/V1/StoreSectionRequest.php app/Http/Requests/Api/V1/UpdateSectionRequest.php app/Http/Requests/Api/V1/StoreSubjectTeacherAssignmentRequest.php app/Http/Requests/Api/V1/UpdateSubjectTeacherAssignmentRequest.php app/Http/Requests/Api/V1/StoreTimetableEntryRequest.php app/Http/Requests/Api/V1/UpdateTimetableEntryRequest.php
git commit -m "Validate user_id/teacher_id fields against the new per-role tables"
```

---

### Task 7: `created_by`/`updated_by` go polymorphic (25 audited tables) + `Auditable` trait rewrite

**Files:**
- Create: `database/migrations/2026_11_12_100300_make_created_by_updated_by_polymorphic.php`
- Modify: `app/Models/Concerns/Auditable.php`
- Modify: `tests/Unit/AuditableTraitTest.php`

**Interfaces:**
- Consumes: the 25-table list from the audit-columns project (same list as `docs/superpowers/plans/2026-11-11-audit-created-by-updated-by.md`'s Task 1: `divisions`, `districts`, `upazilas`, `schools`, `subjects`, `school_classes`, `academic_years`, `classrooms`, `class_routines`, `class_versions`, `shifts`, `sections`, `subject_teacher_assignments`, `timetable_entries`, `books`, `chapters`, `chapter_materials`, `chapter_videos`, `questions`, `exams`, `roles`, `users`, `teacher_profiles`, `student_profiles`, `guardians`).
- Produces: each of the 25 tables gets `created_by_type`/`created_by_id` and `updated_by_type`/`updated_by_id` in place of the old `created_by`/`updated_by`. `Auditable` sets all four from the actor's `get_class()`/`id`. Task 8 consumes this to update every Resource.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Same 25-table list as the audit-columns project's original
     * migration — see
     * docs/superpowers/plans/2026-11-11-audit-created-by-updated-by.md.
     *
     * @var list<string>
     */
    private array $tables = [
        'divisions', 'districts', 'upazilas', 'schools', 'subjects',
        'school_classes', 'academic_years', 'classrooms', 'class_routines',
        'class_versions', 'shifts', 'sections', 'subject_teacher_assignments',
        'timetable_entries', 'books', 'chapters', 'chapter_materials',
        'chapter_videos', 'questions', 'exams', 'roles', 'users',
        'teacher_profiles', 'student_profiles', 'guardians',
    ];

    /**
     * Run the migrations.
     *
     * created_by/updated_by were a flat FK to users.id — now that the
     * actor can be a User, TeacherUser, StudentUser, or GuardianUser, a
     * single FK target isn't possible, so these become polymorphic
     * {column}_type/{column}_id pairs with no DB-level FK. Existing
     * non-null values are backfilled with App\Models\User, since every
     * row created so far was created by a staff actor (the split only
     * just happened).
     */
    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'created_by_type')) {
                    $blueprint->dropForeign([$table === 'users' ? 'users_created_by_foreign' : "{$table}_created_by_foreign"]);
                    $blueprint->string('created_by_type')->nullable()->after('created_by');
                    $blueprint->renameColumn('created_by', 'created_by_id');
                }

                if (! Schema::hasColumn($table, 'updated_by_type')) {
                    $blueprint->dropForeign([$table === 'users' ? 'users_updated_by_foreign' : "{$table}_updated_by_foreign"]);
                    $blueprint->string('updated_by_type')->nullable()->after('updated_by');
                    $blueprint->renameColumn('updated_by', 'updated_by_id');
                }
            });

            DB::table($table)->whereNotNull('created_by_id')->update(['created_by_type' => \App\Models\User::class]);
            DB::table($table)->whereNotNull('updated_by_id')->update(['updated_by_type' => \App\Models\User::class]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'created_by_type')) {
                    $blueprint->renameColumn('created_by_id', 'created_by');
                    $blueprint->dropColumn('created_by_type');
                    $blueprint->foreign('created_by')->references('id')->on('users')->nullOnDelete();
                }

                if (Schema::hasColumn($table, 'updated_by_type')) {
                    $blueprint->renameColumn('updated_by_id', 'updated_by');
                    $blueprint->dropColumn('updated_by_type');
                    $blueprint->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
                }
            });
        }
    }
};
```

**Note for the implementer:** SQLite's `dropForeign()` by constraint name may not resolve the same way MySQL does (SQLite's Laravel grammar often rebuilds the table instead). Run Step 4 early — if `dropForeign()` errors on sqlite, drop it and instead rely on `renameColumn` alone (sqlite doesn't enforce the FK gained via `foreignId()` after a rename the same way, but confirm this empirically rather than assuming): adjust the migration body to whatever actually works against this project's sqlite test DB, keeping the end column shape (`created_by_type`/`created_by_id`/`updated_by_type`/`updated_by_id`, no FK) and the backfill rule identical. Record what you had to change and why in your report.

- [ ] **Step 2: Rewrite the `Auditable` trait**

In `app/Models/Concerns/Auditable.php`, change:

```php
    protected static function bootAuditable(): void
    {
        static::creating(function ($model) {
            if ($model->created_by === null) {
                $model->created_by = Auth::id();
            }
        });

        static::saving(function ($model) {
            // Only an update, never an insert: $model->exists is false
            // during creating() (set true only after the insert
            // completes), so this never fires on create.
            if ($model->exists && ($actorId = Auth::id()) !== null) {
                $model->updated_by = $actorId;
            }
        });
    }
```

to:

```php
    protected static function bootAuditable(): void
    {
        static::creating(function ($model) {
            if ($model->created_by_id === null) {
                $actor = Auth::user();

                if ($actor !== null) {
                    $model->created_by_type = get_class($actor);
                    $model->created_by_id = $actor->getAuthIdentifier();
                }
            }
        });

        static::saving(function ($model) {
            // Only an update, never an insert: $model->exists is false
            // during creating() (set true only after the insert
            // completes), so this never fires on create.
            if (! $model->exists) {
                return;
            }

            $actor = Auth::user();

            if ($actor !== null) {
                $model->updated_by_type = get_class($actor);
                $model->updated_by_id = $actor->getAuthIdentifier();
            }
        });
    }
```

(Read the exact current content of `app/Models/Concerns/Auditable.php` first — it also has a class-level docblock from the audit-columns fix wave explaining the `saving`-vs-`updating` rationale; keep that docblock, only change the method bodies as shown above.)

- [ ] **Step 3: Update the trait's own unit test**

In `tests/Unit/AuditableTraitTest.php`, every assertion against `created_by`/`updated_by` changes to the new column names and checks both halves. For example, change:

```php
        $this->assertSame($actor->id, $division->created_by);
        $this->assertNull($division->updated_by);
```

to:

```php
        $this->assertSame(\App\Models\Division::class === null ? null : get_class($actor), $division->created_by_type);
        $this->assertSame($actor->id, $division->created_by_id);
        $this->assertNull($division->updated_by_type);
        $this->assertNull($division->updated_by_id);
```

Apply the equivalent `_type`/`_id` split to every other assertion in this file (there are a handful more, covering the update case, the no-actor case, and the regression test from the audit-columns fix wave). Read the file's current full content before editing — the exact assertions must be transcribed precisely, not just these two.

- [ ] **Step 4: Run the trait test**

Run: `php artisan test tests/Unit/AuditableTraitTest.php tests/Unit/AuditableTraitCoverageTest.php`
Expected: `AuditableTraitTest` PASS once the rewrite above is correct. `AuditableTraitCoverageTest` (from the audit-columns project) will almost certainly FAIL here, because it asserts every one of the 25 Resources exposes `created_by`/`updated_by` — Task 8 fixes that. Confirm the failure is specifically about those keys, then proceed; do not fix `AuditableTraitCoverageTest` in this task.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_12_100300_make_created_by_updated_by_polymorphic.php app/Models/Concerns/Auditable.php tests/Unit/AuditableTraitTest.php
git commit -m "Make created_by/updated_by polymorphic across the 25 audited tables"
```

---

### Task 8: Update all 25 `*Resource` classes for the polymorphic shape

**Files:**
- Modify: `app/Http/Resources/Api/V1/DivisionResource.php`, `DistrictResource.php`, `UpazilaResource.php`, `SchoolResource.php`, `SubjectResource.php`, `ClassResource.php`, `AcademicYearResource.php`, `ClassroomResource.php`, `ClassRoutineResource.php`, `ClassVersionResource.php`, `ShiftResource.php`, `SectionResource.php`, `SubjectTeacherAssignmentResource.php`, `TimetableEntryResource.php`, `BookResource.php`, `ChapterResource.php`, `ChapterMaterialResource.php`, `ChapterVideoResource.php`, `QuestionResource.php`, `ExamResource.php`, `RoleResource.php`, `UserResource.php`, `TeacherProfileResource.php`, `StudentProfileResource.php`, `GuardianResource.php`
- Modify: `tests/Unit/AuditableTraitCoverageTest.php`

**Interfaces:**
- Consumes: Task 7's column rename.

- [ ] **Step 1: Apply the same transformation to every one of the 25 files**

In every file listed above, find:

```php
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
```

and replace with:

```php
            'created_by_type' => $this->created_by_type,
            'created_by_id' => $this->created_by_id,
            'updated_by_type' => $this->updated_by_type,
            'updated_by_id' => $this->updated_by_id,
```

(Exact position unchanged — immediately after `'id' => $this->id,` in every one of these 25 files, same as the audit-columns project left them.)

- [ ] **Step 2: Update `AuditableTraitCoverageTest.php`**

Read its current content (from the audit-columns project's final-review fix wave). It has a method asserting every audited Resource's `toArray()` contains `created_by`/`updated_by` keys — change that assertion to check for `created_by_type`, `created_by_id`, `updated_by_type`, `updated_by_id` instead.

- [ ] **Step 3: Run it**

Run: `php artisan test tests/Unit/AuditableTraitCoverageTest.php`
Expected: PASS.

- [ ] **Step 4: Run the broader suite to catch any test asserting the old key names**

Run: `php artisan test --compact`
Expected: likely still red in places (Tasks 5's note already flagged teacher/student actor-builder failures; this task may surface additional failures from tests asserting `data.created_by`/`data.updated_by` literally, e.g. every test the audit-columns project extended). Note which test files fail specifically because of `created_by`/`updated_by` key names (as opposed to FK/actor issues from earlier tasks) — Task 19 fixes all of these in one pass, but listing them now in your report saves Task 19 from re-discovering them.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Resources/Api/V1/ tests/Unit/AuditableTraitCoverageTest.php
git commit -m "Expose created_by_type/created_by_id/updated_by_type/updated_by_id on all 25 audited resources"
```

---

### Task 9: `content_access_logs` and `chapter_video_progress` go polymorphic

**Files:**
- Create: `database/migrations/2026_11_12_100400_make_content_log_and_video_progress_polymorphic.php`
- Modify: `app/Models/ContentAccessLog.php`, `app/Models/ChapterVideoProgress.php`
- Modify: `app/Http/Controllers/Api/V1/ChapterVideoController.php`
- Modify: `app/Http/Controllers/Api/V1/BookController.php`, `app/Http/Controllers/Api/V1/ChapterMaterialController.php`

**Interfaces:**
- Produces: `content_access_logs.user_id`/`chapter_video_progress.user_id` become `user_type`/`user_id` morph pairs. `ContentAccessLog::user()`/`ChapterVideoProgress::user()` become `morphTo('user')`.

- [ ] **Step 1: Write the migration**

```php
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
     * Unlike exam_attempts.user_id (student-only, confirmed via
     * RolePermissionSeeder — see Task 5), the actor here can genuinely
     * be any of the four user tables: any authenticated role can
     * download/preview a book or chapter material, and
     * UpdateChapterVideoProgressRequest::authorize() literally allows
     * any authenticated user. Both become user_type/user_id with no FK,
     * same shape as created_by/updated_by.
     */
    public function up(): void
    {
        Schema::table('content_access_logs', function (Blueprint $blueprint) {
            if (! Schema::hasColumn('content_access_logs', 'user_type')) {
                $blueprint->dropForeign(['user_id']);
                $blueprint->string('user_type')->nullable()->after('user_id');
            }
        });

        Schema::table('chapter_video_progress', function (Blueprint $blueprint) {
            if (! Schema::hasColumn('chapter_video_progress', 'user_type')) {
                $blueprint->dropUnique(['chapter_video_id', 'user_id']);
                $blueprint->dropForeign(['user_id']);
                $blueprint->string('user_type')->nullable()->after('user_id');
                $blueprint->unique(['chapter_video_id', 'user_type', 'user_id']);
            }
        });

        DB::table('content_access_logs')->whereNotNull('user_id')->update(['user_type' => \App\Models\User::class]);
        DB::table('chapter_video_progress')->whereNotNull('user_id')->update(['user_type' => \App\Models\User::class]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapter_video_progress', function (Blueprint $blueprint) {
            if (Schema::hasColumn('chapter_video_progress', 'user_type')) {
                $blueprint->dropUnique(['chapter_video_id', 'user_type', 'user_id']);
                $blueprint->dropColumn('user_type');
                $blueprint->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $blueprint->unique(['chapter_video_id', 'user_id']);
            }
        });

        Schema::table('content_access_logs', function (Blueprint $blueprint) {
            if (Schema::hasColumn('content_access_logs', 'user_type')) {
                $blueprint->dropColumn('user_type');
                $blueprint->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }
};
```

(Same sqlite caveat as Task 7, Step 1 — verify `dropForeign()`/`dropUnique()` work against this project's sqlite test DB and adjust if not, keeping the end shape and backfill identical.)

- [ ] **Step 2: Update the two model relations**

In `app/Models/ContentAccessLog.php`, change:

```php
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

to:

```php
    public function user(): MorphTo
    {
        return $this->morphTo();
    }
```

(change the `use Illuminate\Database\Eloquent\Relations\BelongsTo;` import to `use Illuminate\Database\Eloquent\Relations\MorphTo;`)

In `app/Models/ChapterVideoProgress.php`, make the identical change (same import swap, same method body).

- [ ] **Step 3: Update `ChapterVideoController::updateProgress()`**

Change:

```php
        $progress = ChapterVideoProgress::firstOrNew([
            'chapter_video_id' => $chapter_video->id,
            'user_id' => $request->user()->id,
        ]);
```

to:

```php
        $progress = ChapterVideoProgress::firstOrNew([
            'chapter_video_id' => $chapter_video->id,
            'user_type' => get_class($request->user()),
            'user_id' => $request->user()->id,
        ]);
```

and change:

```php
            $progress = ChapterVideoProgress::where('chapter_video_id', $chapter_video->id)
                ->where('user_id', $request->user()->id)
                ->first();
```

to:

```php
            $progress = ChapterVideoProgress::where('chapter_video_id', $chapter_video->id)
                ->where('user_type', get_class($request->user()))
                ->where('user_id', $request->user()->id)
                ->first();
```

- [ ] **Step 4: Update `BookController`'s signed-URL generation and consumption**

Change:

```php
        $params = ['book' => $book->id, 'actor' => $request->user()->id];
```

to:

```php
        $params = ['book' => $book->id, 'actor_type' => get_class($request->user()), 'actor' => $request->user()->id];
```

Change both occurrences of:

```php
        ContentAccessLog::create([
            'book_id' => $book->id,
            'user_id' => $request->integer('actor') ?: null,
            'action' => 'downloaded',
        ]);
```

(and the `'previewed'` twin) to:

```php
        ContentAccessLog::create([
            'book_id' => $book->id,
            'user_type' => $request->string('actor_type')->toString() ?: null,
            'user_id' => $request->integer('actor') ?: null,
            'action' => 'downloaded',
        ]);
```

(keep `'action' => 'previewed',` as-is in the second occurrence — only the `user_type` line is new in both).

- [ ] **Step 5: Update `ChapterMaterialController`'s signed-URL generation and consumption**

Identical treatment: change:

```php
        $params = ['chapter_material' => $chapter_material->id, 'actor' => $request->user()->id];
```

to:

```php
        $params = ['chapter_material' => $chapter_material->id, 'actor_type' => get_class($request->user()), 'actor' => $request->user()->id];
```

and add `'user_type' => $request->string('actor_type')->toString() ?: null,` immediately before the existing `'user_id' => $request->integer('actor') ?: null,` line in both the `download()` and `preview()` methods' `ContentAccessLog::create()` calls.

- [ ] **Step 6: Run the relevant tests**

Run: `php artisan test tests/Feature/ChapterVideoProgressControllerTest.php tests/Unit/ChapterVideoProgressModelTest.php tests/Feature/BookPdfControllerTest.php tests/Feature/ChapterMaterialControllerTest.php tests/Unit/ContentAccessLogTest.php`
Expected: some will fail here if they assert on the actor being a plain `User` or assert exact row shape — note failures for Task 19, same as Task 5's note.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_12_100400_make_content_log_and_video_progress_polymorphic.php app/Models/ContentAccessLog.php app/Models/ChapterVideoProgress.php app/Http/Controllers/Api/V1/ChapterVideoController.php app/Http/Controllers/Api/V1/BookController.php app/Http/Controllers/Api/V1/ChapterMaterialController.php
git commit -m "Make content_access_logs.user and chapter_video_progress.user polymorphic"
```

---

### Task 10: Loosen every Policy's actor type-hint to `Authenticatable`

**Files:**
- Modify (mechanical, identical transformation in each): every file in `app/Policies/*.php` (27 files: `AcademicYearPolicy`, `BookPolicy`, `ChapterMaterialPolicy`, `ChapterPolicy`, `ChapterVideoPolicy`, `ClassPolicy`, `ClassRoutinePolicy`, `ClassVersionPolicy`, `ClassroomPolicy`, `DistrictPolicy`, `DivisionPolicy`, `ExamAttemptPolicy`, `ExamPolicy`, `GuardianPolicy`, `QuestionPolicy`, `RolePolicy`, `SchoolPolicy`, `SectionPolicy`, `ShiftPolicy`, `StudentProfilePolicy`, `SubjectPolicy`, `SubjectTeacherAssignmentPolicy`, `TeacherProfilePolicy`, `TimetableEntryPolicy`, `UpazilaPolicy`, `UserPolicy`, `DivisionPolicy`)
- Modify: `app/Policies/Concerns/ChecksScope.php`
- Modify: `app/Http/Controllers/Api/V1/UserController.php` (the `assertAssignable()` helper's actor parameter only)

**Interfaces:**
- Produces: every Policy and the `ChecksScope` trait accept any of the four Authenticatable models as the acting user.

- [ ] **Step 1: Apply the mechanical transformation to all 27 Policy files**

In every file under `app/Policies/` **except** `UserPolicy.php`, change the import line:

```php
use App\Models\User;
```

to:

```php
use Illuminate\Contracts\Auth\Authenticatable;
```

(keeping its position in the alphabetically-sorted `use` block — `Illuminate\...` sorts after `App\Models\...`, so move it to the correct alphabetical position relative to the file's other `use` statements; check each file's existing import order and slot it in correctly rather than always appending last).

Then, in every method signature in that file, change the ACTOR parameter's type from `User` to `Authenticatable` — this is always the first parameter, always representing "the user attempting the action," never the second parameter (which is always the specific domain resource being acted on, e.g. `Question $question`, `TeacherProfile $profile` — those stay unchanged). For example, in `app/Policies/QuestionPolicy.php`, change every occurrence of `(User $user` to `(Authenticatable $user` — there are 5 such occurrences in this file (`viewAny`, `view`, `create`, `update`, `delete`), and this file has no other `User` usage.

**`UserPolicy.php` is the one exception**, and needs manual care, not the blanket find-replace: its methods are `viewAny(User $user)`, `view(User $user, User $target)`, `create(User $user)`, `update(User $user, User $target)`, `delete(User $user, User $target)`, `assignRole(User $user, User $target)`. Only the FIRST parameter (the actor) in each method changes to `Authenticatable` — the second parameter (`User $target`, the specific `users`-table row being viewed/updated) stays `User`, since `UserController` only ever manages `users` table rows. Add `use Illuminate\Contracts\Auth\Authenticatable;` alongside the existing `use App\Models\User;` (both imports are needed now).

**`GuardianPolicy.php`, `ExamAttemptPolicy.php`, and `StudentProfilePolicy.php` get this same type-hint change in this task**, but their method BODIES have a separate, more involved fix — do NOT touch the bodies here; Task 11 handles that. Doing only the type-hint swap in this task and leaving the (currently `$user->guardian`-calling) bodies as-is is intentional and correct for this task's scope — Task 11 depends on this task's type-hints already being loosened.

- [ ] **Step 2: Loosen `ChecksScope.php`**

Change:

```php
<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksScope
{
    /**
     * A user scoped to a single school may only act within that school;
     * a user scoped to a district (no school) may only act within that
     * district; an unscoped user (e.g. a national administrator) has no
     * restriction.
     */
    private function inScope(User $user, ?int $schoolId, ?int $districtId): bool
```

to:

```php
<?php

namespace App\Policies\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;

trait ChecksScope
{
    /**
     * A user scoped to a single school may only act within that school;
     * a user scoped to a district (no school) may only act within that
     * district; an unscoped user (e.g. a national administrator) has no
     * restriction.
     */
    private function inScope(Authenticatable $user, ?int $schoolId, ?int $districtId): bool
```

(method body unchanged — `$user->school_id`/`$user->district_id` work identically on any of the four models).

- [ ] **Step 3: Loosen `UserController::assertAssignable()`'s actor parameter**

In `app/Http/Controllers/Api/V1/UserController.php`, change:

```php
    private function assertAssignable(User $actor, ?int $roleId, ?int $schoolId, ?int $districtId): void
```

to:

```php
    private function assertAssignable(Authenticatable $actor, ?int $roleId, ?int $schoolId, ?int $districtId): void
```

Add `use Illuminate\Contracts\Auth\Authenticatable;` to this file's imports (keep the existing `use App\Models\User;` too — `show()`/`update()`/`destroy()`/`assignRole()`'s route-bound `User $user` target parameters are unchanged, since `UserController` only manages `users`-table rows going forward).

- [ ] **Step 4: Run the full backend test suite**

Run: `php artisan test --compact`
Expected: this is a pure type-hint change with zero behavior change for every existing `User`-actor test (PHP's type system accepts a `User` instance anywhere `Authenticatable` is expected, since `User` implements that interface) — the ONLY new failures from this specific task should be none. If anything new breaks here that wasn't already broken from Tasks 5/8/9, investigate before moving on — it means something relies on the literal `User` class, not just `Authenticatable`'s shape.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Policies/ app/Http/Controllers/Api/V1/UserController.php
git commit -m "Loosen every Policy's actor type-hint from User to Authenticatable"
```

---

### Task 11: Fix the four `$actor->guardian`/`$user->studentProfile` call sites, clean up `User`

**Files:**
- Modify: `app/Models/Guardian.php` (`scopeVisibleTo()`, `isVisibleTo()`)
- Modify: `app/Policies/ExamAttemptPolicy.php` (`view()`)
- Modify: `app/Policies/StudentProfilePolicy.php` (`viewDashboard()`)
- Modify: `app/Http/Controllers/Api/V1/ExamAttemptController.php` (`start()`)
- Modify: `app/Models/User.php` (remove `guardian()`, `studentProfile()`, `teacherProfile()`)

**Interfaces:**
- Consumes: Task 2's `GuardianUser`/`StudentUser` models, Task 10's loosened type-hints.

- [ ] **Step 1: Fix `Guardian::scopeVisibleTo()` and `isVisibleTo()`**

In `app/Models/Guardian.php`, change the `scopeVisibleTo()` signature and body:

```php
    public function scopeVisibleTo(Builder $query, User $actor): Builder
    {
        $ownGuardian = $actor->guardian;
```

to:

```php
    public function scopeVisibleTo(Builder $query, Authenticatable $actor): Builder
    {
        $ownGuardian = $actor instanceof GuardianUser ? $actor->profile : null;
```

and the `isVisibleTo()` signature and body:

```php
    public function isVisibleTo(User $actor): bool
    {
        $ownGuardian = $actor->guardian;
```

to:

```php
    public function isVisibleTo(Authenticatable $actor): bool
    {
        $ownGuardian = $actor instanceof GuardianUser ? $actor->profile : null;
```

Change the file's `use App\Models\User;`-adjacent imports: this file currently has no explicit `use App\Models\User;` (it references `User` only as the type-hint, resolved via the same-namespace `App\Models` convention — confirm this by checking the file's actual top-of-file `use` block before editing; add `use Illuminate\Contracts\Auth\Authenticatable;` to it). `GuardianUser` is in the same `App\Models` namespace as `Guardian` itself, so no new import is needed for it.

- [ ] **Step 2: Fix `ExamAttemptPolicy::view()`**

Change:

```php
    public function view(User $user, ExamAttempt $examAttempt): bool
    {
        if ($user->id === $examAttempt->user_id) {
            return true;
        }

        if ($user->hasPermission('exam_attempts.view')) {
            return true;
        }

        return $user->guardian !== null
            && $user->guardian->students()->where('user_id', $examAttempt->user_id)->exists();
    }
```

to:

```php
    public function view(Authenticatable $user, ExamAttempt $examAttempt): bool
    {
        if ($user->getAuthIdentifier() === $examAttempt->user_id) {
            return true;
        }

        if ($user->hasPermission('exam_attempts.view')) {
            return true;
        }

        if (! $user instanceof GuardianUser || $user->profile === null) {
            return false;
        }

        return $user->profile->students()->where('user_id', $examAttempt->user_id)->exists();
    }
```

(`$user->id` becomes `$user->getAuthIdentifier()` since `Authenticatable` doesn't guarantee an `id` property by name, only this method; add `use App\Models\GuardianUser;` to the file's imports, alongside the already-loosened `Authenticatable` import from Task 10.)

- [ ] **Step 3: Fix `StudentProfilePolicy::viewDashboard()`**

Change:

```php
    public function viewDashboard(User $user, StudentProfile $profile): bool
    {
        if ($user->id === $profile->user_id) {
            return true;
        }

        if ($user->hasPermission('student_progress.view')) {
            return true;
        }

        return $user->guardian !== null
            && $user->guardian->students()->where('student_profiles.id', $profile->id)->exists();
    }
```

to:

```php
    public function viewDashboard(Authenticatable $user, StudentProfile $profile): bool
    {
        if ($user->getAuthIdentifier() === $profile->user_id) {
            return true;
        }

        if ($user->hasPermission('student_progress.view')) {
            return true;
        }

        if (! $user instanceof GuardianUser || $user->profile === null) {
            return false;
        }

        return $user->profile->students()->where('student_profiles.id', $profile->id)->exists();
    }
```

(add `use App\Models\GuardianUser;` to this file's imports.)

- [ ] **Step 4: Fix `ExamAttemptController::start()`**

Change:

```php
        $user = $request->user();
        $studentProfile = $user->studentProfile;
```

to:

```php
        $user = $request->user();
        $studentProfile = $user->profile;
```

- [ ] **Step 5: Remove the three dead relations from `User`**

In `app/Models/User.php`, remove these three methods entirely (no `users` row can ever be a teacher/student/guardian again after the data migration, so these relations can never return a non-null result going forward):

```php
    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }
```

If `HasOne` becomes otherwise-unused in this file's imports after removing all three, remove the `use Illuminate\Database\Eloquent\Relations\HasOne;` import too — check the file's remaining content first (it still has `role()`, `school()`, `district()`, all `BelongsTo`, so `HasOne` is indeed no longer referenced).

- [ ] **Step 6: Run the directly affected tests**

Run: `php artisan test tests/Unit/StudentProfilePolicyTest.php tests/Feature/ExamAttemptControllerTest.php tests/Feature/ExamAttemptGradingTest.php`
Expected: some may still fail here due to actor-builder issues from earlier tasks (Task 19 territory) rather than this task's logic — confirm any failure is NOT a `GuardianUser`/`profile` related error before moving on; if it is, that's this task's own bug and must be fixed now, not deferred.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Guardian.php app/Policies/ExamAttemptPolicy.php app/Policies/StudentProfilePolicy.php app/Http/Controllers/Api/V1/ExamAttemptController.php app/Models/User.php
git commit -m "Fix guardian/student-profile relation lookups for the new actor models"
```

---

### Task 12: `AuthController` — repoint teacher/student login, add guardian login

**Files:**
- Modify: `app/Http/Controllers/Api/V1/AuthController.php`
- Modify: `routes/api.php`
- Modify: `tests/Feature/PortalLoginTest.php`

**Interfaces:**
- Consumes: Tasks 1-2, 4 (`TeacherUser`/`StudentUser`/`GuardianUser`, and `TeacherProfile`/`StudentProfile` relations pointing at them).
- Produces: `teacherLogin()`/`studentLogin()` issue tokens for `TeacherUser`/`StudentUser`; new `guardianLogin()` issues tokens for `GuardianUser`.

- [ ] **Step 1: Rewrite the imports and the three portal-login methods**

In `app/Http/Controllers/Api/V1/AuthController.php`, change the imports:

```php
use App\Models\StudentProfile;
use App\Models\TeacherProfile;
use App\Models\User;
```

to:

```php
use App\Models\GuardianUser;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\User;
```

(`StudentProfile` and `TeacherProfile` both stay — `studentLogin()`/`teacherLogin()` still reference them directly in their own bodies; `GuardianUser`/`StudentUser`/`TeacherUser` are the three additions.)

Change `teacherLogin()`:

```php
    public function teacherLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = TeacherProfile::where('employee_id', $login)->first()?->user
            ?? User::where('email', $login)->orWhere('phone', $login)->first();

        return $this->issuePortalToken($request, $user, 'portals.teacher');
    }
```

to:

```php
    public function teacherLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = TeacherProfile::where('employee_id', $login)->first()?->user
            ?? TeacherUser::where('email', $login)->orWhere('phone', $login)->first();

        return $this->issuePortalToken($request, $user, 'portals.teacher');
    }
```

Change `studentLogin()`:

```php
    public function studentLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = StudentProfile::where('student_id', $login)->first()?->user;

        return $this->issuePortalToken($request, $user, 'portals.student');
    }
```

— unchanged body. `StudentProfile::where(...)->first()?->user` already resolves to `StudentUser` once Task 4's relation repoint is live, so `studentLogin()` itself needs no edit; it still references `StudentProfile` directly, so keep that import. The full corrected import block for this file (replacing the original `StudentProfile`/`TeacherProfile`/`User` block from the top of this step) is:

```php
use App\Models\GuardianUser;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\User;
```

`studentLogin()`'s body is unchanged.

Add a new `guardianLogin()` method, placed after `managementLogin()` and before `issuePortalToken()`:

```php
    /**
     * Guardian Portal login: resolves by phone or email, same as the
     * generic login; only a `parent_guardian`-role account may
     * authenticate here. No frontend consumes this yet — added for
     * backend symmetry with teacher/student login.
     */
    public function guardianLogin(LoginRequest $request): JsonResponse
    {
        $login = $request->string('login')->toString();

        $user = GuardianUser::where('email', $login)->orWhere('phone', $login)->first();

        return $this->issuePortalToken($request, $user, 'portals.guardian');
    }
```

Loosen `issuePortalToken()`'s signature — the nullable type marker moves from `User` to `Authenticatable`, nothing else about the signature changes (no default value, same parameter order):

```php
    private function issuePortalToken(LoginRequest $request, ?User $user, string $allowedRolesConfigKey): JsonResponse
```

to:

```php
    private function issuePortalToken(LoginRequest $request, ?Authenticatable $user, string $allowedRolesConfigKey): JsonResponse
```

Add `use Illuminate\Contracts\Auth\Authenticatable;` to the file's imports.

- [ ] **Step 2: Add the `portals.guardian` config key**

Check `config/portals.php` (or wherever `portals.teacher`/`portals.student`/`portals.management` are defined — read that file first) and add a `guardian` key listing `['parent_guardian']`, matching the existing shape for `teacher`/`student`/`management`.

- [ ] **Step 3: Add the route**

In `routes/api.php`, change:

```php
        Route::post('/teacher/login', [AuthController::class, 'teacherLogin']);
        Route::post('/student/login', [AuthController::class, 'studentLogin']);
        Route::post('/management/login', [AuthController::class, 'managementLogin']);
```

to:

```php
        Route::post('/teacher/login', [AuthController::class, 'teacherLogin']);
        Route::post('/student/login', [AuthController::class, 'studentLogin']);
        Route::post('/guardian/login', [AuthController::class, 'guardianLogin']);
        Route::post('/management/login', [AuthController::class, 'managementLogin']);
```

- [ ] **Step 4: Update `PortalLoginTest.php`**

Read its current content. Every place it builds a teacher/student actor via `User::factory()->create(['role_id' => ...])` to then log in through `/auth/teacher/login` or `/auth/student/login` needs to build a `TeacherUser::factory()`/`StudentUser::factory()` row instead (with the matching `TeacherProfile`/`StudentProfile` pointing `user_id` at that new row's `id`). Add a new test method for `guardianLogin()`, mirroring whichever existing method shape this file uses for `managementLogin()`/`teacherLogin()` most closely (build a `GuardianUser` row, POST to `/auth/guardian/login`, assert 200 + token).

- [ ] **Step 5: Run it**

Run: `php artisan test tests/Feature/PortalLoginTest.php`
Expected: PASS.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/AuthController.php routes/api.php config/portals.php tests/Feature/PortalLoginTest.php
git commit -m "Repoint teacher/student login to the new tables, add guardian login"
```

---

### Task 13: New `TeacherUserController`

**Files:**
- Create: `app/Http/Controllers/Api/V1/TeacherUserController.php`
- Create: `app/Policies/TeacherUserPolicy.php`
- Create: `app/Http/Requests/Api/V1/StoreTeacherUserRequest.php`, `UpdateTeacherUserRequest.php`
- Create: `app/Http/Resources/Api/V1/TeacherUserResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/TeacherUserAuthorizationTest.php`

**Interfaces:**
- Consumes: Task 2's `TeacherUser` model, Task 10's `ChecksScope`/`Authenticatable` pattern.
- Produces: `POST/GET/PATCH/DELETE /teacher-users`, the second step of creating a teacher account (`POST /teacher-profiles` with `user_id`) now has a first step to call.

- [ ] **Step 1: Write `TeacherUserPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class TeacherUserPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(Authenticatable $user, TeacherUser $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(Authenticatable $user, TeacherUser $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(Authenticatable $user, TeacherUser $target): bool
    {
        return $user->hasPermission('users.delete');
    }
}
```

- [ ] **Step 2: Write `StoreTeacherUserRequest`/`UpdateTeacherUserRequest`**

`app/Http/Requests/Api/V1/StoreTeacherUserRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', TeacherUser::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('teacher_users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('teacher_users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', 'min:8'],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }
}
```

`app/Http/Requests/Api/V1/UpdateTeacherUserRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('teacher_user')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('teacher_users', 'email')->ignore($this->route('teacher_user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('teacher_users', 'phone')->ignore($this->route('teacher_user')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ];
    }
}
```

- [ ] **Step 3: Write `TeacherUserResource`**

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->whenLoaded('role', fn () => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'label' => $this->role->label,
                'permissions' => $this->role->permissions->pluck('name'),
            ]),
            'school' => new SchoolResource($this->whenLoaded('school')),
            'district' => new DistrictResource($this->whenLoaded('district')),
        ];
    }
}
```

(Deliberately no `created_by`/`created_by_type`/etc. — per the spec, the three new login tables don't carry audit columns.)

- [ ] **Step 4: Write `TeacherUserController`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreTeacherUserRequest;
use App\Http\Requests\Api\V1\UpdateTeacherUserRequest;
use App\Http\Resources\Api\V1\TeacherUserResource;
use App\Models\Role;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class TeacherUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TeacherUser::class);

        $teachers = TeacherUser::query()
            ->with(['role.permissions', 'school', 'district'])
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 15));

        return TeacherUserResource::collection($teachers);
    }

    /**
     * Display the specified resource.
     */
    public function show(TeacherUser $teacher_user): TeacherUserResource
    {
        $this->authorize('view', $teacher_user);

        return new TeacherUserResource($teacher_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * The role is always "teacher" — fixed by this endpoint, never
     * caller-supplied (unlike UserController::store(), which lets a
     * caller pick any role up to their own privilege level). Omitted
     * school/district place the new row in the caller's own scope, same
     * convention as UserController::store().
     */
    public function store(StoreTeacherUserRequest $request): JsonResponse
    {
        $this->authorize('create', TeacherUser::class);

        $actor = $request->user();
        $role = Role::where('name', 'teacher')->first();

        $teacher = TeacherUser::create([
            ...$request->safe()->only(['name', 'email', 'phone', 'password']),
            'role_id' => $role?->id,
            'school_id' => $request->filled('school_id') ? $request->integer('school_id') : $actor->school_id,
            'district_id' => $request->filled('district_id') ? $request->integer('district_id') : $actor->district_id,
        ]);

        return (new TeacherUserResource($teacher->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTeacherUserRequest $request, TeacherUser $teacher_user): TeacherUserResource
    {
        $this->authorize('update', $teacher_user);

        $teacher_user->update($request->safe()->only(['name', 'email', 'phone', 'password']));

        return new TeacherUserResource($teacher_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TeacherUser $teacher_user): Response
    {
        $this->authorize('delete', $teacher_user);

        $teacher_user->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 5: Add the routes**

In `routes/api.php`, inside the `auth:sanctum` middleware group, immediately after the existing `users`/`assignRole` lines:

```php
        Route::apiResource('users', UserController::class);
        Route::patch('/users/{user}/role', [UserController::class, 'assignRole']);

        Route::apiResource('teacher-users', TeacherUserController::class);
```

(full `apiResource`, no `except()` — `TeacherUserController` has all five actions including `destroy`, matching `users`' own unrestricted `apiResource` registration.)

Add `use App\Http\Controllers\Api\V1\TeacherUserController;` to the file's top imports (alphabetically ordered alongside the existing controller imports).

- [ ] **Step 6: Write the failing test, then make it pass**

Create `tests/Feature/TeacherUserAuthorizationTest.php`, mirroring `UserAuthorizationTest.php`'s shape:

```php
<?php

namespace Tests\Feature;

use App\Models\TeacherUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_teacher_user_store_and_update_are_authorized(): void
    {
        $target = TeacherUser::factory()->create();
        $payload = ['name' => 'New Teacher', 'email' => 'new-teacher@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/teacher-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/teacher-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/teacher-users/{$target->id}", [])->assertOk();
    }
}
```

Run: `php artisan test tests/Feature/TeacherUserAuthorizationTest.php`
Expected: PASS (1 test) once all the files above are in place.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/TeacherUserController.php app/Policies/TeacherUserPolicy.php app/Http/Requests/Api/V1/StoreTeacherUserRequest.php app/Http/Requests/Api/V1/UpdateTeacherUserRequest.php app/Http/Resources/Api/V1/TeacherUserResource.php routes/api.php tests/Feature/TeacherUserAuthorizationTest.php
git commit -m "Add TeacherUserController for creating/managing teacher login accounts"
```

---

### Task 14: New `StudentUserController`

**Files:**
- Create: `app/Http/Controllers/Api/V1/StudentUserController.php`
- Create: `app/Policies/StudentUserPolicy.php`
- Create: `app/Http/Requests/Api/V1/StoreStudentUserRequest.php`, `UpdateStudentUserRequest.php`
- Create: `app/Http/Resources/Api/V1/StudentUserResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/StudentUserAuthorizationTest.php`

**Interfaces:**
- Consumes: Task 2's `StudentUser` model.
- Produces: `POST/GET/PATCH/DELETE /student-users`.

- [ ] **Step 1: Write `StudentUserPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\StudentUser;
use Illuminate\Contracts\Auth\Authenticatable;

class StudentUserPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(Authenticatable $user, StudentUser $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(Authenticatable $user, StudentUser $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(Authenticatable $user, StudentUser $target): bool
    {
        return $user->hasPermission('users.delete');
    }
}
```

- [ ] **Step 2: Write `StoreStudentUserRequest`/`UpdateStudentUserRequest`**

`app/Http/Requests/Api/V1/StoreStudentUserRequest.php` — `email` is `nullable` here, not `required` (unlike `StoreTeacherUserRequest`/`StoreGuardianUserRequest`): `StudentProfile`/`AuthController::studentLogin()` resolve a student by `student_id` via the profile, not by email, matching how a student account today may have no email/phone set per `studentLogin()`'s own doc comment ("a student's account may not even have those set"). The password rule is `min:4`, matching `StoreUserRequest::isStudentRole()`'s precedent for a student's short PIN — and since this endpoint always creates a student, there's no role lookup needed to decide that, unlike `StoreUserRequest`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StudentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentUser::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('student_users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('student_users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', 'min:4'],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }
}
```

`app/Http/Requests/Api/V1/UpdateStudentUserRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('student_user')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:255',
                Rule::unique('student_users', 'email')->ignore($this->route('student_user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('student_users', 'phone')->ignore($this->route('student_user')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:4'],
        ];
    }
}
```

- [ ] **Step 3: Write `StudentUserResource`**

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StudentUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->whenLoaded('role', fn () => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'label' => $this->role->label,
                'permissions' => $this->role->permissions->pluck('name'),
            ]),
            'school' => new SchoolResource($this->whenLoaded('school')),
            'district' => new DistrictResource($this->whenLoaded('district')),
        ];
    }
}
```

- [ ] **Step 4: Write `StudentUserController`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreStudentUserRequest;
use App\Http\Requests\Api\V1\UpdateStudentUserRequest;
use App\Http\Resources\Api\V1\StudentUserResource;
use App\Models\Role;
use App\Models\StudentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StudentUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StudentUser::class);

        $students = StudentUser::query()
            ->with(['role.permissions', 'school', 'district'])
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 15));

        return StudentUserResource::collection($students);
    }

    /**
     * Display the specified resource.
     */
    public function show(StudentUser $student_user): StudentUserResource
    {
        $this->authorize('view', $student_user);

        return new StudentUserResource($student_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * The role is always "student" — fixed by this endpoint, never
     * caller-supplied. Omitted school/district place the new row in the
     * caller's own scope, same convention as UserController::store().
     */
    public function store(StoreStudentUserRequest $request): JsonResponse
    {
        $this->authorize('create', StudentUser::class);

        $actor = $request->user();
        $role = Role::where('name', 'student')->first();

        $student = StudentUser::create([
            ...$request->safe()->only(['name', 'email', 'phone', 'password']),
            'role_id' => $role?->id,
            'school_id' => $request->filled('school_id') ? $request->integer('school_id') : $actor->school_id,
            'district_id' => $request->filled('district_id') ? $request->integer('district_id') : $actor->district_id,
        ]);

        return (new StudentUserResource($student->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateStudentUserRequest $request, StudentUser $student_user): StudentUserResource
    {
        $this->authorize('update', $student_user);

        $student_user->update($request->safe()->only(['name', 'email', 'phone', 'password']));

        return new StudentUserResource($student_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(StudentUser $student_user): Response
    {
        $this->authorize('delete', $student_user);

        $student_user->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 5: Add the route**

In `routes/api.php`, immediately after the `teacher-users` line added in Task 13:

```php
        Route::apiResource('student-users', StudentUserController::class);
```

Add `use App\Http\Controllers\Api\V1\StudentUserController;` to the imports.

- [ ] **Step 6: Write the failing test, then make it pass**

Create `tests/Feature/StudentUserAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\StudentUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_student_user_store_and_update_are_authorized(): void
    {
        $target = StudentUser::factory()->create();
        $payload = ['name' => 'New Student', 'password' => 'pin1'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/student-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/student-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/student-users/{$target->id}", [])->assertOk();
    }
}
```

Run: `php artisan test tests/Feature/StudentUserAuthorizationTest.php`
Expected: PASS (1 test) once all the files above are in place.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/StudentUserController.php app/Policies/StudentUserPolicy.php app/Http/Requests/Api/V1/StoreStudentUserRequest.php app/Http/Requests/Api/V1/UpdateStudentUserRequest.php app/Http/Resources/Api/V1/StudentUserResource.php routes/api.php tests/Feature/StudentUserAuthorizationTest.php
git commit -m "Add StudentUserController for creating/managing student login accounts"
```

---

### Task 15: New `GuardianUserController`

**Files:**
- Create: `app/Http/Controllers/Api/V1/GuardianUserController.php`
- Create: `app/Policies/GuardianUserPolicy.php`
- Create: `app/Http/Requests/Api/V1/StoreGuardianUserRequest.php`, `UpdateGuardianUserRequest.php`
- Create: `app/Http/Resources/Api/V1/GuardianUserResource.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/GuardianUserAuthorizationTest.php`

**Interfaces:**
- Consumes: Task 2's `GuardianUser` model.
- Produces: `POST/GET/PATCH/DELETE /guardian-users`.

- [ ] **Step 1: Write `GuardianUserPolicy`**

```php
<?php

namespace App\Policies;

use App\Models\GuardianUser;
use Illuminate\Contracts\Auth\Authenticatable;

class GuardianUserPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('users.view');
    }

    public function view(Authenticatable $user, GuardianUser $target): bool
    {
        return $user->hasPermission('users.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('users.create');
    }

    public function update(Authenticatable $user, GuardianUser $target): bool
    {
        return $user->hasPermission('users.update');
    }

    public function delete(Authenticatable $user, GuardianUser $target): bool
    {
        return $user->hasPermission('users.delete');
    }
}
```

- [ ] **Step 2: Write `StoreGuardianUserRequest`/`UpdateGuardianUserRequest`**

`app/Http/Requests/Api/V1/StoreGuardianUserRequest.php` — password rule is `min:8` (a guardian account is a full account, not a short student PIN):

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\GuardianUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGuardianUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', GuardianUser::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('guardian_users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('guardian_users', 'phone')->withoutTrashed()],
            'password' => ['required', 'string', 'min:8'],
            'school_id' => ['nullable', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->withoutTrashed()],
        ];
    }
}
```

`app/Http/Requests/Api/V1/UpdateGuardianUserRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateGuardianUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('guardian_user')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:255',
                Rule::unique('guardian_users', 'email')->ignore($this->route('guardian_user')),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('guardian_users', 'phone')->ignore($this->route('guardian_user')),
            ],
            'password' => ['sometimes', 'required', 'string', 'min:8'],
        ];
    }
}
```

- [ ] **Step 3: Write `GuardianUserResource`**

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GuardianUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->whenLoaded('role', fn () => [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'label' => $this->role->label,
                'permissions' => $this->role->permissions->pluck('name'),
            ]),
            'school' => new SchoolResource($this->whenLoaded('school')),
            'district' => new DistrictResource($this->whenLoaded('district')),
        ];
    }
}
```

- [ ] **Step 4: Write `GuardianUserController`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreGuardianUserRequest;
use App\Http\Requests\Api\V1\UpdateGuardianUserRequest;
use App\Http\Resources\Api\V1\GuardianUserResource;
use App\Models\GuardianUser;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GuardianUserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', GuardianUser::class);

        $guardians = GuardianUser::query()
            ->with(['role.permissions', 'school', 'district'])
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 15));

        return GuardianUserResource::collection($guardians);
    }

    /**
     * Display the specified resource.
     */
    public function show(GuardianUser $guardian_user): GuardianUserResource
    {
        $this->authorize('view', $guardian_user);

        return new GuardianUserResource($guardian_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * The role is always "parent_guardian" — fixed by this endpoint,
     * never caller-supplied. Omitted school/district place the new row
     * in the caller's own scope, same convention as
     * UserController::store().
     */
    public function store(StoreGuardianUserRequest $request): JsonResponse
    {
        $this->authorize('create', GuardianUser::class);

        $actor = $request->user();
        $role = Role::where('name', 'parent_guardian')->first();

        $guardian = GuardianUser::create([
            ...$request->safe()->only(['name', 'email', 'phone', 'password']),
            'role_id' => $role?->id,
            'school_id' => $request->filled('school_id') ? $request->integer('school_id') : $actor->school_id,
            'district_id' => $request->filled('district_id') ? $request->integer('district_id') : $actor->district_id,
        ]);

        return (new GuardianUserResource($guardian->load(['role.permissions', 'school', 'district'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGuardianUserRequest $request, GuardianUser $guardian_user): GuardianUserResource
    {
        $this->authorize('update', $guardian_user);

        $guardian_user->update($request->safe()->only(['name', 'email', 'phone', 'password']));

        return new GuardianUserResource($guardian_user->load(['role.permissions', 'school', 'district']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GuardianUser $guardian_user): Response
    {
        $this->authorize('delete', $guardian_user);

        $guardian_user->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 5: Add the route**

```php
        Route::apiResource('guardian-users', GuardianUserController::class);
```

Add `use App\Http\Controllers\Api\V1\GuardianUserController;` to the imports.

- [ ] **Step 6: Write the failing test, then make it pass**

Create `tests/Feature/GuardianUserAuthorizationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\GuardianUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class GuardianUserAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_guardian_user_store_and_update_are_authorized(): void
    {
        $target = GuardianUser::factory()->create();
        $payload = ['name' => 'New Guardian', 'email' => 'new-guardian@example.test', 'password' => 'password123'];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/guardian-users/{$target->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['users.create', 'users.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/guardian-users', $payload)->assertCreated();
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/guardian-users/{$target->id}", [])->assertOk();
    }
}
```

Run: `php artisan test tests/Feature/GuardianUserAuthorizationTest.php`
Expected: PASS (1 test) once all the files above are in place.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/GuardianUserController.php app/Policies/GuardianUserPolicy.php app/Http/Requests/Api/V1/StoreGuardianUserRequest.php app/Http/Requests/Api/V1/UpdateGuardianUserRequest.php app/Http/Resources/Api/V1/GuardianUserResource.php routes/api.php tests/Feature/GuardianUserAuthorizationTest.php
git commit -m "Add GuardianUserController for creating/managing guardian login accounts"
```

---

### Task 16: Update seeders

**Files:**
- Modify: `database/seeders/UserSeeder.php`
- Modify: `database/seeders/TeacherProfileSeeder.php`, `StudentProfileSeeder.php`, `GuardianSeeder.php`
- Modify: `database/seeders/ClassRoutineSeeder.php`, `LmsContentDemoSeeder.php`

**Interfaces:**
- Consumes: Tasks 1-9.

- [ ] **Step 1: Split `UserSeeder`**

In `database/seeders/UserSeeder.php`, remove the three rows for `teacher`, `student`, `parent_guardian` from the `$users` array (they move to a new seeder below), leaving only: `national_admin`, `district_officer`, `school_admin`, `trainer`, `office_staff`, `it_admin`, `auditor`.

Create `database/seeders/TeacherStudentGuardianUserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\GuardianUser;
use App\Models\Role;
use App\Models\StudentUser;
use App\Models\TeacherUser;
use Illuminate\Database\Seeder;

class TeacherStudentGuardianUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The teacher/student/parent_guardian sample accounts UserSeeder used
     * to create directly in `users` — moved to their own tables since the
     * split. Same emails/phones/scope as before, so every downstream
     * seeder that looks these up by email keeps working.
     */
    public function run(): void
    {
        $teacherRole = Role::where('name', 'teacher')->firstOrFail();

        TeacherUser::updateOrCreate(
            ['email' => 'teacher@tlap.test'],
            [
                'name' => 'Teacher',
                'phone' => '01700000004',
                'password' => 'password',
                'role_id' => $teacherRole->id,
                'school_id' => 1,
                'district_id' => 1,
                'email_verified_at' => now(),
            ],
        );

        $studentRole = Role::where('name', 'student')->firstOrFail();

        StudentUser::updateOrCreate(
            ['email' => 'student@tlap.test'],
            [
                'name' => 'Student',
                'phone' => '01700000005',
                'password' => 'password',
                'role_id' => $studentRole->id,
                'school_id' => 1,
                'district_id' => 1,
                'email_verified_at' => now(),
            ],
        );

        $guardianRole = Role::where('name', 'parent_guardian')->firstOrFail();

        GuardianUser::updateOrCreate(
            ['email' => 'parent.guardian@tlap.test'],
            [
                'name' => 'Parent Guardian',
                'phone' => '01700000008',
                'password' => 'password',
                'role_id' => $guardianRole->id,
                'school_id' => 1,
                'district_id' => 1,
                'email_verified_at' => now(),
            ],
        );
    }
}
```

- [ ] **Step 2: Repoint `TeacherProfileSeeder`/`StudentProfileSeeder`/`GuardianSeeder`**

In `database/seeders/TeacherProfileSeeder.php`, change:

```php
use App\Models\TeacherProfile;
use App\Models\User;
```

to:

```php
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
```

and change:

```php
        $user = User::where('email', 'teacher@tlap.test')->first();
```

to:

```php
        $user = TeacherUser::where('email', 'teacher@tlap.test')->first();
```

In `database/seeders/StudentProfileSeeder.php`, same treatment: `use App\Models\User;` → `use App\Models\StudentUser;`, and `User::where('email', 'student@tlap.test')` → `StudentUser::where('email', 'student@tlap.test')`.

In `database/seeders/GuardianSeeder.php`, same treatment: `use App\Models\User;` → `use App\Models\GuardianUser;`, and `User::where('email', 'parent.guardian@tlap.test')` → `GuardianUser::where('email', 'parent.guardian@tlap.test')`.

- [ ] **Step 3: Repoint `ClassRoutineSeeder`**

Change:

```php
use App\Models\User;
```

to:

```php
use App\Models\TeacherUser;
```

and change:

```php
        $teacher = User::where('email', 'teacher@tlap.test')->first();
```

to:

```php
        $teacher = TeacherUser::where('email', 'teacher@tlap.test')->first();
```

- [ ] **Step 4: Repoint `LmsContentDemoSeeder`**

Change:

```php
use App\Models\User;
```

to:

```php
use App\Models\StudentUser;
use App\Models\TeacherUser;
```

Change:

```php
        $teacher = User::where('email', 'teacher@tlap.test')->first();
        $studentUser = User::where('email', 'student@tlap.test')->first();
```

to:

```php
        $teacher = TeacherUser::where('email', 'teacher@tlap.test')->first();
        $studentUser = StudentUser::where('email', 'student@tlap.test')->first();
```

This seeder stores `$teacher->id` into `books.pdf_uploaded_by_id` and `chapter_materials.uploaded_by_id` (3 call sites — `seedBookWithChapters()`'s `'pdf_uploaded_by_id' => $teacher->id` and `seedChapterContent()`'s two `'uploaded_by_id' => $teacher->id`). Per the spec, those two columns stay FK'd to `users` (only `trainer`/staff ever legitimately hold `books.create`/`chapter_materials.create`), so a `teacher_users.id` value there is wrong once `$teacher` is a `TeacherUser`. Fetch the sample school_admin instead for just these three assignments — add `use App\Models\User;` back (still needed for this) and, in `run()`, add:

```php
        $staffUploader = User::where('email', 'school.admin@tlap.test')->first();
```

Then change the two private method signatures and their three `_uploaded_by_id` assignments:

`seedBookWithChapters(ClassVersion $version, string $subjectName, string $bookName, array $chapterDefinitions, User $teacher)` stays taking `$teacher` for whatever else it uses it for (check the full method body — if `$teacher` is ONLY used for `pdf_uploaded_by_id`, add a new `?User $staffUploader` parameter instead and pass `$staffUploader` from `run()`; if `$teacher` is used for anything else in that method, keep both parameters). Same for `seedChapterContent(Chapter $chapter, User $teacher, StudentProfile $studentProfile)` — read the full method bodies first to confirm exactly what `$teacher` is used for beyond the `uploaded_by_id` lines already found, then wire `$staffUploader` through for just the `*_uploaded_by_id` assignments while `$teacher`/`$studentUser` continue to flow into `ChapterVideoProgress`/`ExamAttempt` creation (which now correctly expect a `TeacherUser`/`StudentUser`-shaped id, per Tasks 5 and 9).

- [ ] **Step 5: Update `DatabaseSeeder`**

Add `TeacherStudentGuardianUserSeeder::class` to the `$this->call([...])` list in `database/seeders/DatabaseSeeder.php`, immediately after `UserSeeder::class` and before `TeacherProfileSeeder::class`.

- [ ] **Step 6: Run the seeder end to end**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php`
Expected: PASS — this is the only test that actually runs the full seeder chain end to end, so it's the real verification for this task.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/UserSeeder.php database/seeders/TeacherStudentGuardianUserSeeder.php database/seeders/TeacherProfileSeeder.php database/seeders/StudentProfileSeeder.php database/seeders/GuardianSeeder.php database/seeders/ClassRoutineSeeder.php database/seeders/LmsContentDemoSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "Repoint seeders to the new teacher/student/guardian tables"
```

---

### Task 17: `CreatesAuthorizedActors` — new actor builders

**Files:**
- Modify: `tests/Concerns/CreatesAuthorizedActors.php`

**Interfaces:**
- Produces: `teacherActorWithPermissions()`, `studentActorWithPermissions()`, `guardianActorWithPermissions()` — Task 19 and every future test that needs a real teacher/student/guardian actor uses these.

- [ ] **Step 1: Add the three new methods**

In `tests/Concerns/CreatesAuthorizedActors.php`, add (alongside the existing `actorWithPermissions()`/`actorWithNoPermissions()`):

```php
    /**
     * @param  list<string>  $permissionNames
     */
    private function teacherActorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): TeacherUser
    {
        $role = Role::create(['name' => 'teacher_role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Teacher Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return TeacherUser::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function studentActorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): StudentUser
    {
        $role = Role::create(['name' => 'student_role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Student Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return StudentUser::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function guardianActorWithPermissions(array $permissionNames, ?int $schoolId = null, ?int $districtId = null): GuardianUser
    {
        $role = Role::create(['name' => 'guardian_role_'.md5(implode(',', $permissionNames).microtime()), 'label' => 'Test Guardian Role']);

        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->attach($permission);
        }

        return GuardianUser::factory()->create(['role_id' => $role->id, 'school_id' => $schoolId, 'district_id' => $districtId]);
    }
```

Add `use App\Models\GuardianUser;`, `use App\Models\StudentUser;`, `use App\Models\TeacherUser;` to this file's imports.

- [ ] **Step 2: Verify the file still parses and nothing existing breaks**

Run: `php artisan test tests/Feature/ReferenceDataAuthorizationTest.php`
Expected: PASS (this file uses the trait and exercises the unchanged `actorWithPermissions()`/`actorWithNoPermissions()` methods — confirms the new additions didn't break the existing ones).

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Concerns/CreatesAuthorizedActors.php
git commit -m "Add teacher/student/guardian actor builders to the test helper trait"
```

---

### Task 18: Fix every existing test broken by the split

**Files:**
- Modify: every test file surfaced as failing by Tasks 5, 8, 9, and this task's own Step 1 run (expected candidates based on the research behind this plan: `tests/Unit/ExamAttemptTenantScopeTest.php`, `tests/Feature/ExamAttemptControllerTest.php`, `tests/Feature/ExamAttemptGradingTest.php`, `tests/Unit/ChapterVideoProgressModelTest.php`, `tests/Feature/ChapterVideoProgressControllerTest.php`, `tests/Unit/ContentAccessLogTest.php`, `tests/Feature/BookPdfControllerTest.php`, `tests/Feature/ChapterMaterialControllerTest.php`, `tests/Feature/RoutineAssignmentTimetableAuthorizationTest.php`, `tests/Feature/ShiftAndSectionAuthorizationTest.php`, `tests/Feature/TimetableEntryControllerUpdateTest.php`, `tests/Feature/TimetableEntryGenerateControllerTest.php`, `tests/Unit/TimetableGeneratorTest.php`, `tests/Unit/TimetableConflictCheckerTest.php`, `tests/Unit/TimetableEntryCreateOrRestoreTest.php`, `tests/Feature/SchoolDirectAuthorizationTest.php`, `tests/Feature/StudentProfileAuthorizationTest.php`, `tests/Unit/StudentProfilePolicyTest.php`, `tests/Feature/StudentDashboardControllerTest.php`, `tests/Unit/StudentDashboardBuilderTest.php`, `tests/Feature/QuestionImportExportTest.php`, `tests/Feature/QuestionControllerTest.php`, `tests/Feature/ExamControllerTest.php`, `tests/Feature/StudentPasswordValidationTest.php`, `tests/Feature/StudentProgressPermissionMappingTest.php`, `tests/Feature/ExamAttemptsPermissionMappingTest.php`, `tests/Feature/TimetableEntriesGeneratePermissionMappingTest.php`)

**Interfaces:**
- Consumes: every prior task.

**The rule to apply, mechanically, wherever it's broken:**

1. A test that builds an actor meant to represent a teacher — look for `User::factory()->create([...])` immediately followed by (or preceded by) a `TeacherProfile::create(['user_id' => $that_actor->id, ...])`, or granted teacher-shaped permissions like `questions.create`/`exams.create`/`teacher_profiles.*` — replace `User::factory()` with `TeacherUser::factory()` for that specific actor (not every actor in the file — only the one meant to BE a teacher), and use `teacherActorWithPermissions()` in place of `actorWithPermissions()` wherever the test used that helper for a teacher-shaped permission set.
2. Same rule for a student actor: `StudentProfile::create(['user_id' => ...])`, `exam_attempts.create`, `student_profiles.*` as the actor's own record → `StudentUser::factory()` / `studentActorWithPermissions()`.
3. Same rule for a guardian actor: `Guardian::create(['user_id' => ...])`, `guardians.*` → `GuardianUser::factory()` / `guardianActorWithPermissions()`.
4. A test asserting `data.created_by` / `data.updated_by` (literal key names) on one of the 25 audited resources' JSON response — update the assertion to `data.created_by_type`/`data.created_by_id` (and the `updated_by` equivalents), matching Task 8's new shape. Where the old assertion checked the VALUE equals the actor's id (e.g. `assertJsonPath('data.created_by', $allowed->id)`), the new pair of assertions is `assertJsonPath('data.created_by_type', get_class($allowed))` and `assertJsonPath('data.created_by_id', $allowed->id)`.
5. A test creating a `ContentAccessLog`/`ChapterVideoProgress` row directly (not through the HTTP layer) and asserting on `user_id` — add the matching `user_type` value alongside every `user_id` the test sets or asserts.
6. A test that hits `/exam-attempts`/`/exams/{exam}/attempts` or anything in the timetable-generation family and fails specifically on a teacher/student FK — same rule as #1/#2: the actor playing that role becomes a real `TeacherUser`/`StudentUser`.
7. Anything else that fails for a reason NOT covered by rules 1-6 is a genuine new finding, not an anticipated one — do not force-fit it into one of these rules; investigate and fix it on its own terms, and call it out clearly in your report.

**This task is explicitly test-driven on the existing suite, not pre-diffed** — the exact set of broken assertions can only be enumerated by running the suite against everything Tasks 1-17 already changed. Treat "run full suite, read the failures, apply the rule above per failure, re-run" as the step loop, iterating until green. Do not skip a failing test or mark it skipped — every one must either pass or be shown to be testing something now-impossible (e.g., explicitly testing that `users.email` must be unique against a teacher's email, a cross-table check this project's spec explicitly decided NOT to support) and REMOVED with a one-line justification in the commit message, never silently.

- [ ] **Step 1: Run the full suite and capture every failure**

Run: `php artisan test --compact`
Expected: a list of failing tests. Group them by which of the 7 rules above applies to each.

- [ ] **Step 2: Fix each failing file per its matching rule, re-running that one file after each fix**

For each file identified in Step 1, apply the matching rule, then run just that file (e.g. `php artisan test tests/Feature/ExamAttemptControllerTest.php`) before moving to the next file — fixing one file at a time with its own test run keeps a regression from one fix from being mistaken for a pre-existing failure in the next file.

- [ ] **Step 3: Run the full suite again**

Run: `php artisan test --compact`
Expected: every test passes, or every remaining failure has been identified as a genuine removal per rule #7 with its justification ready for the commit message.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/
git commit -m "Fix every existing test broken by the user-table split"
```

(If any test was removed per rule #7, name it and why in this commit's body, not just the summary line.)

---

### Task 19: New tests proving the cross-cutting behaviors actually work

**Files:**
- Create: `tests/Feature/TeacherCreatedContentAuditTest.php`
- Create: `tests/Feature/TeacherDomainFkAssignmentTest.php`

**Interfaces:**
- Consumes: everything.

- [ ] **Step 1: Write the failing audit-attribution test**

Create `tests/Feature/TeacherCreatedContentAuditTest.php` — a `TeacherUser` actor creates a `Question`, and the response records `created_by_type = TeacherUser::class`, not `User::class`. This is the one assertion that actually proves the whole point of making `created_by`/`updated_by` polymorphic — set up the minimal fixtures a `questions.create`-permitted teacher needs (a `Chapter`, matching `QuestionControllerTest`'s own setup pattern — read that file's `setUp()` for the exact fixture shape to reuse, rather than inventing a new one), `actingAs` a `teacherActorWithPermissions(['questions.create'])`, POST to `/api/v1/questions`, and assert:

```php
$response->assertCreated()
    ->assertJsonPath('data.created_by_type', \App\Models\TeacherUser::class)
    ->assertJsonPath('data.created_by_id', $teacher->id);
```

- [ ] **Step 2: Write the failing domain-FK test**

Create `tests/Feature/TeacherDomainFkAssignmentTest.php` — a staff actor creates a `SubjectTeacherAssignment` (or `TimetableEntry`) with `teacher_id` set to a real `TeacherUser`'s id, and the stored row's `teacher()` relation resolves to a `TeacherUser` instance (`$assignment->teacher instanceof \App\Models\TeacherUser`). Reuse `RoutineAssignmentTimetableAuthorizationTest`'s fixture setup pattern (school/class/version/shift/section) rather than inventing new fixtures.

- [ ] **Step 3: Run both**

Run: `php artisan test tests/Feature/TeacherCreatedContentAuditTest.php tests/Feature/TeacherDomainFkAssignmentTest.php`
Expected: PASS (both should already pass if every prior task is correct — these are confirmation tests, not tests driving new implementation).

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/TeacherCreatedContentAuditTest.php tests/Feature/TeacherDomainFkAssignmentTest.php
git commit -m "Add tests proving polymorphic audit columns and teacher domain FKs work end to end"
```

---

### Task 20: Final whole-branch verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: every change from Tasks 1-19.

- [ ] **Step 1: Run the full backend test suite**

Run: `php artisan test --compact`
Expected: every test passes.

- [ ] **Step 2: Run Pint across the whole backend**

Run: `vendor/bin/pint --format agent`
Expected: `{"tool":"pint","result":"passed"}` (every prior task already ran `--dirty`; this is the no-op confirmation). If it reports `fixed` on any file NOT touched by this plan, revert that specific fix the same way the audit-columns project did (`git checkout -- <file>`) to keep this branch's diff scoped to the plan.

- [ ] **Step 3: Confirm no `users`-table row can be a teacher/student/guardian**

Run (from `backend/`):
```bash
php artisan tinker --execute 'echo \App\Models\User::whereIn("role_id", \App\Models\Role::whereIn("name", ["teacher","student","parent_guardian"])->pluck("id"))->count();'
```
Expected: `0`.

- [ ] **Step 4: Confirm every one of the 25 audited resources exposes the new polymorphic keys**

Run: `php artisan test tests/Unit/AuditableTraitCoverageTest.php`
Expected: PASS.

- [ ] **Step 5: Confirm nothing is left uncommitted**

Run: `git status`
Expected: clean working tree.
