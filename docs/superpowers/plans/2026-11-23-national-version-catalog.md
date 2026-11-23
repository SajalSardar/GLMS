# National Version Catalog Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split `class_versions`' free-text version name into a fixed national `versions` catalog (exactly "Bangla"/"English"), while `class_versions` keeps being the district/school/class mapping mechanism it already structurally is.

**Architecture:** New `versions` table (2 rows, seeded at migration time, no create/delete route — a hard-fixed set). `class_versions` drops its `name`/`bn_name` columns and gains `version_id` (FK → `versions`); its unique index becomes `(class_id, school_id, version_id)`. `ClassVersion` keeps exposing `name`/`bn_name` as derived accessors proxied from the linked `Version`, so every downstream FK (`shifts.class_version_id`, `student_profiles.class_version_id`, etc.) and every consumer that only reads `.name`/`.bn_name` is unaffected.

**Tech Stack:** Laravel (PHP 8.5), MySQL (prod) / SQLite (tests), React + TypeScript frontend.

**Spec:** `docs/superpowers/specs/2026-11-23-national-version-catalog-design.md`

## Global Constraints

- `versions` has exactly two rows (Bangla, English), seeded inside the migration's `up()`. No `store`/`destroy` route exists for it.
- `class_versions`'s table/model name does not change. Every FK pointing at `class_versions.id` is untouched.
- `ClassVersion::name`/`ClassVersion::bn_name` stay readable the same way as before (derived accessors) — no consumer outside the admin CRUD surface should need a code change.
- `version_id` is left **nullable at the schema level** (this project has no `doctrine/dbal`, so `Blueprint::change()` to flip an existing column NOT NULL isn't available) — NOT NULL is enforced at the application layer via required validation in `StoreClassVersionRequest`/`UpdateClassVersionRequest`.
- Every model in this app uses the `Auditable` trait (enforced by `AuditableTraitCoverageTest`); `Version` follows suit.
- Run `vendor/bin/pint --dirty --format agent` after any PHP file change, per this project's Laravel Boost guidelines.

---

### Task 1: `versions` table + `Version` model

**Files:**
- Create: `backend/database/migrations/2026_11_23_090000_create_versions_table.php`
- Create: `backend/app/Models/Version.php`
- Modify: `backend/tests/Unit/AuditableTraitCoverageTest.php`
- Test: `backend/tests/Feature/VersionSeedMigrationTest.php`

**Interfaces:**
- Produces: `App\Models\Version` with `fillable: ['name', 'bn_name', 'is_active']`, `casts(): ['is_active' => 'boolean']`, columns `id, created_by_id, created_by_type, updated_by_id, updated_by_type, name, bn_name, is_active, created_at, updated_at`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionSeedMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_two_versions_are_seeded_by_migration(): void
    {
        $this->assertSame(2, Version::count());

        $bangla = Version::where('name', 'Bangla')->first();
        $english = Version::where('name', 'English')->first();

        $this->assertNotNull($bangla);
        $this->assertNotNull($english);
        $this->assertTrue($bangla->is_active);
        $this->assertTrue($english->is_active);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VersionSeedMigrationTest`
Expected: FAIL — `Class "App\Models\Version" not found` (table doesn't exist yet either).

- [ ] **Step 3: Write the migration**

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
     * The national catalog of curriculum versions. Fixed at exactly two
     * rows by design — see docs/superpowers/specs/2026-11-23-national-
     * version-catalog-design.md. Seeded here, not in a seeder, so the
     * fixed-set invariant holds in every environment including a fresh
     * RefreshDatabase test run. created_by_id/created_by_type/
     * updated_by_id/updated_by_type match the polymorphic shape every
     * other audited table in this app ended up with after
     * 2026_11_12_100300_make_created_by_updated_by_polymorphic.php —
     * declared directly here since this table is new, not retrofitted.
     */
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();
            $table->string('name')->unique();
            $table->string('bn_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('versions')->insert([
            ['name' => 'Bangla', 'bn_name' => 'বাংলা', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'English', 'bn_name' => 'ইংরেজি', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('versions');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Version extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'bn_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=VersionSeedMigrationTest`
Expected: PASS

- [ ] **Step 6: Register `Version` with the Auditable drift-guard test**

In `backend/tests/Unit/AuditableTraitCoverageTest.php`:
- Add `use App\Http\Resources\Api\V1\VersionResource;` and `use App\Models\Version;` to the imports (alphabetically: `VersionResource` after `UserResource`; `Version` after `User`).
- Add `[Version::class, VersionResource::class],` to the `$pairs` array in `auditedModels()`, after the `[User::class, UserResource::class],` line.

(This references `VersionResource`, which doesn't exist until Task 4 — this step is written now so Task 4 doesn't forget it, but will only compile/pass once Task 4 lands. Leave this edit staged; do not run the full `AuditableTraitCoverageTest` suite until Task 4 is done, since `test_audited_model_uses_auditable_trait` for `Version` will already pass after this task, but `test_audited_model_resource_exposes_created_by_and_updated_by` for `Version` will fail with a class-not-found error until then.)

Run: `php artisan test --filter=AuditableTraitCoverageTest::test_audited_model_uses_auditable_trait`
Expected: PASS for the whole data set, including the new `Version` row.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_23_090000_create_versions_table.php backend/app/Models/Version.php backend/tests/Feature/VersionSeedMigrationTest.php backend/tests/Unit/AuditableTraitCoverageTest.php
git commit -m "feat: add national versions catalog table and model"
```

---

### Task 2: `class_versions` migration + `ClassVersion` model updates

**Files:**
- Create: `backend/database/migrations/2026_11_23_090100_add_version_id_to_class_versions_table.php`
- Modify: `backend/app/Models/ClassVersion.php`
- Test: `backend/tests/Feature/ClassVersionVersionRelationTest.php`

**Interfaces:**
- Consumes: `App\Models\Version` (Task 1).
- Produces: `ClassVersion::version(): BelongsTo`, `ClassVersion->name` / `ClassVersion->bn_name` (derived, nullable-safe), `class_versions.version_id` column, unique `(class_id, school_id, version_id)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassVersionVersionRelationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchool(): School
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        return School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);
    }

    public function test_name_and_bn_name_proxy_from_the_linked_version(): void
    {
        $school = $this->makeSchool();
        $class = SchoolClass::create(['name' => 'Class Six']);
        $bangla = Version::where('name', 'Bangla')->first();

        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'version_id' => $bangla->id,
        ]);

        $this->assertSame('Bangla', $classVersion->name);
        $this->assertSame($bangla->bn_name, $classVersion->bn_name);
    }

    public function test_name_is_null_without_a_version_id(): void
    {
        $classVersion = new ClassVersion();

        $this->assertNull($classVersion->name);
        $this->assertNull($classVersion->bn_name);
    }

    public function test_duplicate_version_id_for_same_class_and_school_is_rejected(): void
    {
        $school = $this->makeSchool();
        $class = SchoolClass::create(['name' => 'Class Six']);
        $bangla = Version::where('name', 'Bangla')->first();

        ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'version_id' => $bangla->id,
        ]);

        $this->expectException(QueryException::class);

        ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'version_id' => $bangla->id,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ClassVersionVersionRelationTest`
Expected: FAIL — `version_id` isn't a known column / mass-assignment silently drops it, so `$classVersion->name` resolves against the old `name` column (which still exists) rather than the new relation, or fails because `version()` doesn't exist yet.

- [ ] **Step 3: Write the migration**

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
            $table->dropUnique(['class_id', 'school_id', 'name']);
            $table->dropColumn(['name', 'bn_name']);
            $table->unique(['class_id', 'school_id', 'version_id']);
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
            $table->dropUnique(['class_id', 'school_id', 'version_id']);
            $table->string('name')->nullable();
            $table->string('bn_name')->nullable();
        });

        DB::table('class_versions')->update(['name' => 'General']);

        Schema::table('class_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_id');
            $table->unique(['class_id', 'school_id', 'name']);
        });
    }
};
```

- [ ] **Step 4: Update the `ClassVersion` model**

Replace the full contents of `backend/app/Models/ClassVersion.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClassVersion extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'class_id',
        'school_id',
        'district_id',
        'version_id',
        'is_active',
    ];

    /**
     * Every query eager-loads the linked national Version — the table is
     * two rows, so the join cost is negligible, and this is what keeps
     * name()/bnName() below from N+1ing across the many existing call
     * sites that read ->name/->bn_name without having explicitly
     * eager-loaded anything.
     */
    protected $with = ['version'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'version_subjects')
            ->withPivot('required_weekly_periods')
            ->withTimestamps();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * Derived from the linked Version, not a real column — keeps every
     * existing consumer that reads ->name working unchanged now that the
     * version's display name lives in the national versions catalog
     * instead of on this row. Null-safe: an unsaved/incomplete model with
     * no version_id has no linked Version yet.
     */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn () => $this->version?->name);
    }

    protected function bnName(): Attribute
    {
        return Attribute::make(get: fn () => $this->version?->bn_name);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ClassVersionVersionRelationTest`
Expected: PASS

- [ ] **Step 6: Manually verify the backfill against a non-empty table**

This is a one-time manual check, not an automated test — this codebase has no migration-level test suite (confirmed: no existing migration has one), and `RefreshDatabase` always runs every migration against an empty database, so the backfill `UPDATE` statements never touch a populated table during the automated suite.

Run (against a local MySQL database, not SQLite — per this project's documented SQLite/MySQL migration-behavior gap):

```bash
php artisan migrate:rollback --step=1
php artisan tinker --execute 'DB::table("versions")->insert(["name"=>"tmp-check","is_active"=>true,"created_at"=>now(),"updated_at"=>now()]);'
```

Actually simpler — roll back to just before this migration, insert one throwaway `class_versions` row with a free-text `name`, re-run the migration, and confirm `version_id` resolved correctly, then roll everything back to a clean state:

```bash
php artisan tinker --execute '$s = App\Models\School::first(); $c = App\Models\SchoolClass::first(); DB::table("class_versions")->insert(["class_id"=>$c->id,"school_id"=>$s->id,"district_id"=>$s->district_id,"name"=>"English Version","is_active"=>true,"created_at"=>now(),"updated_at"=>now()]);'
php artisan migrate:rollback --step=1
php artisan migrate
php artisan tinker --execute 'echo DB::table("class_versions")->join("versions","versions.id","=","class_versions.version_id")->value("versions.name");'
```

Expected output: `English`. Then reset the local database back to a clean state (`php artisan migrate:fresh`) before moving on — this check is throwaway, not part of the codebase.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/database/migrations/2026_11_23_090100_add_version_id_to_class_versions_table.php backend/app/Models/ClassVersion.php backend/tests/Feature/ClassVersionVersionRelationTest.php
git commit -m "feat: add version_id to class_versions, derive name/bn_name from the linked Version"
```

---

### Task 3: Permissions for the `versions` resource

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Test: `backend/tests/Feature/VersionPermissionMappingTest.php`

**Interfaces:**
- Produces: permissions `versions.view`, `versions.update`, mapped to every role that currently holds `class_versions.view`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_versions_view_is_mapped_to_every_role_holding_class_versions_view(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $rolesWithClassVersionsView = Role::whereHas('permissions', fn ($query) => $query->where('name', 'class_versions.view'))
            ->pluck('name')
            ->sort()
            ->values();

        $rolesWithVersionsView = Role::whereHas('permissions', fn ($query) => $query->where('name', 'versions.view'))
            ->pluck('name')
            ->sort()
            ->values();

        $this->assertEquals($rolesWithClassVersionsView, $rolesWithVersionsView);
    }

    public function test_versions_update_is_national_admin_only(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $roles = Role::whereHas('permissions', fn ($query) => $query->where('name', 'versions.update'))
            ->pluck('name')
            ->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VersionPermissionMappingTest`
Expected: FAIL — `versions.view`/`versions.update` don't exist, so both role lists are empty.

(Check `RoleSeeder` exists at `backend/database/seeders/RoleSeeder.php` and creates all roles including `national_admin`/`district_officer`/etc. before relying on it here — if the seeder file is named differently, use whichever seeder this codebase already uses elsewhere to create base roles in a test, matching the pattern other `*PermissionMappingTest` files in this codebase already use.)

- [ ] **Step 3: Add the permissions**

In `backend/database/seeders/PermissionSeeder.php`, immediately after the `class_versions.*` block:

```php
            ['name' => 'class_versions.view', 'label' => 'View class versions'],
            ['name' => 'class_versions.create', 'label' => 'Create class versions'],
            ['name' => 'class_versions.update', 'label' => 'Update class versions'],
            ['name' => 'class_versions.delete', 'label' => 'Delete class versions'],

            ['name' => 'versions.view', 'label' => 'View versions'],
            ['name' => 'versions.update', 'label' => 'Update versions'],

```

- [ ] **Step 4: Map the permission to roles**

In `backend/database/seeders/RolePermissionSeeder.php`, add `'versions.view',` immediately before every existing `'class_versions.view', 'shifts.view', 'sections.view',` (and its one variant with `'subject_teacher_assignments.view'` appended) occurrence — i.e. every line below becomes:

```php
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view',
```

or

```php
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
```

This applies to the `district_officer`, `school_admin`, `teacher`, `student`, `trainer`, `office_staff`, `parent_guardian`, `it_admin`, and `auditor` role arrays (every array that currently contains `'class_versions.view'`). `national_admin` needs no edit — it already gets every permission via `$allPermissions`.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=VersionPermissionMappingTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add backend/database/seeders/PermissionSeeder.php backend/database/seeders/RolePermissionSeeder.php backend/tests/Feature/VersionPermissionMappingTest.php
git commit -m "feat: add versions.view/versions.update permissions"
```

---

### Task 4: `VersionPolicy`, `VersionResource`, `VersionController`, routes

**Files:**
- Create: `backend/app/Policies/VersionPolicy.php`
- Create: `backend/app/Http/Resources/Api/V1/VersionResource.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateVersionRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/VersionController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/VersionControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\Version` (Task 1).
- Produces: `GET /api/v1/versions`, `GET /api/v1/versions/{version}`, `PATCH /api/v1/versions/{version}` — no `POST`/`DELETE` route registered at all.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class VersionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_and_show_are_public(): void
    {
        $this->getJson('/api/v1/versions')->assertOk()->assertJsonCount(2, 'data');

        $bangla = Version::where('name', 'Bangla')->first();
        $this->getJson("/api/v1/versions/{$bangla->id}")->assertOk()->assertJsonPath('data.name', 'Bangla');
    }

    public function test_store_and_destroy_routes_do_not_exist(): void
    {
        $this->postJson('/api/v1/versions', ['name' => 'Urdu'])->assertNotFound();

        $bangla = Version::where('name', 'Bangla')->first();
        $this->deleteJson("/api/v1/versions/{$bangla->id}")->assertNotFound();
    }

    public function test_update_is_authorized(): void
    {
        $bangla = Version::where('name', 'Bangla')->first();

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/versions/{$bangla->id}", ['bn_name' => 'বাংলা সংস্করণ'])
            ->assertForbidden();

        $allowed = $this->actorWithPermissions(['versions.update']);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/versions/{$bangla->id}", ['bn_name' => 'বাংলা সংস্করণ'])
            ->assertOk()
            ->assertJsonPath('data.bn_name', 'বাংলা সংস্করণ')
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_update_rejects_a_duplicate_name(): void
    {
        $bangla = Version::where('name', 'Bangla')->first();

        $allowed = $this->actorWithPermissions(['versions.update']);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/versions/{$bangla->id}", ['name' => 'English'])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VersionControllerTest`
Expected: FAIL — route `/api/v1/versions` doesn't exist (404 on `test_index_and_show_are_public`).

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\Version;
use Illuminate\Contracts\Auth\Authenticatable;

class VersionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('versions.view');
    }

    public function view(Authenticatable $user, Version $version): bool
    {
        return $user->hasPermission('versions.view');
    }

    public function update(Authenticatable $user, Version $version): bool
    {
        return $user->hasPermission('versions.update');
    }
}
```

- [ ] **Step 4: Write the resource**

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'created_by_type' => $this->created_by_type,
            'created_by_id' => $this->created_by_id,
            'updated_by_type' => $this->updated_by_type,
            'updated_by_id' => $this->updated_by_id,
            'name' => $this->name,
            'bn_name' => $this->bn_name,
            'is_active' => $this->is_active,
        ];
    }
}
```

- [ ] **Step 5: Write the update request**

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('version')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('versions', 'name')->ignore($this->route('version')),
            ],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 6: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateVersionRequest;
use App\Http\Resources\Api\V1\VersionResource;
use App\Models\Version;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VersionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $versions = Version::query()
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return VersionResource::collection($versions);
    }

    public function show(Version $version): VersionResource
    {
        return new VersionResource($version);
    }

    public function update(UpdateVersionRequest $request, Version $version): VersionResource
    {
        $this->authorize('update', $version);

        $version->update($request->validated());

        return new VersionResource($version);
    }
}
```

- [ ] **Step 7: Register the routes**

In `backend/routes/api.php`, add the import (alphabetically, after `UserController`):

```php
use App\Http\Controllers\Api\V1\VersionController;
```

Add to the public group, after the `class-versions` index/show line (around line 106):

```php
    Route::apiResource('versions', VersionController::class)->only(['index', 'show']);
```

Add to the `auth:sanctum` group, after the `class-versions` store/update/destroy + subjects lines (around line 182):

```php
        Route::apiResource('versions', VersionController::class)->only(['update']);
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=VersionControllerTest`
Expected: PASS

- [ ] **Step 9: Run the Auditable coverage test for `Version` end to end**

Run: `php artisan test --filter=AuditableTraitCoverageTest`
Expected: PASS (this closes out Task 1 Step 6's deferred check now that `VersionResource` exists).

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Policies/VersionPolicy.php backend/app/Http/Resources/Api/V1/VersionResource.php backend/app/Http/Requests/Api/V1/UpdateVersionRequest.php backend/app/Http/Controllers/Api/V1/VersionController.php backend/routes/api.php backend/tests/Feature/VersionControllerTest.php
git commit -m "feat: add read-only+update versions API"
```

---

### Task 5: Wire `class_versions` writes to `version_id`

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreClassVersionRequest.php`
- Modify: `backend/app/Http/Requests/Api/V1/UpdateClassVersionRequest.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ClassVersionController.php`
- Modify: `backend/app/Http/Resources/Api/V1/ClassVersionResource.php`
- Test: `backend/tests/Feature/ClassVersionControllerTest.php` (existing — see Task 8 for the full fixture sweep; this task only adds the new validation tests below)

**Interfaces:**
- Consumes: `App\Models\Version` (Task 1), `ClassVersion::version_id` (Task 2).
- Produces: `StoreClassVersionRequest`/`UpdateClassVersionRequest` validating `version_id` instead of `name`/`bn_name`.

- [ ] **Step 1: Write the failing tests**

Read the full current contents of `backend/tests/Feature/ClassVersionControllerTest.php` first — it has existing tests that will be fixed in Task 7, and its `setUp()` may build `$this->school`/`$this->district` differently than `ClassAndVersionAuthorizationTest` does. Follow whatever fixture pattern this file already uses for a school/district/class_schools setup; the snippet below assumes a `$this->school` property matching `ClassAndVersionAuthorizationTest`'s shape (division → district → upazila → school) only as a starting point — adjust to match this file's actual existing `setUp()`.

Add these two new tests to the file:

```php
    public function test_store_rejects_an_unknown_version_id(): void
    {
        $class = SchoolClass::create(['name' => 'Class Six']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $allowed = $this->actorWithPermissions(['class_versions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', [
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'version_id' => 999999,
        ])->assertStatus(422);
    }

    public function test_store_rejects_an_inactive_version_id(): void
    {
        $class = SchoolClass::create(['name' => 'Class Six']);
        ClassSchool::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $inactive = Version::where('name', 'English')->first();
        $inactive->update(['is_active' => false]);

        $allowed = $this->actorWithPermissions(['class_versions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', [
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'version_id' => $inactive->id,
        ])->assertStatus(422);
    }
```

(Check this test file's `setUp()` for a `$this->school` property already matching the shape used in `ClassAndVersionAuthorizationTest`; if the existing `ClassVersionControllerTest` builds its school differently, follow its own existing helper instead of assuming this exact shape. Add `use App\Models\Version;` to its imports if not already present.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ClassVersionControllerTest::test_store_rejects_an_unknown_version_id`
Run: `php artisan test --filter=ClassVersionControllerTest::test_store_rejects_an_inactive_version_id`
Expected: both FAIL — currently `version_id` isn't validated at all (and isn't a request field the controller reads), so the request succeeds with a 201 instead of 422.

- [ ] **Step 3: Update `StoreClassVersionRequest`**

Replace the full contents of `backend/app/Http/Requests/Api/V1/StoreClassVersionRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\School;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreClassVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $school = School::find($this->integer('school_id'));

        return $school !== null && ($this->user()?->can('create', [ClassVersion::class, $school]) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'school_id' => ['required', 'integer', Rule::exists('schools', 'id')->withoutTrashed()],
            'version_id' => [
                'required', 'integer',
                Rule::exists('versions', 'id')->where('is_active', true),
                Rule::unique('class_versions', 'version_id')->withoutTrashed()
                    ->where('class_id', $this->integer('class_id'))
                    ->where('school_id', $this->integer('school_id')),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * class_id/school_id existing individually isn't enough — the school
     * must actually have adopted this class via class_schools before a
     * version can be created under it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['class_id', 'school_id'])) {
                return;
            }

            $exists = ClassSchool::where('class_id', $this->integer('class_id'))
                ->where('school_id', $this->integer('school_id'))
                ->exists();

            if (! $exists) {
                $validator->errors()->add('class_id', 'This school has not adopted this class.');
            }
        });
    }
}
```

- [ ] **Step 4: Update `UpdateClassVersionRequest`**

Replace the full contents of `backend/app/Http/Requests/Api/V1/UpdateClassVersionRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassVersionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('class_version')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * class_id is immutable after creation.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $classVersion = $this->route('class_version');

        return [
            'version_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('versions', 'id')->where('is_active', true),
                Rule::unique('class_versions', 'version_id')->ignore($classVersion)
                    ->where('class_id', $classVersion->class_id)
                    ->where('school_id', $classVersion->school_id),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
```

- [ ] **Step 5: Update `ClassVersionController::store()`**

In `backend/app/Http/Controllers/Api/V1/ClassVersionController.php`, replace the `$attributes` assembly and the soft-delete-restore lookup inside `store()`:

```php
        $attributes = [
            'version_id' => $request->integer('version_id'),
            'is_active' => $request->boolean('is_active', true),
            'class_id' => $request->integer('class_id'),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ];

        $version = ClassVersion::onlyTrashed()
            ->where('class_id', $attributes['class_id'])
            ->where('school_id', $attributes['school_id'])
            ->where('version_id', $attributes['version_id'])
            ->first();
```

Update the method's docblock comment (currently: `A soft-deleted version with the same (class_id, school_id, name) is restored...`) to say `(class_id, school_id, version_id)` instead.

- [ ] **Step 6: Add `version_id` to `ClassVersionResource`**

In `backend/app/Http/Resources/Api/V1/ClassVersionResource.php`, add `'version_id' => $this->version_id,` immediately after the existing `'district_id' => $this->district_id,` line.

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=ClassVersionControllerTest::test_store_rejects_an_unknown_version_id`
Run: `php artisan test --filter=ClassVersionControllerTest::test_store_rejects_an_inactive_version_id`
Expected: both PASS

(Every *other* test in `ClassVersionControllerTest` — and every other file that constructs a `ClassVersion` with a free-text `name` — is expected to fail at this point. That sweep is Task 8/9, not this task. Do not attempt to fix them here.)

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/app/Http/Requests/Api/V1/StoreClassVersionRequest.php backend/app/Http/Requests/Api/V1/UpdateClassVersionRequest.php backend/app/Http/Controllers/Api/V1/ClassVersionController.php backend/app/Http/Resources/Api/V1/ClassVersionResource.php backend/tests/Feature/ClassVersionControllerTest.php
git commit -m "feat: validate class-version writes against the national versions catalog"
```

---

### Task 6: `SectionBackfillSeeder`

**Files:**
- Modify: `backend/database/seeders/SectionBackfillSeeder.php`

**Interfaces:**
- Consumes: `App\Models\Version` (Task 1).

- [ ] **Step 1: Update the seeder**

In `backend/database/seeders/SectionBackfillSeeder.php`:

Add the import:

```php
use App\Models\Version;
```

At the top of `run()`, before the `ClassSchool::with('schoolClass')->get()->each(...)` call, resolve the default version once:

```php
        $bangladVersionId = Version::where('name', 'Bangla')->value('id');

```

(Name it `$bangladVersionId` to avoid shadowing/confusion with the PHP reserved-adjacent word patterns elsewhere in this file — any clear name is fine; this one just avoids `$banglaVersionId` reading awkwardly next to `$bangla`-prefixed locals if any exist elsewhere in the file.)

Replace:

```php
            $version = ClassVersion::firstOrCreate(
                ['class_id' => $class->id, 'school_id' => $schoolId, 'name' => 'General'],
                ['district_id' => $districtId, 'is_active' => true],
            );
```

with:

```php
            $version = ClassVersion::firstOrCreate(
                ['class_id' => $class->id, 'school_id' => $schoolId, 'version_id' => $bangladVersionId],
                ['district_id' => $districtId, 'is_active' => true],
            );
```

- [ ] **Step 2: Verify manually**

This seeder has no dedicated test today (confirmed by searching the test suite for `SectionBackfillSeederTest` — none exists; it's exercised only as part of the full `DatabaseSeeder` chain). Run the full chain locally and confirm it completes without error:

```bash
php artisan migrate:fresh --seed
```

Expected: no errors, exit code 0.

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/SectionBackfillSeeder.php
git commit -m "fix: resolve the national Bangla version in SectionBackfillSeeder"
```

---

### Task 7: Fix the fixtures in `ClassVersionControllerTest` and `ClassAndVersionAuthorizationTest`

**Files:**
- Modify: `backend/tests/Feature/ClassVersionControllerTest.php`
- Modify: `backend/tests/Feature/ClassAndVersionAuthorizationTest.php`

**Interfaces:**
- Consumes: `App\Models\Version` (Task 1).

- [ ] **Step 1: Read both files in full**

Before editing, read the entire current contents of both files — they were only partially quoted during planning, and other tests in them beyond what's shown below may also construct a `ClassVersion` with a free-text `name`.

- [ ] **Step 2: Fix `ClassAndVersionAuthorizationTest`**

Add `use App\Models\Version;` to its imports.

In `test_class_version_store_update_and_update_subjects_are_authorized()`, replace:

```php
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Existing Version']);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['class_id' => $class->id, 'school_id' => $this->school->id, 'name' => 'New Version'];
```

with:

```php
        $bangla = Version::where('name', 'Bangla')->first();
        $english = Version::where('name', 'English')->first();
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => $bangla->id]);
        $subject = Subject::create(['name' => 'Math']);
        $payload = ['class_id' => $class->id, 'school_id' => $this->school->id, 'version_id' => $english->id];
```

(Using `$english` for the create payload is deliberate, not arbitrary: this test creates one `ClassVersion` directly with `version_id = $bangla->id` and then `POST`s a second one for the *same* `(class_id, school_id)` pair. Since the unique index is now `(class_id, school_id, version_id)`, the second one must use a different `version_id` or it will 422 instead of 201.)

In `test_class_version_store_rejects_a_class_school_pair_missing_from_class_schools()`, replace:

```php
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', [
            'class_id' => $unadoptedClass->id,
            'school_id' => $this->school->id,
            'name' => 'New Version',
        ])->assertStatus(422);
```

with:

```php
        $bangla = Version::where('name', 'Bangla')->first();

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-versions', [
            'class_id' => $unadoptedClass->id,
            'school_id' => $this->school->id,
            'version_id' => $bangla->id,
        ])->assertStatus(422);
```

- [ ] **Step 3: Fix `ClassVersionControllerTest`**

For every occurrence in this file of `ClassVersion::create([...])` / `ClassVersion::factory()->create([...])` / `->postJson('/api/v1/class-versions', [...])` that includes a `'name' => '...'` (and optionally `'bn_name' => '...'`) key:

- If the test only ever creates **one** `ClassVersion` for a given `(class_id, school_id)` pair, replace `'name' => '...'` (and drop any `'bn_name' => '...'`) with `'version_id' => Version::where('name', 'Bangla')->value('id')`.
- If the test creates **two** `ClassVersion` rows under the *same* `(class_id, school_id)` pair (e.g. to test listing/pagination/filtering across multiple versions), give the first `Version::where('name', 'Bangla')->value('id')` and the second `Version::where('name', 'English')->value('id')` — preserving the original intent of "two distinct versions exist" instead of colliding on the new unique index.
- Add `use App\Models\Version;` to the file's imports if not already present.

- [ ] **Step 4: Run both files to verify they pass**

Run: `php artisan test --filter=ClassVersionControllerTest`
Run: `php artisan test --filter=ClassAndVersionAuthorizationTest`
Expected: both PASS, no failures.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/tests/Feature/ClassVersionControllerTest.php backend/tests/Feature/ClassAndVersionAuthorizationTest.php
git commit -m "fix: update class-version test fixtures for the version_id catalog"
```

---

### Task 8: Fixture sweep across the remaining test suite

**Files:**
- Modify: every file in the list below (re-derive it fresh with the grep command in Step 1 — the list from brainstorming may be stale by the time this task runs).

**Interfaces:**
- Consumes: `App\Models\Version` (Task 1).

- [ ] **Step 1: Find every remaining fixture that constructs a `ClassVersion` with a free-text name**

```bash
grep -rln "ClassVersion::\(create\|factory\|firstOrCreate\|updateOrCreate\)" backend/tests
```

Exclude `ClassVersionControllerTest.php` and `ClassAndVersionAuthorizationTest.php` — those are done (Task 7). For every remaining file in the result, grep within it for `'name' =>` near a `ClassVersion` construction to confirm it's affected (some files may only reference `class_version_id` as a plain integer FK value and need no change at all — e.g. `$booking->class_version_id = $version->id` — those are already fine and should be skipped).

- [ ] **Step 2: Fix each affected file**

Apply the same two rules as Task 7 Step 3 to each file:
- Single `ClassVersion` per `(class_id, school_id)` pair in that test → `'version_id' => Version::where('name', 'Bangla')->value('id')`, drop `'name'`/`'bn_name'`.
- Multiple `ClassVersion` rows under the same `(class_id, school_id)` pair → alternate Bangla/English `version_id`s.
- Add `use App\Models\Version;` where missing.

Work through the files in batches (e.g. by directory — `tests/Feature` first, then `tests/Unit`), running the full suite after each batch rather than saving every fix for one giant run at the end — this surfaces unique-constraint collisions (Task 7's second rule) immediately, file by file, instead of in one large undifferentiated failure dump.

- [ ] **Step 3: Run the full backend suite**

Run: `php artisan test`
Expected: PASS, zero failures. If any remain, they are either (a) a missed `ClassVersion` fixture — apply the same fix — or (b) a genuine behavior regression introduced by an earlier task, which must be root-caused, not papered over with another fixture tweak. Judge which case applies by reading the actual failure message, not by assuming.

- [ ] **Step 4: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add backend/tests
git commit -m "fix: update remaining ClassVersion test fixtures for the version_id catalog"
```

---

### Task 9: Frontend — `versions` API client + `ClassVersionsPage` picker

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`
- Modify: `frontend/apps/management/src/pages/ClassVersionsPage.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Produces: `Version` type, `listVersions()`, `updateVersion()` in `@ttla/api-client`. `ClassVersion.version_id`, `ClassVersionPayload` swaps `name`/`bn_name` for `version_id`.

- [ ] **Step 1: Add the `Version` type and functions to `api-client`**

In `frontend/packages/api-client/src/index.ts`, immediately before the `// ---------- Class versions ----------` section, add:

```ts
// ---------- Versions ----------

export interface Version {
  id: number
  name: string
  bn_name: string | null
  is_active: boolean
}

export interface VersionPayload {
  name?: string
  bn_name?: string | null
  is_active?: boolean
}

export function listVersions(
  params: { page?: number; perPage?: number; search?: string } = {},
): Promise<PaginatedResponse<Version>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    search: params.search || undefined,
  }

  return apiFetch<PaginatedResponse<Version>>(`/versions${buildQuery(query)}`)
}

export function updateVersion(id: number, payload: VersionPayload, token: string): Promise<{ data: Version }> {
  return updateGeo<Version, VersionPayload>('versions', id, payload, token)
}
```

- [ ] **Step 2: Update the `ClassVersion` type and payload**

Replace the existing `ClassVersion` interface and `ClassVersionPayload` interface (in the `// ---------- Class versions ----------` section) with:

```ts
export interface ClassVersion {
  id: number
  class_id: number
  school_id: number
  district_id: number
  version_id: number
  name: string
  bn_name: string | null
  is_active: boolean
  class?: SchoolClass | null
  subjects?: Subject[] | null
}

export interface ClassVersionPayload {
  class_id: number
  school_id: number
  version_id: number
  is_active?: boolean
}
```

(`name`/`bn_name` stay on the *read* type — the API still returns them, derived server-side — but drop from the *write* payload type, since the server no longer accepts them.)

- [ ] **Step 3: Update `ClassVersionsPage.tsx`**

Add `Version` and `listVersions` to the import from `@ttla/api-client`.

Replace `initialValues`:

```ts
const initialValues = {
  school_id: '',
  class_id: '',
  version_id: '',
  is_active: 'true',
}
```

Replace `requiredFields`:

```ts
const requiredFields = ['school_id', 'class_id', 'version_id']
```

Replace `toPayload`:

```ts
function toPayload(values: Record<string, string>) {
  return {
    class_id: Number(values.class_id),
    school_id: Number(values.school_id),
    version_id: Number(values.version_id),
    is_active: values.is_active === 'true',
  }
}
```

In `ClassVersionDetails`, no change needed — it already reads `version.name`/`version.bn_name`, which stay valid.

In the `columns` array, no change needed — same reasoning.

Add a `versions` state and fetch it once, alongside the existing `allClasses`/`schools` effect:

```ts
  const [versions, setVersions] = useState<Version[]>([])
```

and inside the existing `useEffect` that fetches `allClasses`/`schools`:

```ts
    listVersions({ perPage: 10 })
      .then((result) => setVersions(result.data))
      .catch(() => setVersions([]))
```

In `valuesFromRow`, replace:

```ts
          name: row.name,
          bn_name: row.bn_name ?? '',
```

with:

```ts
          version_id: String(row.version_id),
```

In `renderFields`, replace the `name`/`bn_name` `GeoField` blocks:

```tsx
            <GeoField label={t['management.fields.name']} required error={fieldErrors.name}>
              <input
                className={inputClass}
                value={values.name}
                onChange={(event) => onChange('name', event.target.value)}
              />
            </GeoField>

            <GeoField label={t['management.fields.bnName']} error={fieldErrors.bn_name}>
              <input
                className={inputClass}
                value={values.bn_name}
                onChange={(event) => onChange('bn_name', event.target.value)}
              />
            </GeoField>
```

with a picker over the two fetched versions:

```tsx
            <GeoField label={t['management.fields.version']} required error={fieldErrors.version_id}>
              <SearchableSelect
                value={values.version_id}
                onChange={(next) => onChange('version_id', next)}
                placeholder={t['management.fields.version']}
                searchPlaceholder={t['management.table.search']}
                noMatchesLabel={t['management.table.noMatches']}
                options={versions.map((version) => ({ value: String(version.id), label: version.name }))}
              />
            </GeoField>
```

- [ ] **Step 4: Add the `management.fields.version` i18n key**

In `frontend/packages/i18n/src/common/en.json`, add immediately after the existing `"management.fields.bnName": "Name (Bangla)",` line:

```json
  "management.fields.version": "Version",
```

In `frontend/packages/i18n/src/common/bn.json`, add immediately after the existing `"management.fields.bnName": "নাম (বাংলা)",` line:

```json
  "management.fields.version": "ভার্সন",
```

- [ ] **Step 5: Manually verify in the browser**

Run: `npm run dev` (or whatever this project's documented dev-server command is — check `package.json`/root `CLAUDE.md` if `npm run dev` isn't it) from `frontend/apps/management`, open the Class Versions page, and confirm:
- The create form shows a Bangla/English picker, not free-text inputs.
- Creating a class-version succeeds and the table/detail view still show the version's name correctly.
- Editing an existing row pre-fills the picker with its current version.

- [ ] **Step 6: Commit**

```bash
git add frontend/packages/api-client/src/index.ts frontend/apps/management/src/pages/ClassVersionsPage.tsx frontend/packages/i18n/src/common/en.json frontend/packages/i18n/src/common/bn.json
git commit -m "feat: use the Bangla/English version picker on the Class Versions page"
```

---

### Task 10: Full audit pass + final verification

**Files:**
- Potentially any file not already touched by Tasks 1-9 — this task's job is to find out which, if any.

**Interfaces:**
- None new. This task only verifies.

- [ ] **Step 1: Re-list every file referencing "version"**

```bash
grep -rli "version" backend/app backend/routes backend/database --include=*.php
grep -rli "version" frontend/apps frontend/packages --include=*.tsx --include=*.ts
```

- [ ] **Step 2: Classify every result**

For each file in both lists, confirm it falls into exactly one of:
1. Only uses `class_version_id` (or `classVersionId`/`class_version`) as an opaque FK/value — no change needed.
2. Only reads `.name`/`.bn_name` off a class-version object — no change needed (derived server-side now, same shape).
3. Already touched by Tasks 1-9 (the `versions`/`class-versions` admin surface, `SectionBackfillSeeder`, the test fixture sweep, `ClassVersionsPage.tsx`, `api-client`).

Anything that doesn't fit one of these three — for example, any remaining seeder, factory, or demo-data script that constructs a `ClassVersion` with a `name`/`bn_name` key outside the test suite (checked in Tasks 1-9, but re-confirm here since `database/seeders` wasn't exhaustively walked file-by-file earlier) — gets fixed now, following the same `version_id` substitution pattern used throughout this plan.

- [ ] **Step 3: Run the full backend suite against MySQL**

Per this project's documented SQLite/MySQL migration-behavior gap, run the full suite against a real MySQL database, not just SQLite. `backend/phpunit.xml` hardcodes `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` for every test run (confirmed — there is no separate MySQL testing config checked into this repo), so this requires a temporary local edit:

1. In `backend/phpunit.xml`, temporarily change:
   ```xml
   <env name="DB_CONNECTION" value="sqlite"/>
   <env name="DB_DATABASE" value=":memory:"/>
   ```
   to point at a local MySQL database instead, e.g.:
   ```xml
   <env name="DB_CONNECTION" value="mysql"/>
   <env name="DB_DATABASE" value="ttla_testing"/>
   <env name="DB_HOST" value="127.0.0.1"/>
   <env name="DB_USERNAME" value="root"/>
   <env name="DB_PASSWORD" value=""/>
   ```
   (adjust host/username/password to whatever this local environment's MySQL actually needs — Laragon's default MySQL is typically `root` with no password on `127.0.0.1`).
2. Create the database if it doesn't exist: `mysql -u root -e "CREATE DATABASE IF NOT EXISTS ttla_testing"`.
3. Run: `php artisan test`
4. **Revert `backend/phpunit.xml` back to the original `sqlite`/`:memory:` values before committing anything** — this change is a local, throwaway verification step, never a committed change.

Expected: PASS, zero failures.

- [ ] **Step 4: Report findings**

Summarize, in the final task report, every file that needed a fix beyond Tasks 1-9 and why — this is the artifact that proves the audit was actually done, not assumed.
