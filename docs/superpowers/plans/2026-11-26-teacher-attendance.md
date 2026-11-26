# Teacher Attendance (Device Check-In) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a physical attendance device (ID card or face match, device-agnostic) record teacher check-ins, let `school_admin`/`national_admin` monitor and print a monthly attendance report per school, and let a teacher see their own attendance in the teacher portal.

**Architecture:** Three new backend tables (`kiosk_devices`, `teacher_attendances`, `attendance_punches`) plus a token-authenticated, unauthenticated-to-Sanctum ingestion endpoint (`POST /attendance-punches`) that any device/bridge can call. Status (present/absent/leave/holiday) is derived the same way the existing student `Attendance` system already derives it — stored only for days with an actual punch or an admin correction, synthesized at read time otherwise. Frontend: one new admin page to provision/revoke device tokens, one new admin report page mirroring the existing student `AttendanceReportPage`, and one new teacher-portal self-view page.

**Tech Stack:** Laravel 13 / PHP 8.5 / Sanctum 4 (backend), React + TypeScript + Tailwind + Vite (frontend), PHPUnit (backend tests). No new dependencies.

**Spec:** `docs/superpowers/specs/2026-11-26-teacher-attendance-design.md` — read it alongside this plan; this plan is the spec's concrete build order, not a replacement for it.

## Global Constraints

- Follow this codebase's existing conventions exactly: every new Eloquent model that is an admin-editable resource uses `Auditable, SoftDeletes, TenantScoped`; every new table using `Auditable` has BOTH `nullableMorphs('created_by')` and `nullableMorphs('updated_by')` (the trait's `saving` hook unconditionally tries to set both — omitting either column breaks every save).
- No route-level `permission:` middleware anywhere in this app — every authorization decision is a Policy call (`$this->authorize(...)` or a FormRequest's own `authorize()`), exactly as every existing controller already does.
- New permissions are added to `database/seeders/PermissionSeeder.php` and granted to `school_admin` explicitly in `database/seeders/RolePermissionSeeder.php` (`national_admin` already holds every permission via its all-permissions wildcard — never add it explicitly).
- PHP: curly braces always, constructor property promotion, explicit return types/type hints, PHPDoc over inline comments except for genuinely non-obvious logic.
- After any PHP file change, run `vendor/bin/pint --dirty --format agent` from `backend/` before considering a task done.
- Frontend has no component-level test runner wired up for pages in this app (confirmed: the only `*.test.ts` in the whole frontend is `packages/i18n/src/index.test.ts`) — frontend tasks are verified by `npx tsc --noEmit -p tsconfig.json`, `npm run build`, and a manual browser check, not by writing new test files. Do not invent a test file a page has no sibling convention for.
- Every new i18n key is added to BOTH `frontend/packages/i18n/src/common/en.json` and `frontend/packages/i18n/src/common/bn.json` — `CommonStrings = typeof en` is enforced at the TypeScript level, so a key present in only one locale is a type error.
- Backend test actors are built with `tests/Concerns/CreatesAuthorizedActors.php`'s `actorWithPermissions([...], $schoolId, $districtId)` / `teacherActorWithPermissions([...], $schoolId, $districtId)` — never hand-roll a Role/Permission in a test.

---

## Task 1: Migrations & Models

**Files:**
- Create: `backend/database/migrations/2026_11_26_090000_create_kiosk_devices_table.php`
- Create: `backend/database/migrations/2026_11_26_090100_create_teacher_attendances_table.php`
- Create: `backend/database/migrations/2026_11_26_090200_create_attendance_punches_table.php`
- Create: `backend/app/Models/KioskDevice.php`
- Create: `backend/app/Models/TeacherAttendance.php`
- Create: `backend/app/Models/AttendancePunch.php`
- Test: `backend/tests/Feature/TeacherAttendanceDataModelTest.php`

**Interfaces:**
- Produces: `KioskDevice` (fields: `id, school_id, name, token, is_active, last_used_at`; relation `school(): BelongsTo`), `TeacherAttendance` (fields: `id, teacher_profile_id, school_id, district_id, date, status, check_in_at, check_out_at, corrected_by_id, correction_note`; `STATUSES = ['present','absent','leave']`; relations `teacherProfile()`, `school()`, `district()`, `correctedBy()`), `AttendancePunch` (fields: `id, teacher_profile_id, kiosk_device_id, method, punched_at`; relations `teacherProfile()`, `kioskDevice()`). All later tasks build on these three models' exact field/relation names above.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\District;
use App\Models\Division;
use App\Models\KioskDevice;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAttendanceDataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_device_teacher_attendance_and_punch_rows_can_be_created_with_their_relations(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $teacherUser = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $district->id]);
        $profile = TeacherProfile::create([
            'user_id' => $teacherUser->id,
            'school_id' => $school->id,
            'district_id' => $district->id,
            'employee_id' => 'EMP-0001',
        ]);

        $device = KioskDevice::create([
            'school_id' => $school->id,
            'name' => 'Front Gate',
            'token' => hash('sha256', 'plain-token'),
            'is_active' => true,
        ]);

        $attendance = TeacherAttendance::create([
            'teacher_profile_id' => $profile->id,
            'school_id' => $school->id,
            'district_id' => $district->id,
            'date' => '2026-12-01',
            'status' => 'present',
            'check_in_at' => '2026-12-01 08:55:00',
            'check_out_at' => '2026-12-01 16:05:00',
        ]);

        $punch = AttendancePunch::create([
            'teacher_profile_id' => $profile->id,
            'kiosk_device_id' => $device->id,
            'method' => 'id_card',
            'punched_at' => '2026-12-01 08:55:00',
        ]);

        $this->assertTrue($device->school->is($school));
        $this->assertTrue($attendance->teacherProfile->is($profile));
        $this->assertSame('2026-12-01', $attendance->date->toDateString());
        $this->assertTrue($punch->teacherProfile->is($profile));
        $this->assertTrue($punch->kioskDevice->is($device));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (from `backend/`): `php artisan test --filter=TeacherAttendanceDataModelTest --compact`
Expected: FAIL — `Class "App\Models\KioskDevice" not found` (or migration table missing).

- [ ] **Step 3: Write the three migrations**

`database/migrations/2026_11_26_090000_create_kiosk_devices_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A provisioned physical attendance device (kiosk). `token` stores
     * only a SHA-256 hash of the device's actual token — the plaintext is
     * generated once at creation and returned exactly once in that
     * response (see KioskDeviceController::store()), never persisted or
     * shown again. `is_active` is a separate toggle from soft-delete: a
     * school can pause a device without losing its history.
     */
    public function up(): void
    {
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('created_by');
            $table->nullableMorphs('updated_by');

            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
```

`database/migrations/2026_11_26_090100_create_teacher_attendances_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per teacher per day, mirroring attendances' shape for
     * students. `status` is present|absent|leave — never 'holiday'; a
     * holiday date is computed at read time from the Holiday calendar,
     * exactly like Attendance already does, and is never written here.
     * check_in_at/check_out_at are the earliest/latest punch of the day
     * (see AttendancePunchController::store()); corrected_by_id/
     * correction_note are set only when a human overrides a day via
     * TeacherAttendanceController::correct() — distinguishing "the device
     * recorded this" from "a human overrode it."
     */
    public function up(): void
    {
        Schema::create('teacher_attendances', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('created_by');
            $table->nullableMorphs('updated_by');

            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->constrained()->cascadeOnDelete();

            $table->date('date');
            $table->string('status');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();

            $table->foreignId('corrected_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('correction_note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['teacher_profile_id', 'date']);
            $table->index(['school_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_attendances');
    }
};
```

`database/migrations/2026_11_26_090200_create_attendance_punches_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable raw device log — one row per punch, never summarized away,
     * so there is always a full audit trail of every scan. No SoftDeletes/
     * Auditable/TenantScoped: this is a device log, not an admin-editable
     * resource, and school_id is reachable via teacher_profile_id when
     * needed rather than denormalized here.
     */
    public function up(): void
    {
        Schema::create('attendance_punches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kiosk_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method');
            $table->timestamp('punched_at');

            $table->timestamps();
            $table->index(['teacher_profile_id', 'punched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_punches');
    }
};
```

- [ ] **Step 4: Write the three models**

`app/Models/KioskDevice.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KioskDevice extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'school_id',
        'name',
        'token',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
```

`app/Models/TeacherAttendance.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherAttendance extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    public const STATUSES = ['present', 'absent', 'leave'];

    protected $fillable = [
        'teacher_profile_id',
        'school_id',
        'district_id',
        'date',
        'status',
        'check_in_at',
        'check_out_at',
        'corrected_by_id',
        'correction_note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
        ];
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_id');
    }
}
```

`app/Models/AttendancePunch.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePunch extends Model
{
    protected $fillable = [
        'teacher_profile_id',
        'kiosk_device_id',
        'method',
        'punched_at',
    ];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
        ];
    }

    public function teacherProfile(): BelongsTo
    {
        return $this->belongsTo(TeacherProfile::class);
    }

    public function kioskDevice(): BelongsTo
    {
        return $this->belongsTo(KioskDevice::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=TeacherAttendanceDataModelTest --compact`
Expected: PASS

- [ ] **Step 6: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_26_090000_create_kiosk_devices_table.php \
        database/migrations/2026_11_26_090100_create_teacher_attendances_table.php \
        database/migrations/2026_11_26_090200_create_attendance_punches_table.php \
        app/Models/KioskDevice.php app/Models/TeacherAttendance.php app/Models/AttendancePunch.php \
        tests/Feature/TeacherAttendanceDataModelTest.php
git commit -m "Add kiosk_devices, teacher_attendances, attendance_punches tables and models"
```

---

## Task 2: Permissions

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Test: `backend/tests/Feature/TeacherAttendancePermissionMappingTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: permissions `kiosk_devices.view`, `kiosk_devices.create`, `kiosk_devices.update`, `kiosk_devices.delete`, `teacher_attendances.view`, `teacher_attendances.update` — every later task's Policy/FormRequest checks these exact strings.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherAttendancePermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list every new permission this feature adds is
     * mapped to — school_admin explicitly in RolePermissionSeeder;
     * national_admin via its all-permissions wildcard.
     */
    public function test_teacher_attendance_and_kiosk_device_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = ['national_admin', 'school_admin'];

        $permissions = [
            'teacher_attendances.view',
            'teacher_attendances.update',
            'kiosk_devices.view',
            'kiosk_devices.create',
            'kiosk_devices.update',
            'kiosk_devices.delete',
        ];

        foreach ($permissions as $permissionName) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            $this->assertSame($expected, $roles, "Expected {$permissionName} to be mapped to exactly national_admin and school_admin.");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TeacherAttendancePermissionMappingTest --compact`
Expected: FAIL — the permissions don't exist yet, so every role list comes back empty.

- [ ] **Step 3: Add the permissions**

In `database/seeders/PermissionSeeder.php`, add to the end of the `$permissions` array (right before its closing `];`):

```php
            // A physical attendance device identifies a teacher (ID card or
            // its own built-in face match) and reports the result here —
            // kiosk_devices.* governs provisioning/revoking those devices;
            // teacher_attendances.* governs viewing the resulting report and
            // manually correcting a day.
            ['name' => 'kiosk_devices.view', 'label' => 'View kiosk devices'],
            ['name' => 'kiosk_devices.create', 'label' => 'Provision a kiosk device'],
            ['name' => 'kiosk_devices.update', 'label' => 'Rename or activate/deactivate a kiosk device'],
            ['name' => 'kiosk_devices.delete', 'label' => 'Revoke a kiosk device'],

            ['name' => 'teacher_attendances.view', 'label' => "View teachers' attendance report"],
            ['name' => 'teacher_attendances.update', 'label' => "Manually correct a teacher's attendance for a day"],
```

In `database/seeders/RolePermissionSeeder.php`, add to the end of the `'school_admin' => [...]` array (right before its closing `],`):

```php
                'kiosk_devices.view', 'kiosk_devices.create', 'kiosk_devices.update', 'kiosk_devices.delete',
                'teacher_attendances.view', 'teacher_attendances.update',
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TeacherAttendancePermissionMappingTest --compact`
Expected: PASS

- [ ] **Step 5: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php tests/Feature/TeacherAttendancePermissionMappingTest.php
git commit -m "Add kiosk_devices.* and teacher_attendances.* permissions"
```

---

## Task 3: Kiosk Device CRUD (admin-facing)

**Files:**
- Create: `backend/app/Policies/KioskDevicePolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreKioskDeviceRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateKioskDeviceRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/KioskDeviceResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/KioskDeviceController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/KioskDeviceControllerTest.php`

**Interfaces:**
- Consumes: `KioskDevice` model (Task 1), `kiosk_devices.*` permissions (Task 2).
- Produces: `GET/POST /api/v1/kiosk-devices`, `PATCH/DELETE /api/v1/kiosk-devices/{kiosk_device}`. `KioskDeviceResource::toArray()` fields: `id, school_id, school_name, name, is_active, last_used_at, created_at` — note it NEVER includes `token`; only `store()`'s raw response embeds the one-time plaintext `token`. Later tasks (and the frontend) must never expect `token` on `index`/`update` responses.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\KioskDevice;
use App\Models\School;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class KioskDeviceControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);
    }

    public function test_school_admin_creates_a_device_scoped_to_their_own_school_and_receives_the_token_once(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.view', 'kiosk_devices.create'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate'])
            ->assertCreated();

        $response->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.name', 'Front Gate')
            ->assertJsonPath('data.is_active', true);

        $plainToken = $response->json('data.token');
        $this->assertNotEmpty($plainToken);

        $this->assertDatabaseHas('kiosk_devices', [
            'school_id' => $this->schoolA->id,
            'name' => 'Front Gate',
            'token' => hash('sha256', $plainToken),
        ]);
    }

    public function test_national_admin_must_specify_a_school_when_creating_a_device(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['kiosk_devices.view', 'kiosk_devices.create']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('school_id');

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate', 'school_id' => $this->schoolA->id])
            ->assertCreated()
            ->assertJsonPath('data.school_id', $this->schoolA->id);
    }

    public function test_store_requires_a_name(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_store_is_forbidden_without_the_create_permission(): void
    {
        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->postJson('/api/v1/kiosk-devices', ['name' => 'Front Gate'])
            ->assertForbidden();
    }

    public function test_school_admin_sees_only_their_own_schools_devices(): void
    {
        KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'A Gate', 'token' => hash('sha256', 'a'), 'is_active' => true]);
        KioskDevice::create(['school_id' => $this->schoolB->id, 'name' => 'B Gate', 'token' => hash('sha256', 'b'), 'is_active' => true]);

        $schoolAdminA = $this->actorWithPermissions(['kiosk_devices.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->getJson('/api/v1/kiosk-devices')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'A Gate');
    }

    public function test_national_admin_sees_every_schools_devices(): void
    {
        KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'A Gate', 'token' => hash('sha256', 'a'), 'is_active' => true]);
        KioskDevice::create(['school_id' => $this->schoolB->id, 'name' => 'B Gate', 'token' => hash('sha256', 'b'), 'is_active' => true]);

        $nationalAdmin = $this->actorWithPermissions(['kiosk_devices.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/kiosk-devices')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/kiosk-devices')
            ->assertForbidden();
    }

    public function test_school_admin_can_rename_and_deactivate_their_own_device(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/kiosk-devices/{$device->id}", ['name' => 'New Name', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_school_admin_cannot_update_another_schools_device(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolB->id, 'name' => 'B Gate', 'token' => hash('sha256', 'b'), 'is_active' => true]);

        $schoolAdminA = $this->actorWithPermissions(['kiosk_devices.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->patchJson("/api/v1/kiosk-devices/{$device->id}", ['name' => 'Hijacked'])
            ->assertNotFound();
    }

    public function test_update_is_forbidden_without_the_update_permission(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/kiosk-devices/{$device->id}", ['name' => 'New Name'])
            ->assertForbidden();
    }

    public function test_school_admin_can_delete_their_own_device(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions(['kiosk_devices.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->deleteJson("/api/v1/kiosk-devices/{$device->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('kiosk_devices', ['id' => $device->id]);
    }

    public function test_destroy_is_forbidden_without_the_delete_permission(): void
    {
        $device = KioskDevice::create(['school_id' => $this->schoolA->id, 'name' => 'Old Name', 'token' => hash('sha256', 'a'), 'is_active' => true]);

        $schoolAdmin = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->deleteJson("/api/v1/kiosk-devices/{$device->id}")
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=KioskDeviceControllerTest --compact`
Expected: FAIL — route not defined / classes not found.

- [ ] **Step 3: Write the Policy**

`app/Policies/KioskDevicePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\KioskDevice;
use Illuminate\Contracts\Auth\Authenticatable;

class KioskDevicePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('kiosk_devices.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('kiosk_devices.create');
    }

    public function update(Authenticatable $user, KioskDevice $device): bool
    {
        return $user->hasPermission('kiosk_devices.update');
    }

    public function delete(Authenticatable $user, KioskDevice $device): bool
    {
        return $user->hasPermission('kiosk_devices.delete');
    }
}
```

(No explicit registration needed — Laravel auto-discovers `App\Policies\KioskDevicePolicy` for `App\Models\KioskDevice`, same as every other policy in this app except the one documented exception in `AppServiceProvider`.)

- [ ] **Step 4: Write the FormRequests**

`app/Http/Requests/Api/V1/StoreKioskDeviceRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\KioskDevice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreKioskDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', KioskDevice::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actor = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            // A school-scoped actor (school_admin) always gets their own
            // school_id in the controller — this field is only meaningful
            // for an unscoped actor (national_admin) provisioning a device
            // for a specific school, so it's required exactly when the
            // actor has none of their own.
            'school_id' => [
                $actor?->school_id === null ? 'required' : 'nullable',
                'integer',
                Rule::exists('schools', 'id'),
            ],
        ];
    }
}
```

`app/Http/Requests/Api/V1/UpdateKioskDeviceRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateKioskDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('kiosk_device')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

- [ ] **Step 5: Write the Resource**

`app/Http/Resources/Api/V1/KioskDeviceResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KioskDeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_id' => $this->school_id,
            'school_name' => $this->whenLoaded('school', fn () => $this->school?->name),
            'name' => $this->name,
            'is_active' => $this->is_active,
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // Deliberately no `token` field — the plaintext is only ever
            // returned once, inline, by store()'s own raw response.
        ];
    }
}
```

- [ ] **Step 6: Write the Controller**

`app/Http/Controllers/Api/V1/KioskDeviceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreKioskDeviceRequest;
use App\Http\Requests\Api\V1\UpdateKioskDeviceRequest;
use App\Http\Resources\Api\V1\KioskDeviceResource;
use App\Models\KioskDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class KioskDeviceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', KioskDevice::class);

        $devices = KioskDevice::query()->with('school')->orderBy('name')->paginate(15);

        return KioskDeviceResource::collection($devices);
    }

    /**
     * The plaintext token is generated here and returned exactly once —
     * only its SHA-256 hash is ever stored, so this response is the only
     * place it is ever visible again.
     */
    public function store(StoreKioskDeviceRequest $request): JsonResponse
    {
        $actor = $request->user();
        $plainToken = Str::random(48);

        $device = KioskDevice::create([
            'school_id' => $actor->school_id ?? $request->integer('school_id'),
            'name' => $request->string('name')->toString(),
            'token' => hash('sha256', $plainToken),
            'is_active' => true,
        ]);

        return response()->json([
            'data' => [
                ...(new KioskDeviceResource($device->load('school')))->resolve(),
                'token' => $plainToken,
            ],
        ], 201);
    }

    public function update(UpdateKioskDeviceRequest $request, KioskDevice $kiosk_device): KioskDeviceResource
    {
        $kiosk_device->update($request->only(['name', 'is_active']));

        return new KioskDeviceResource($kiosk_device->load('school'));
    }

    public function destroy(KioskDevice $kiosk_device): Response
    {
        $this->authorize('delete', $kiosk_device);

        $kiosk_device->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 7: Register routes**

In `routes/api.php`: add the import `use App\Http\Controllers\Api\V1\KioskDeviceController;` alongside the other `use` statements (alphabetical order, after `HolidayController`), and inside the existing `Route::middleware('auth:sanctum')->group(function (): void { ... })` block, add (near the `teacher-leave-applications` routes):

```php
        Route::apiResource('kiosk-devices', KioskDeviceController::class)->only(['index', 'store', 'update', 'destroy']);
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=KioskDeviceControllerTest --compact`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add app/Policies/KioskDevicePolicy.php \
        app/Http/Requests/Api/V1/StoreKioskDeviceRequest.php app/Http/Requests/Api/V1/UpdateKioskDeviceRequest.php \
        app/Http/Resources/Api/V1/KioskDeviceResource.php app/Http/Controllers/Api/V1/KioskDeviceController.php \
        routes/api.php tests/Feature/KioskDeviceControllerTest.php
git commit -m "Add kiosk device provisioning CRUD (admin-facing)"
```

---

## Task 4: Kiosk Punch Ingestion (device-facing)

**Files:**
- Create: `backend/app/Http/Middleware/AuthenticateKioskDevice.php`
- Modify: `backend/bootstrap/app.php`
- Create: `backend/app/Http/Requests/Api/V1/RecordAttendancePunchRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/AttendancePunchController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/AttendancePunchControllerTest.php`

**Interfaces:**
- Consumes: `KioskDevice`, `TeacherAttendance`, `AttendancePunch` models (Task 1), `TeacherProfile.employee_id` (existing).
- Produces: `POST /api/v1/attendance-punches` (header `X-Kiosk-Token`, body `{identifier, method, captured_at?}`) — the `kiosk.auth` middleware alias, reusable verbatim by the later student-attendance sub-project.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\KioskDevice;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendancePunchControllerTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private KioskDevice $deviceA;

    private string $deviceAToken = 'plain-token-a';

    private TeacherProfile $profileA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $this->schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $this->deviceA = KioskDevice::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Front Gate',
            'token' => hash('sha256', $this->deviceAToken),
            'is_active' => true,
        ]);

        $teacherA = TeacherUser::factory()->create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $this->profileA = TeacherProfile::create([
            'user_id' => $teacherA->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'employee_id' => 'EMP-A1',
        ]);
    }

    public function test_a_punch_with_no_token_is_rejected(): void
    {
        $this->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-A1', 'method' => 'id_card'])
            ->assertUnauthorized();
    }

    public function test_a_punch_with_an_invalid_token_is_rejected(): void
    {
        $this->withHeaders(['X-Kiosk-Token' => 'wrong-token'])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-A1', 'method' => 'id_card'])
            ->assertUnauthorized();
    }

    public function test_a_punch_from_an_inactive_device_is_rejected(): void
    {
        $this->deviceA->update(['is_active' => false]);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-A1', 'method' => 'id_card'])
            ->assertUnauthorized();
    }

    public function test_a_valid_punch_creates_a_present_record_and_touches_last_used_at(): void
    {
        $this->assertNull($this->deviceA->last_used_at);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1',
                'method' => 'id_card',
                'captured_at' => '2026-12-01T08:55:00+00:00',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('attendance_punches', [
            'teacher_profile_id' => $this->profileA->id,
            'kiosk_device_id' => $this->deviceA->id,
            'method' => 'id_card',
        ]);

        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_profile_id' => $this->profileA->id,
            'date' => '2026-12-01',
            'status' => 'present',
        ]);

        $this->assertNotNull($this->deviceA->fresh()->last_used_at);
    }

    public function test_a_second_punch_the_same_day_sets_check_out_without_changing_check_in(): void
    {
        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1', 'method' => 'id_card', 'captured_at' => '2026-12-01T08:55:00+00:00',
            ])->assertCreated();

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1', 'method' => 'face', 'captured_at' => '2026-12-01T16:05:00+00:00',
            ])->assertCreated();

        $attendance = TeacherAttendance::where('teacher_profile_id', $this->profileA->id)->where('date', '2026-12-01')->sole();

        $this->assertSame('2026-12-01T08:55:00+00:00', $attendance->check_in_at->toIso8601String());
        $this->assertSame('2026-12-01T16:05:00+00:00', $attendance->check_out_at->toIso8601String());
        $this->assertDatabaseCount('attendance_punches', 2);
    }

    public function test_an_unrecognized_identifier_is_rejected_with_404(): void
    {
        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'NOPE', 'method' => 'id_card'])
            ->assertNotFound();
    }

    /**
     * A device cannot resolve a teacher from a different school, even if
     * the employee_id happens to collide — the lookup is always scoped by
     * the authenticated device's own school_id, never request input.
     */
    public function test_a_devices_token_cannot_resolve_a_teacher_from_a_different_school(): void
    {
        $teacherB = TeacherUser::factory()->create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id]);
        TeacherProfile::create([
            'user_id' => $teacherB->id,
            'school_id' => $this->schoolB->id,
            'district_id' => $this->schoolB->district_id,
            'employee_id' => 'EMP-A1', // deliberately colliding with School A's employee_id
        ]);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', ['identifier' => 'EMP-A1', 'method' => 'id_card'])
            ->assertCreated()
            ->assertJsonPath('data.name', $this->profileA->user->name);
    }

    /**
     * A punch is hard evidence of actual attendance and always wins over a
     * leave record — explicit test since this is the one place a future
     * maintainer might assume leave should take precedence.
     */
    public function test_a_punch_still_records_present_even_when_the_teacher_is_on_approved_leave(): void
    {
        TeacherLeaveApplication::create([
            'teacher_id' => $this->profileA->user_id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'reason' => 'Sick',
            'status' => 'approved',
        ]);

        $this->withHeaders(['X-Kiosk-Token' => $this->deviceAToken])
            ->postJson('/api/v1/attendance-punches', [
                'identifier' => 'EMP-A1', 'method' => 'id_card', 'captured_at' => '2026-12-01T08:55:00+00:00',
            ])->assertCreated();

        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_profile_id' => $this->profileA->id,
            'date' => '2026-12-01',
            'status' => 'present',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AttendancePunchControllerTest --compact`
Expected: FAIL — route/classes not found.

- [ ] **Step 3: Write the middleware**

`app/Http/Middleware/AuthenticateKioskDevice.php`:

```php
<?php

namespace App\Http\Middleware;

use App\Models\KioskDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateKioskDevice
{
    /**
     * Stands in for `auth:sanctum` on the one route a physical device (not
     * a logged-in portal user) calls. Resolves the device from a
     * SHA-256-hashed `X-Kiosk-Token` header and binds it onto the request
     * for the controller — `school_id` for every punch always comes from
     * this resolved device, never from the request body.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('X-Kiosk-Token');

        if (! $token) {
            abort(401, 'Missing kiosk device token.');
        }

        $device = KioskDevice::where('token', hash('sha256', $token))
            ->where('is_active', true)
            ->first();

        if ($device === null) {
            abort(401, 'Invalid or inactive kiosk device token.');
        }

        // saveQuietly(): a device touching its own last_used_at on every
        // punch should not run through Auditable's saving hook (there is
        // no authenticated portal user to stamp as updated_by here anyway).
        $device->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('kiosk_device', $device);

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

In `bootstrap/app.php`, change:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
```

to:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'kiosk.auth' => \App\Http\Middleware\AuthenticateKioskDevice::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
```

- [ ] **Step 5: Write the FormRequest**

`app/Http/Requests/Api/V1/RecordAttendancePunchRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAttendancePunchRequest extends FormRequest
{
    /**
     * Authentication here is the kiosk device token, already checked by
     * the `kiosk.auth` middleware before this FormRequest even runs —
     * there is no portal user on this request at all, so there is nothing
     * further to authorize.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:64'],
            'method' => ['required', Rule::in(['id_card', 'face'])],
            'captured_at' => ['nullable', 'date'],
        ];
    }
}
```

- [ ] **Step 6: Write the Controller**

`app/Http/Controllers/Api/V1/AttendancePunchController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordAttendancePunchRequest;
use App\Models\AttendancePunch;
use App\Models\KioskDevice;
use App\Models\TeacherAttendance;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AttendancePunchController extends Controller
{
    /**
     * Receives one punch from a device and derives the day's attendance
     * from it. This contract only resolves against TeacherProfile today —
     * the later student-attendance sub-project adds a second lookup
     * branch (falling back to StudentProfile.student_id) and its own
     * summary table; the URL, auth mechanism, and request/response shape
     * do not need to change.
     */
    public function store(RecordAttendancePunchRequest $request): JsonResponse
    {
        /** @var KioskDevice $device */
        $device = $request->attributes->get('kiosk_device');

        $teacherProfile = TeacherProfile::where('school_id', $device->school_id)
            ->where('employee_id', $request->string('identifier')->toString())
            ->first();

        if ($teacherProfile === null) {
            abort(404, 'Unrecognized ID.');
        }

        $punchedAt = $request->filled('captured_at')
            ? Carbon::parse($request->string('captured_at')->toString())
            : now();

        AttendancePunch::create([
            'teacher_profile_id' => $teacherProfile->id,
            'kiosk_device_id' => $device->id,
            'method' => $request->string('method')->toString(),
            'punched_at' => $punchedAt,
        ]);

        $this->upsertDaySummary($teacherProfile, $punchedAt);

        return response()->json([
            'data' => [
                'name' => $teacherProfile->user?->name,
                'punched_at' => $punchedAt->toIso8601String(),
            ],
        ], 201);
    }

    /**
     * check_in_at is the earliest punch of the day, check_out_at the
     * latest — recomputed from (existing check_in, existing check_out,
     * this new punch) rather than assuming punches arrive in order, so a
     * late-arriving earlier timestamp (e.g. a retried/delayed device
     * upload) still corrects check_in_at rather than being ignored.
     * Always writes status 'present' — a punch is hard evidence of actual
     * attendance and always wins over a leave record.
     */
    private function upsertDaySummary(TeacherProfile $teacherProfile, Carbon $punchedAt): void
    {
        $attendance = TeacherAttendance::where('teacher_profile_id', $teacherProfile->id)
            ->whereDate('date', $punchedAt->toDateString())
            ->first();

        if ($attendance === null) {
            TeacherAttendance::create([
                'teacher_profile_id' => $teacherProfile->id,
                'school_id' => $teacherProfile->school_id,
                'district_id' => $teacherProfile->district_id,
                'date' => $punchedAt->toDateString(),
                'status' => 'present',
                'check_in_at' => $punchedAt,
                'check_out_at' => null,
            ]);

            return;
        }

        $times = collect([$attendance->check_in_at, $attendance->check_out_at, $punchedAt])
            ->filter()
            ->sortBy(fn (Carbon $time) => $time->getTimestamp())
            ->values();

        $attendance->update([
            'status' => 'present',
            'check_in_at' => $times->first(),
            'check_out_at' => $times->count() > 1 ? $times->last() : null,
        ]);
    }
}
```

- [ ] **Step 7: Register the route**

In `routes/api.php`: add imports `use App\Http\Controllers\Api\V1\AttendancePunchController;` (alphabetical, after `AttendanceController`). Register the route in the PUBLIC section (not inside the `auth:sanctum` group — this is a device, not a Sanctum-authenticated user), right after the `/practice-exams` lines:

```php
    // Device-facing, not a portal user — authenticated by the kiosk.auth
    // middleware's own token check, not auth:sanctum. Throttled the same
    // way the other public write endpoint above is.
    Route::post('/attendance-punches', [AttendancePunchController::class, 'store'])
        ->middleware(['kiosk.auth', 'throttle:120,1']);
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=AttendancePunchControllerTest --compact`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/AuthenticateKioskDevice.php bootstrap/app.php \
        app/Http/Requests/Api/V1/RecordAttendancePunchRequest.php app/Http/Controllers/Api/V1/AttendancePunchController.php \
        routes/api.php tests/Feature/AttendancePunchControllerTest.php
git commit -m "Add device-facing attendance punch ingestion endpoint"
```

---

## Task 5: Teacher Attendance Report, Self-View & Manual Correction

**Files:**
- Create: `backend/app/Policies/TeacherAttendancePolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/CorrectTeacherAttendanceRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/TeacherAttendanceController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/TeacherAttendanceControllerTest.php`

**Interfaces:**
- Consumes: `TeacherAttendance` (Task 1), `teacher_attendances.*` permissions (Task 2), `Holiday::isHoliday()` (existing), `TeacherLeaveApplication` (existing).
- Produces: `GET /api/v1/teacher-attendances/report?school_id=&year=&month=` → `{data: {school: {id,name,address}, year, month, dates: [{date,day,holiday_name}], teachers: [{teacher_profile_id,name,employee_id,statuses: {date: status}}]}}`. `GET /api/v1/teacher-attendances/mine?year=&month=` → `{data: {year, month, days: [{date,status,check_in_at,check_out_at,holiday_name}], summary: {present,absent,leave}}}`. `PATCH /api/v1/teacher-attendances/correct` body `{teacher_profile_id, date, status, correction_note?}`. These three exact response shapes are what the frontend tasks (7, 9) consume.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\Holiday;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherAttendanceControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private TeacherUser $teacher;

    private TeacherProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $district->id]);
        $this->profile = TeacherProfile::create([
            'user_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $district->id,
            'employee_id' => 'EMP-0001',
        ]);
    }

    public function test_report_marks_a_day_with_no_record_as_absent(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertSame('absent', $teacherRow['statuses']['2026-12-01']);
    }

    public function test_report_marks_a_day_covered_by_an_approved_leave_as_leave(): void
    {
        TeacherLeaveApplication::create([
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'reason' => 'Sick',
            'status' => 'approved',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertSame('leave', $teacherRow['statuses']['2026-12-01']);
    }

    public function test_report_overlays_a_holiday_instead_of_marking_absent(): void
    {
        Holiday::create([
            'type' => 'other',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-01',
            'name' => 'Special Holiday',
        ]);

        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.view'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertOk();

        $teacherRow = collect($response->json('data.teachers'))->firstWhere('teacher_profile_id', $this->profile->id);
        $this->assertArrayNotHasKey('2026-12-01', $teacherRow['statuses']);
        $dateInfo = collect($response->json('data.dates'))->firstWhere('date', '2026-12-01');
        $this->assertSame('Special Holiday', $dateInfo['holiday_name']);
    }

    public function test_report_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/teacher-attendances/report?school_id={$this->school->id}&year=2026&month=12")
            ->assertForbidden();
    }

    public function test_mine_returns_the_teachers_own_record_with_check_in_and_out_times(): void
    {
        TeacherAttendance::create([
            'teacher_profile_id' => $this->profile->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'date' => '2026-12-01',
            'status' => 'present',
            'check_in_at' => '2026-12-01 08:55:00',
            'check_out_at' => '2026-12-01 16:05:00',
        ]);

        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/teacher-attendances/mine?year=2026&month=12')
            ->assertOk();

        $day = collect($response->json('data.days'))->firstWhere('date', '2026-12-01');
        $this->assertSame('present', $day['status']);
        $this->assertNotNull($day['check_in_at']);
        $this->assertSame(1, $response->json('data.summary.present'));
    }

    public function test_mine_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([]);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/teacher-attendances/mine')
            ->assertForbidden();
    }

    public function test_school_admin_can_manually_correct_a_days_status(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2026-12-01',
                'status' => 'present',
                'correction_note' => 'Device was offline',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'present');

        $this->assertDatabaseHas('teacher_attendances', [
            'teacher_profile_id' => $this->profile->id,
            'date' => '2026-12-01',
            'status' => 'present',
            'corrected_by_id' => $schoolAdmin->id,
            'correction_note' => 'Device was offline',
        ]);
    }

    public function test_correct_is_forbidden_without_the_update_permission(): void
    {
        $schoolAdmin = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2026-12-01',
                'status' => 'present',
            ])
            ->assertForbidden();
    }

    public function test_correct_rejects_an_invalid_status(): void
    {
        $schoolAdmin = $this->actorWithPermissions(['teacher_attendances.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson('/api/v1/teacher-attendances/correct', [
                'teacher_profile_id' => $this->profile->id,
                'date' => '2026-12-01',
                'status' => 'not-a-real-status',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=TeacherAttendanceControllerTest --compact`
Expected: FAIL — route/classes not found.

- [ ] **Step 3: Write the Policy**

`app/Policies/TeacherAttendancePolicy.php`:

```php
<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

class TeacherAttendancePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_attendances.view');
    }

    public function update(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_attendances.update');
    }
}
```

- [ ] **Step 4: Write the FormRequest**

`app/Http/Requests/Api/V1/CorrectTeacherAttendanceRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherAttendance;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CorrectTeacherAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', TeacherAttendance::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'teacher_profile_id' => ['required', 'integer', Rule::exists('teacher_profiles', 'id')->withoutTrashed()],
            'date' => ['required', 'date'],
            'status' => ['required', Rule::in(TeacherAttendance::STATUSES)],
            'correction_note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 5: Write the Controller**

`app/Http/Controllers/Api/V1/TeacherAttendanceController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CorrectTeacherAttendanceRequest;
use App\Models\Holiday;
use App\Models\School;
use App\Models\TeacherAttendance;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherProfile;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class TeacherAttendanceController extends Controller
{
    /**
     * Monthly attendance grid for every teacher at a school — mirrors
     * AttendanceController::report()'s shape for students. A day with no
     * stored row is synthesized as leave/absent here (never pre-populated
     * in the database); a holiday day is left out of `statuses` entirely
     * and the caller reads the holiday name from `dates` instead, exactly
     * like the student report already does.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TeacherAttendance::class);

        $school = School::findOrFail($request->integer('school_id'));

        [$year, $month, $start, $end] = $this->resolveMonth($request);
        $dates = $this->dateRange($start, $end);

        $profiles = TeacherProfile::where('school_id', $school->id)->with('user')->get();

        $attendanceByTeacher = TeacherAttendance::where('school_id', $school->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->groupBy('teacher_profile_id');

        $teacherRows = $profiles->map(function (TeacherProfile $profile) use ($start, $end, $attendanceByTeacher) {
            $own = $attendanceByTeacher->get($profile->id, collect())->keyBy(fn (TeacherAttendance $row) => $row->date->toDateString());
            $statuses = [];

            for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
                $dateString = $cursor->toDateString();
                $existing = $own->get($dateString);

                if ($existing !== null) {
                    $statuses[$dateString] = $existing->status;

                    continue;
                }

                if (Holiday::isHoliday($cursor)) {
                    continue;
                }

                $statuses[$dateString] = $this->onApprovedLeave($profile->user_id, $cursor) ? 'leave' : 'absent';
            }

            return [
                'teacher_profile_id' => $profile->id,
                'name' => $profile->user?->name,
                'employee_id' => $profile->employee_id,
                'statuses' => $statuses,
            ];
        })->values();

        return response()->json([
            'data' => [
                'school' => ['id' => $school->id, 'name' => $school->name, 'address' => $school->address],
                'year' => $year,
                'month' => $month,
                'dates' => $dates,
                'teachers' => $teacherRows,
            ],
        ]);
    }

    /**
     * This teacher's own monthly record, including check-in/out times —
     * the source data for the teacher portal's "My Attendance" page.
     * Self-service bypass, same convention as AttendanceController::mine()
     * for students — no permission check, just an instanceof guard.
     */
    public function mine(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $profile = $teacher->profile;

        if ($profile === null) {
            return response()->json(['data' => null]);
        }

        [$year, $month, $start, $end] = $this->resolveMonth($request);

        $records = TeacherAttendance::where('teacher_profile_id', $profile->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (TeacherAttendance $row) => $row->date->toDateString());

        $days = [];
        $summary = ['present' => 0, 'absent' => 0, 'leave' => 0];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $dateString = $cursor->toDateString();
            $record = $records->get($dateString);
            $holidayName = $this->holidayNameCovering($cursor);

            $status = $record?->status;

            if ($status === null && $holidayName === null) {
                $status = $this->onApprovedLeave($teacher->id, $cursor) ? 'leave' : 'absent';
            }

            if ($status !== null) {
                $summary[$status]++;
            }

            $days[] = [
                'date' => $dateString,
                'status' => $holidayName !== null ? null : $status,
                'check_in_at' => $record?->check_in_at?->toIso8601String(),
                'check_out_at' => $record?->check_out_at?->toIso8601String(),
                'holiday_name' => $holidayName,
            ];
        }

        return response()->json([
            'data' => [
                'year' => $year,
                'month' => $month,
                'days' => $days,
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * Manual correction by school_admin/national_admin — device was down,
     * missed punch, etc. Overwrites (or creates) the day's final status;
     * check_in_at/check_out_at are left untouched (a correction is about
     * the day's status, not fabricating device timestamps).
     */
    public function correct(CorrectTeacherAttendanceRequest $request): JsonResponse
    {
        $profile = TeacherProfile::findOrFail($request->integer('teacher_profile_id'));

        $attendance = TeacherAttendance::updateOrCreate(
            ['teacher_profile_id' => $profile->id, 'date' => $request->string('date')->toString()],
            [
                'school_id' => $profile->school_id,
                'district_id' => $profile->district_id,
                'status' => $request->string('status')->toString(),
                'corrected_by_id' => $request->user()->id,
                'correction_note' => $request->string('correction_note')->toString() ?: null,
            ],
        );

        return response()->json(['data' => [
            'teacher_profile_id' => $attendance->teacher_profile_id,
            'date' => $attendance->date->toDateString(),
            'status' => $attendance->status,
        ]]);
    }

    private function onApprovedLeave(int $teacherUserId, Carbon $date): bool
    {
        return TeacherLeaveApplication::query()
            ->where('teacher_id', $teacherUserId)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', $date)
            ->whereDate('to_date', '>=', $date)
            ->exists();
    }

    private function holidayNameCovering(Carbon $date): ?string
    {
        return Holiday::where('is_active', true)->get()->first(fn (Holiday $holiday) => $holiday->coversDate($date))?->name;
    }

    /**
     * @return array{0: int, 1: int, 2: Carbon, 3: Carbon}
     */
    private function resolveMonth(Request $request): array
    {
        $year = $request->integer('year') ?: (int) now()->format('Y');
        $month = $request->integer('month') ?: (int) now()->format('n');

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$year, $month, $start, $end];
    }

    /**
     * @return array<int, array{date: string, day: int, holiday_name: ?string}>
     */
    private function dateRange(Carbon $start, Carbon $end): array
    {
        $holidays = Holiday::where('is_active', true)->get();
        $dates = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $holiday = $holidays->first(fn (Holiday $item) => $item->coversDate($cursor));

            $dates[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'holiday_name' => $holiday?->name,
            ];
        }

        return $dates;
    }
}
```

- [ ] **Step 6: Register routes**

In `routes/api.php`: add import `use App\Http\Controllers\Api\V1\TeacherAttendanceController;` (alphabetical, after `TeacherProfileController`... actually directly before it alphabetically: `TeacherAttendanceController` sorts before `TeacherLeaveApplicationController`). Inside the existing `auth:sanctum` group, near the `teacher-leave-applications` routes, add:

```php
        Route::get('/teacher-attendances/report', [TeacherAttendanceController::class, 'report']);
        Route::get('/teacher-attendances/mine', [TeacherAttendanceController::class, 'mine']);
        Route::patch('/teacher-attendances/correct', [TeacherAttendanceController::class, 'correct']);
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=TeacherAttendanceControllerTest --compact`
Expected: PASS

- [ ] **Step 8: Run the full backend suite**

Run: `php artisan test --compact`
Expected: PASS (every test, old and new)

- [ ] **Step 9: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add app/Policies/TeacherAttendancePolicy.php app/Http/Requests/Api/V1/CorrectTeacherAttendanceRequest.php \
        app/Http/Controllers/Api/V1/TeacherAttendanceController.php routes/api.php \
        tests/Feature/TeacherAttendanceControllerTest.php
git commit -m "Add teacher attendance report, self-view, and manual correction endpoints"
```

---

## Task 6: Frontend API Client

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`

**Interfaces:**
- Consumes: the three endpoint groups from Tasks 3–5 (`kiosk-devices`, `attendance-punches` — not called from any portal UI, so no client function for it — `teacher-attendances/*`).
- Produces: `KioskDevice`, `KioskDevicePayload`, `listKioskDevices`, `createKioskDevice` (returns `KioskDevice & {token: string}`), `updateKioskDevice`, `deleteKioskDevice`; `TeacherAttendanceReport`, `getTeacherAttendanceReport`; `MyTeacherAttendance`, `getMyTeacherAttendance`; `correctTeacherAttendance`. Later frontend tasks (7–9) import exactly these names from `@ttla/api-client`.

- [ ] **Step 1: Add the types and functions**

Append to `frontend/packages/api-client/src/index.ts` (anywhere after the existing `AttendanceStatus`/attendance exports — these are new, independent exports, so there's no existing test to fail first; verification is the type-check in Step 2):

```ts
export interface KioskDevice {
  id: number
  school_id: number
  school_name: string | null
  name: string
  is_active: boolean
  last_used_at: string | null
  created_at: string | null
}

export interface KioskDevicePayload {
  name: string
  school_id?: number
}

export function listKioskDevices(
  params: { page?: number; perPage?: number } = {},
  token?: string,
): Promise<PaginatedResponse<KioskDevice>> {
  return listGeo<KioskDevice>('kiosk-devices', params, token)
}

// Only the create response ever carries the plaintext token — shown to
// the admin exactly once, matching the backend's own one-time-reveal
// contract (see KioskDeviceController::store()).
export function createKioskDevice(
  payload: KioskDevicePayload,
  token: string,
): Promise<{ data: KioskDevice & { token: string } }> {
  return createGeo<KioskDevice & { token: string }, KioskDevicePayload>('kiosk-devices', payload, token)
}

export function updateKioskDevice(
  id: number,
  payload: Partial<{ name: string; is_active: boolean }>,
  token: string,
): Promise<{ data: KioskDevice }> {
  return updateGeo<KioskDevice, Partial<{ name: string; is_active: boolean }>>('kiosk-devices', id, payload, token)
}

export function deleteKioskDevice(id: number, token: string): Promise<void> {
  return deleteGeo('kiosk-devices', id, token)
}

export interface TeacherAttendanceReportTeacherRow {
  teacher_profile_id: number
  name: string | null
  employee_id: string | null
  statuses: Record<string, AttendanceStatus>
}

export interface TeacherAttendanceReport {
  school: { id: number; name: string; address: string | null }
  year: number
  month: number
  dates: AttendanceReportDate[]
  teachers: TeacherAttendanceReportTeacherRow[]
}

export function getTeacherAttendanceReport(
  params: { schoolId: number; year: number; month: number },
  token: string,
): Promise<{ data: TeacherAttendanceReport }> {
  const query = buildQuery({ school_id: params.schoolId, year: params.year, month: params.month })

  return apiFetch<{ data: TeacherAttendanceReport }>(`/teacher-attendances/report${query}`, {
    headers: authHeaders(token),
  })
}

export interface MyTeacherAttendanceDay {
  date: string
  status: AttendanceStatus | null
  check_in_at: string | null
  check_out_at: string | null
  holiday_name: string | null
}

export interface MyTeacherAttendance {
  year: number
  month: number
  days: MyTeacherAttendanceDay[]
  summary: Record<AttendanceStatus, number>
}

export function getMyTeacherAttendance(
  params: { year: number; month: number },
  token: string,
): Promise<{ data: MyTeacherAttendance | null }> {
  const query = buildQuery({ year: params.year, month: params.month })

  return apiFetch<{ data: MyTeacherAttendance | null }>(`/teacher-attendances/mine${query}`, {
    headers: authHeaders(token),
  })
}

export function correctTeacherAttendance(
  payload: { teacher_profile_id: number; date: string; status: AttendanceStatus; correction_note?: string },
  token: string,
): Promise<{ data: { teacher_profile_id: number; date: string; status: AttendanceStatus } }> {
  return apiFetch('/teacher-attendances/correct', {
    method: 'PATCH',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}
```

- [ ] **Step 2: Type-check**

Run (from `frontend/`): `cd packages/api-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
cd ..  # back to frontend/
git add packages/api-client/src/index.ts
git commit -m "Add kiosk device and teacher attendance API client functions"
```

---

## Task 7: Management — Kiosk Devices Page

**Files:**
- Create: `frontend/apps/management/src/pages/KioskDevicesPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listKioskDevices`, `createKioskDevice`, `updateKioskDevice`, `deleteKioskDevice` (Task 6), `GeoCrudTable` (existing).
- Produces: route `/dashboard/kiosk-devices`, nav item "Kiosk Devices" (visible to `national_admin`/`school_admin`, same gating as every other admin-only page in this app).

- [ ] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add (anywhere near the other `management.nav.*`/feature-namespaced keys — e.g. right after `management.idCard.generatedOn`):

```json
  "management.nav.kioskDevices": "Kiosk Devices",
  "management.kioskDevices.title": "Kiosk Devices",
  "management.kioskDevices.addNew": "Add device",
  "management.kioskDevices.name": "Device name",
  "management.kioskDevices.school": "School",
  "management.kioskDevices.status": "Status",
  "management.kioskDevices.active": "Active",
  "management.kioskDevices.inactive": "Inactive",
  "management.kioskDevices.lastUsed": "Last used",
  "management.kioskDevices.never": "Never",
  "management.kioskDevices.tokenRevealTitle": "Device created",
  "management.kioskDevices.tokenRevealBody": "Copy this token into the device now — it will not be shown again.",
  "management.kioskDevices.copyToken": "Copy token",
  "management.kioskDevices.copied": "Copied",
  "management.kioskDevices.done": "Done",
  "management.kioskDevices.nameRequired": "Device name is required.",
```

In `frontend/packages/i18n/src/common/bn.json`, add the matching keys at the same relative location:

```json
  "management.nav.kioskDevices": "কিয়স্ক ডিভাইস",
  "management.kioskDevices.title": "কিয়স্ক ডিভাইস",
  "management.kioskDevices.addNew": "ডিভাইস যুক্ত করুন",
  "management.kioskDevices.name": "ডিভাইসের নাম",
  "management.kioskDevices.school": "বিদ্যালয়",
  "management.kioskDevices.status": "অবস্থা",
  "management.kioskDevices.active": "সক্রিয়",
  "management.kioskDevices.inactive": "নিষ্ক্রিয়",
  "management.kioskDevices.lastUsed": "সর্বশেষ ব্যবহৃত",
  "management.kioskDevices.never": "কখনো না",
  "management.kioskDevices.tokenRevealTitle": "ডিভাইস তৈরি হয়েছে",
  "management.kioskDevices.tokenRevealBody": "এই টোকেনটি এখনই ডিভাইসে প্রবেশ করান — এটি আর দেখানো হবে না।",
  "management.kioskDevices.copyToken": "টোকেন কপি করুন",
  "management.kioskDevices.copied": "কপি হয়েছে",
  "management.kioskDevices.done": "সম্পন্ন",
  "management.kioskDevices.nameRequired": "ডিভাইসের নাম আবশ্যক।",
```

- [ ] **Step 2: Write the page**

`frontend/apps/management/src/pages/KioskDevicesPage.tsx`:

```tsx
import { useEffect, useState } from 'react'
import {
  createKioskDevice,
  deleteKioskDevice,
  listKioskDevices,
  listSchools,
  updateKioskDevice,
  type KioskDevice,
  type School,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { type GeoColumn } from '../components/GeoCrudTable'
import { schoolLabel } from '../components/schoolOptions'
import SearchableSelect from '../components/SearchableSelect'
import { buttonNeutral, buttonPrimary, inputClass } from '../components/styles'

const t = getCommonStrings('en')

// Shown exactly once, right after creation — the backend never returns
// the plaintext token again (only its hash is stored), so this is the
// only chance to copy it into the physical device.
function TokenRevealModal({ token, onClose }: { token: string; onClose: () => void }) {
  const [copied, setCopied] = useState(false)

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(token)
      setCopied(true)
    } catch {
      // Clipboard access can be denied/unavailable — the token is still
      // selectable text in the box below, so this is a soft failure.
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5">
      <div className="w-full max-w-md rounded-md bg-white p-6 shadow-2xl" role="dialog" aria-modal="true">
        <h3 className="text-base font-semibold text-gray-800">{t['management.kioskDevices.tokenRevealTitle']}</h3>
        <p className="mt-2 text-sm text-gray-600">{t['management.kioskDevices.tokenRevealBody']}</p>

        <code className="mt-4 block w-full overflow-x-auto rounded-md bg-gray-50 px-3 py-2 text-xs break-all text-gray-800">
          {token}
        </code>

        <div className="mt-5 flex justify-end gap-3">
          <button type="button" className={buttonNeutral} onClick={handleCopy}>
            {copied ? t['management.kioskDevices.copied'] : t['management.kioskDevices.copyToken']}
          </button>
          <button type="button" className={buttonPrimary} onClick={onClose}>
            {t['management.kioskDevices.done']}
          </button>
        </div>
      </div>
    </div>
  )
}

function AddDeviceModal({
  schools,
  needsSchoolPicker,
  onCreated,
  onClose,
}: {
  schools: School[]
  needsSchoolPicker: boolean
  onCreated: (token: string) => void
  onClose: () => void
}) {
  const { token } = useAuth()
  const [name, setName] = useState('')
  const [schoolId, setSchoolId] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit() {
    if (!token) return
    if (!name.trim()) {
      setError(t['management.kioskDevices.nameRequired'])
      return
    }

    setSubmitting(true)
    setError(null)

    try {
      const created = await createKioskDevice(
        { name: name.trim(), ...(needsSchoolPicker && schoolId ? { school_id: Number(schoolId) } : {}) },
        token,
      )
      onCreated(created.data.token)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : String(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div className="w-full max-w-md rounded-md bg-white p-6 shadow-2xl" onClick={(event) => event.stopPropagation()} role="dialog" aria-modal="true">
        <h3 className="text-base font-semibold text-gray-800">{t['management.kioskDevices.addNew']}</h3>

        <label className="mt-4 flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.kioskDevices.name']}</span>
          <input className={inputClass} value={name} onChange={(event) => setName(event.target.value)} />
        </label>

        {needsSchoolPicker && (
          <label className="mt-4 flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['management.kioskDevices.school']}</span>
            <SearchableSelect
              value={schoolId}
              onChange={setSchoolId}
              placeholder={t['management.kioskDevices.school']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={schools.map((school) => ({ value: String(school.id), label: schoolLabel(school) }))}
            />
          </label>
        )}

        {error && <p className="mt-3 rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{error}</p>}

        <div className="mt-5 flex justify-end gap-3">
          <button type="button" className={buttonNeutral} onClick={onClose} disabled={submitting}>
            {t['management.table.cancel']}
          </button>
          <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting}>
            {submitting ? t['management.table.saving'] : t['management.table.save']}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function KioskDevicesPage() {
  const { token } = useAuth()
  const [schools, setSchools] = useState<School[]>([])
  const [adding, setAdding] = useState(false)
  const [revealToken, setRevealToken] = useState<string | null>(null)
  const [refreshKey, setRefreshKey] = useState(0)

  useEffect(() => {
    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => setSchools(result.data))
      .catch(() => setSchools([]))
  }, [token])

  const columns: GeoColumn<KioskDevice>[] = [
    { key: 'name', label: t['management.kioskDevices.name'], render: (row) => row.name },
    { key: 'school', label: t['management.kioskDevices.school'], render: (row) => row.school_name ?? '—' },
    {
      key: 'status',
      label: t['management.kioskDevices.status'],
      render: (row) => (row.is_active ? t['management.kioskDevices.active'] : t['management.kioskDevices.inactive']),
    },
    {
      key: 'last_used_at',
      label: t['management.kioskDevices.lastUsed'],
      render: (row) => (row.last_used_at ? new Date(row.last_used_at).toLocaleString() : t['management.kioskDevices.never']),
    },
  ]

  return (
    <section className="space-y-5">
      <GeoCrudTable<KioskDevice>
        key={refreshKey}
        title={t['management.kioskDevices.title']}
        columns={columns}
        fetchPage={(page) => listKioskDevices({ page, perPage: 15 }, token ?? undefined)}
        deleteRow={(id, authToken) => deleteKioskDevice(id, authToken)}
        onAdd={() => setAdding(true)}
        createPermission="kiosk_devices.create"
        updatePermission="kiosk_devices.update"
        deletePermission="kiosk_devices.delete"
        onEdit={(row) => {
          if (!token) return
          const nextActive = !row.is_active
          updateKioskDevice(row.id, { is_active: nextActive }, token).then(() => setRefreshKey((key) => key + 1))
        }}
      />

      {adding && (
        <AddDeviceModal
          schools={schools}
          // A school_admin's own listSchools call is already narrowed to
          // just their own school by TenantScope (it applies regardless of
          // the route's own public/auth:sanctum status — see TenantScope's
          // own comment), so `schools.length > 1` is only ever true for an
          // unscoped actor (national_admin), who is the only one who needs
          // to pick which school the device belongs to.
          needsSchoolPicker={schools.length > 1}
          onCreated={(plainToken) => {
            setAdding(false)
            setRevealToken(plainToken)
            setRefreshKey((key) => key + 1)
          }}
          onClose={() => setAdding(false)}
        />
      )}

      {revealToken && <TokenRevealModal token={revealToken} onClose={() => setRevealToken(null)} />}
    </section>
  )
}
```

**Note for the implementer:** `GeoCrudTable`'s built-in `onEdit` is repurposed here as a one-click active/inactive toggle (not a form) since a device's only ever-editable field besides name is `is_active`, and the one-click toggle is simpler UX than opening a form for one checkbox. If this reads awkwardly once you see it running, it is fine to instead give the row its own explicit toggle button via `rowActions` and leave `onEdit` unset — either is an acceptable interpretation of "rename/toggle a device," not a correctness requirement of this plan.

- [ ] **Step 3: Wire up the route and nav item**

In `frontend/apps/management/src/App.tsx`: add `import KioskDevicesPage from './pages/KioskDevicesPage'` (alphabetical). Add a nav item near `attendanceNavItem` (same visibility group):

```ts
const kioskDevicesNavItem: SidebarItem = { to: '/dashboard/kiosk-devices', label: t['management.nav.kioskDevices'], icon: 'point_of_sale' }
```

Add `kioskDevicesNavItem` to the same array `attendanceNavItem` is already included in (the `national_admin`/`school_admin`-only nav list around line 360). Add the route next to the other `RequireSchoolManager`-gated routes:

```tsx
                <Route path="kiosk-devices" element={<KioskDevicesPage />} />
```

- [ ] **Step 4: Type-check and build**

```bash
cd frontend/apps/management
npx tsc --noEmit -p tsconfig.json
npm run build
```
Expected: no errors, build succeeds.

- [ ] **Step 5: Manual browser verification**

Start the dev server (`npm run dev`), log in as `school_admin` (seeded `school.admin@tlap.test` / `password` — re-login if permissions were seeded after the session's token was issued), navigate to the new "Kiosk Devices" nav item, create a device, confirm the token-reveal modal shows a token exactly once, and confirm it's gone from the list view (no `token` column/value anywhere after closing the modal).

- [ ] **Step 6: Commit**

```bash
git add apps/management/src/pages/KioskDevicesPage.tsx apps/management/src/App.tsx \
        packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add Kiosk Devices management page"
```

---

## Task 8: Management — Teacher Attendance Report Page

**Files:**
- Create: `frontend/apps/management/src/pages/TeacherAttendanceReportPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `getTeacherAttendanceReport` (Task 6).
- Produces: route `/dashboard/teacher-attendance`, nav item "Teacher Attendance" (same `national_admin`/`school_admin` visibility as the existing student `AttendanceReportPage`).

- [ ] **Step 1: Add i18n keys**

`en.json` (near the other `management.nav.*` keys added in Task 7):

```json
  "management.nav.teacherAttendance": "Teacher Attendance",
  "management.teacherAttendance.title": "Teacher Attendance Report",
  "management.teacherAttendance.colEmployeeId": "Employee ID",
  "management.teacherAttendance.colTeacher": "Teacher Name",
  "management.teacherAttendance.loading": "Loading the report...",
  "management.teacherAttendance.empty": "No teachers at this school yet.",
  "management.teacherAttendance.print": "Print",
```

`bn.json`:

```json
  "management.nav.teacherAttendance": "শিক্ষক উপস্থিতি",
  "management.teacherAttendance.title": "শিক্ষক উপস্থিতি প্রতিবেদন",
  "management.teacherAttendance.colEmployeeId": "কর্মচারী আইডি",
  "management.teacherAttendance.colTeacher": "শিক্ষকের নাম",
  "management.teacherAttendance.loading": "প্রতিবেদন লোড হচ্ছে...",
  "management.teacherAttendance.empty": "এই বিদ্যালয়ে এখনো কোনো শিক্ষক নেই।",
  "management.teacherAttendance.print": "প্রিন্ট করুন",
```

- [ ] **Step 2: Write the page**

`frontend/apps/management/src/pages/TeacherAttendanceReportPage.tsx` — this mirrors the existing `AttendanceReportPage.tsx` closely (same `SmartMonthPicker`, same sticky-grid table, same `window.print()` convention); reuse its `SmartMonthPicker` component logic by copying it in (this codebase duplicates small page-local components rather than extracting shared ones — same convention already seen between `AttendanceReportPage.tsx` and the teacher portal's own calendar widgets):

```tsx
import { useEffect, useState } from 'react'
import { getTeacherAttendanceReport, listSchools, type School, type TeacherAttendanceReport } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from '../components/SearchableSelect'

const t = getCommonStrings('en')

const MONTH_NAMES = Array.from({ length: 12 }, (_, index) => new Date(2023, index, 1).toLocaleDateString('en-US', { month: 'short' }))

const REPORT_LETTER: Record<string, string> = {
  present: t['management.attendance.statusPresent'],
  absent: t['management.attendance.statusAbsent'],
  leave: t['management.attendance.statusLeave'],
}

const REPORT_COLOR: Record<string, string> = {
  present: 'text-success-600',
  absent: 'text-danger-600',
  leave: 'text-amber-700',
}

export default function TeacherAttendanceReportPage() {
  const { token } = useAuth()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const now = new Date()
  const [year, setYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [loading, setLoading] = useState(false)
  const [report, setReport] = useState<TeacherAttendanceReport | null>(null)

  useEffect(() => {
    listSchools({ perPage: 200 }, token ?? undefined)
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))
  }, [token])

  useEffect(() => {
    if (!token || !schoolId) {
      setReport(null)
      return
    }

    let active = true
    setLoading(true)

    getTeacherAttendanceReport({ schoolId, year, month }, token)
      .then((result) => {
        if (active) setReport(result.data)
      })
      .catch(() => {
        if (active) setReport(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, schoolId, year, month])

  return (
    <section className="px-4 py-6 print:px-0 print:py-0 lg:px-6">
      <style>{`
        @media print {
          @page { size: A4 landscape; margin: 10mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-2xl font-bold text-gray-900 print:hidden">{t['management.teacherAttendance.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm print:hidden">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSchool']}</span>
          <SearchableSelect
            className="min-w-[14rem]"
            value={schoolId !== null ? String(schoolId) : ''}
            onChange={(value) => setSchoolId(Number(value))}
            placeholder={t['management.attendance.selectSchool']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={schools.map((school) => ({ value: String(school.id), label: school.bn_name ?? school.name }))}
          />
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.month']}</span>
          <select
            className="h-11 min-w-[9rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={month}
            onChange={(event) => setMonth(Number(event.target.value))}
          >
            {MONTH_NAMES.map((name, index) => (
              <option key={name} value={index + 1}>
                {name}
              </option>
            ))}
          </select>
        </label>

        <input
          type="number"
          className="h-11 w-24 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={year}
          onChange={(event) => setYear(Number(event.target.value))}
        />

        <button
          type="button"
          onClick={() => window.print()}
          className="flex cursor-pointer items-center gap-2 rounded-md border border-gray-200 px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:border-primary-300"
        >
          <span className="material-symbols-outlined !text-[18px] leading-none">print</span>
          {t['management.teacherAttendance.print']}
        </button>
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['management.teacherAttendance.loading']}</p>}

      {!loading && report && report.teachers.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['management.teacherAttendance.empty']}</p>
      )}

      {!loading && report && report.teachers.length > 0 && (
        <div className="mt-4 rounded-md bg-white p-5 shadow-sm print:mt-0 print:rounded-none print:p-0 print:shadow-none">
          <div className="mb-4 text-center text-sm text-gray-700">
            <p className="text-base font-semibold text-gray-900">{report.school.name}</p>
            {report.school.address && <p className="text-xs text-gray-500">{report.school.address}</p>}
            <p className="mt-1 text-xs font-medium text-gray-600">
              {MONTH_NAMES[month - 1]} {year}
            </p>
          </div>

          <div className="max-h-[28rem] overflow-x-auto overflow-y-auto rounded-md border border-gray-100">
            <table className="w-full text-xs">
              <thead className="font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="sticky top-0 left-0 z-20 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">
                    {t['management.teacherAttendance.colEmployeeId']}
                  </th>
                  <th className="sticky top-0 left-24 z-20 bg-primary-50 px-3 py-2 text-left whitespace-nowrap">
                    {t['management.teacherAttendance.colTeacher']}
                  </th>
                  {report.dates.map((dateInfo) => (
                    <th
                      key={dateInfo.date}
                      title={dateInfo.holiday_name ?? undefined}
                      className={`sticky top-0 z-10 px-2 py-2 text-center whitespace-nowrap ${
                        dateInfo.holiday_name ? 'bg-amber-50 text-amber-700' : 'bg-primary-50'
                      }`}
                    >
                      {dateInfo.day}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {report.teachers.map((row) => (
                  <tr key={row.teacher_profile_id} className="border-t border-gray-100">
                    <td className="sticky left-0 z-10 bg-white px-3 py-2 whitespace-nowrap text-gray-500">{row.employee_id ?? '—'}</td>
                    <td className="sticky left-24 z-10 bg-white px-3 py-2 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                    {report.dates.map((dateInfo) => {
                      const status = row.statuses[dateInfo.date]

                      return (
                        <td key={dateInfo.date} className="px-2 py-2 text-center">
                          {status ? (
                            <span className={`font-semibold ${REPORT_COLOR[status]}`}>{REPORT_LETTER[status]}</span>
                          ) : dateInfo.holiday_name ? (
                            <span className="text-amber-500">•</span>
                          ) : (
                            <span className="text-gray-300">—</span>
                          )}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </section>
  )
}
```

- [ ] **Step 3: Wire up the route and nav item**

In `App.tsx`: `import TeacherAttendanceReportPage from './pages/TeacherAttendanceReportPage'`, add

```ts
const teacherAttendanceNavItem: SidebarItem = { to: '/dashboard/teacher-attendance', label: t['management.nav.teacherAttendance'], icon: 'badge' }
```

into the same nav array as `attendanceNavItem`/`kioskDevicesNavItem`, and the route:

```tsx
                <Route path="teacher-attendance" element={<TeacherAttendanceReportPage />} />
```

- [ ] **Step 4: Type-check and build**

```bash
cd frontend/apps/management
npx tsc --noEmit -p tsconfig.json
npm run build
```

- [ ] **Step 5: Manual browser verification**

As `school_admin`, open "Teacher Attendance," confirm the grid renders (absent for every day with no data yet, since no punches exist), switch months, and confirm print preview (`Ctrl+P` / browser print preview, or emulate print media) shows only the grid.

- [ ] **Step 6: Commit**

```bash
git add apps/management/src/pages/TeacherAttendanceReportPage.tsx apps/management/src/App.tsx \
        packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add Teacher Attendance Report management page"
```

---

## Task 9: Teacher Portal — My Attendance Page

**Files:**
- Create: `frontend/apps/teacher/src/pages/MyAttendancePage.tsx`
- Modify: `frontend/apps/teacher/src/App.tsx`
- Modify: `frontend/apps/teacher/src/components/TeacherAppShell.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `getMyTeacherAttendance` (Task 6).
- Produces: route `/my-attendance`, nav item "My Attendance" in the teacher portal sidebar.

- [ ] **Step 1: Add i18n keys**

`en.json`:

```json
  "teacher.nav.myAttendance": "My Attendance",
  "teacher.myAttendance.title": "My Attendance",
  "teacher.myAttendance.month": "Month",
  "teacher.myAttendance.loading": "Loading...",
  "teacher.myAttendance.noProfile": "Your profile hasn't been set up yet — contact your administrator.",
  "teacher.myAttendance.colDate": "Date",
  "teacher.myAttendance.colStatus": "Status",
  "teacher.myAttendance.colCheckIn": "Check-in",
  "teacher.myAttendance.colCheckOut": "Check-out",
  "teacher.myAttendance.summaryPresent": "Present",
  "teacher.myAttendance.summaryAbsent": "Absent",
  "teacher.myAttendance.summaryLeave": "Leave",
```

`bn.json`:

```json
  "teacher.nav.myAttendance": "আমার উপস্থিতি",
  "teacher.myAttendance.title": "আমার উপস্থিতি",
  "teacher.myAttendance.month": "মাস",
  "teacher.myAttendance.loading": "লোড হচ্ছে...",
  "teacher.myAttendance.noProfile": "আপনার প্রোফাইল এখনো তৈরি করা হয়নি — প্রশাসকের সাথে যোগাযোগ করুন।",
  "teacher.myAttendance.colDate": "তারিখ",
  "teacher.myAttendance.colStatus": "অবস্থা",
  "teacher.myAttendance.colCheckIn": "প্রবেশের সময়",
  "teacher.myAttendance.colCheckOut": "প্রস্থানের সময়",
  "teacher.myAttendance.summaryPresent": "উপস্থিত",
  "teacher.myAttendance.summaryAbsent": "অনুপস্থিত",
  "teacher.myAttendance.summaryLeave": "ছুটি",
```

- [ ] **Step 2: Write the page**

`frontend/apps/teacher/src/pages/MyAttendancePage.tsx`:

```tsx
import { useEffect, useState } from 'react'
import { getMyTeacherAttendance, type AttendanceStatus, type MyTeacherAttendance } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

const MONTH_NAMES = Array.from({ length: 12 }, (_, index) => new Date(2023, index, 1).toLocaleDateString('en-US', { month: 'long' }))

const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: t['teacher.attendance.statusPresent'],
  absent: t['teacher.attendance.statusAbsent'],
  leave: t['teacher.attendance.statusLeave'],
}

const STATUS_COLOR: Record<AttendanceStatus, string> = {
  present: 'text-success-600',
  absent: 'text-danger-600',
  leave: 'text-amber-700',
}

export default function MyAttendancePage() {
  const { token } = useAuth()

  const now = new Date()
  const [year, setYear] = useState(now.getFullYear())
  const [month, setMonth] = useState(now.getMonth() + 1)
  const [loading, setLoading] = useState(true)
  const [record, setRecord] = useState<MyTeacherAttendance | null>(null)
  const [hasProfile, setHasProfile] = useState(true)

  useEffect(() => {
    if (!token) return
    let active = true
    setLoading(true)

    getMyTeacherAttendance({ year, month }, token)
      .then((result) => {
        if (!active) return
        setRecord(result.data)
        setHasProfile(result.data !== null)
      })
      .catch(() => {
        if (active) setRecord(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, year, month])

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.myAttendance.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['teacher.myAttendance.month']}</span>
          <select
            className="h-11 min-w-[9rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={month}
            onChange={(event) => setMonth(Number(event.target.value))}
          >
            {MONTH_NAMES.map((name, index) => (
              <option key={name} value={index + 1}>
                {name}
              </option>
            ))}
          </select>
        </label>

        <input
          type="number"
          className="h-11 w-24 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={year}
          onChange={(event) => setYear(Number(event.target.value))}
        />
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['teacher.myAttendance.loading']}</p>}

      {!loading && !hasProfile && <p className="mt-6 text-sm text-gray-500">{t['teacher.myAttendance.noProfile']}</p>}

      {!loading && record && (
        <>
          <div className="mt-4 flex flex-wrap gap-4">
            <div className="rounded-md bg-white px-4 py-3 shadow-sm">
              <p className="text-xs text-gray-500">{t['teacher.myAttendance.summaryPresent']}</p>
              <p className="text-lg font-semibold text-success-600">{record.summary.present}</p>
            </div>
            <div className="rounded-md bg-white px-4 py-3 shadow-sm">
              <p className="text-xs text-gray-500">{t['teacher.myAttendance.summaryAbsent']}</p>
              <p className="text-lg font-semibold text-danger-600">{record.summary.absent}</p>
            </div>
            <div className="rounded-md bg-white px-4 py-3 shadow-sm">
              <p className="text-xs text-gray-500">{t['teacher.myAttendance.summaryLeave']}</p>
              <p className="text-lg font-semibold text-amber-700">{record.summary.leave}</p>
            </div>
          </div>

          <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[32rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colDate']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colStatus']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colCheckIn']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myAttendance.colCheckOut']}</th>
                </tr>
              </thead>
              <tbody>
                {record.days.map((day) => (
                  <tr key={day.date} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{day.date}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      {day.holiday_name ? (
                        <span className="text-amber-700">{day.holiday_name}</span>
                      ) : day.status ? (
                        <span className={`font-semibold ${STATUS_COLOR[day.status]}`}>{STATUS_LABEL[day.status]}</span>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                      {day.check_in_at ? new Date(day.check_in_at).toLocaleTimeString() : '—'}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                      {day.check_out_at ? new Date(day.check_out_at).toLocaleTimeString() : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  )
}
```

- [ ] **Step 3: Wire up the route and nav item**

In `frontend/apps/teacher/src/App.tsx`: add `import MyAttendancePage from './pages/MyAttendancePage'` and a route:

```tsx
              <Route path="my-attendance" element={<MyAttendancePage />} />
```

In `frontend/apps/teacher/src/components/TeacherAppShell.tsx`: add to `navItems` (a flat item, not nested under the existing "Attendance" submenu — that submenu is about recording *student* attendance, a different concern):

```ts
  { to: '/my-attendance', label: t['teacher.nav.myAttendance'], icon: 'badge' },
```

- [ ] **Step 4: Type-check and build**

```bash
cd frontend/apps/teacher
npx tsc --noEmit -p tsconfig.json
npm run build
```

- [ ] **Step 5: Manual browser verification**

Log in to the teacher portal as the seeded teacher (`teacher@tlap.test` / `password`), open "My Attendance," confirm it renders a monthly grid (every day absent, since no punches exist yet in a fresh environment — to see a present day, POST a punch via curl/Postman against `/api/v1/attendance-punches` with a kiosk token created in Task 7's manual check, using that teacher's `employee_id`).

- [ ] **Step 6: Commit**

```bash
git add apps/teacher/src/pages/MyAttendancePage.tsx apps/teacher/src/App.tsx apps/teacher/src/components/TeacherAppShell.tsx \
        packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add My Attendance page to the teacher portal"
```

---

## Self-Review Notes (for whoever executes this plan)

- **Spec coverage:** device check-in (Tasks 3–4) ✓, admin monitor/report/print (Task 8) ✓, teacher self-view (Task 9) ✓, manual correction (Task 5) ✓, device-agnostic ingestion layer reusable by the student sub-project (Task 4's contract) ✓, permissions mapped exactly to `national_admin`/`school_admin` (Task 2) ✓.
- **Deliberately not built** (per the spec's Non-Goals — do not add these): any in-house face-matching code, a specific device/vendor integration, a "late" status, student self-check-in, device health monitoring, notifications.
- **Full regression check:** after Task 5, `php artisan test --compact` must show 0 failures across the entire suite (every pre-existing test, not just this feature's new ones) before moving to the frontend tasks.
