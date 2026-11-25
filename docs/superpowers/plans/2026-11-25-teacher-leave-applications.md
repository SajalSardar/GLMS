# Teacher Leave Applications & Substitute Delegation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher submit a leave request (reviewed by school_admin), optionally naming a substitute colleague who then gains the ability to record attendance for the applicant's homeroom section(s) during the approved leave window, and let national_admin see a read-only list of who's currently on leave.

**Architecture:** One new Laravel resource (`TeacherLeaveApplication`) modeled directly on the existing `StudentLeaveApplication`, following this codebase's exact conventions: Auditable+SoftDeletes+TenantScoped model, Policy-based authorization (no route middleware), FormRequest validation, a resource-computed `can_review` flag. Delegation is implemented as a single extra OR-clause in the existing `AttendancePolicy`/`AttendanceController` — no new table, no cron job, no flag to flip; it's a live date-range-and-status query evaluated on every attendance call. Three frontend surfaces: a teacher-portal submit+history page, a school_admin review page, and a national_admin read-only page.

**Tech Stack:** Laravel (PHP), PHPUnit; React + TypeScript (Vite), Vitest.

**Spec:** `docs/superpowers/specs/2026-11-25-teacher-leave-applications-design.md`

## Global Constraints

- No route-level `permission:` middleware anywhere in this app — every authorization decision is a Policy/FormRequest `authorize()`/`can()` call. Do not introduce middleware-based gating.
- A teacher's own submission (`store`), their own history (`mine`), and the substitute picker (`colleagues`) bypass the Role/Permission system entirely (`$this->user() instanceof TeacherUser`) — same convention as `StoreStudentLeaveApplicationRequest`. Do not gate these behind a permission string.
- `national_admin` is never excluded from an ability by role-name check anywhere in this app — its wildcard permission set is a standing invariant. The "national_admin is read-only" decision is a **frontend** scope decision (no review UI built for it), not a backend lockout.
- `delegate_teacher_id` is nullable — a teacher with no homeroom section has nothing to delegate, and submission must not require one.
- No cancel/withdraw endpoint, no per-section delegate granularity, no delegation of exam-mark recording or student-leave review, no destroy endpoint. See the spec's Non-Goals section for the full list — do not add any of these.
- Every new permission name and role grant must exactly match: `teacher_leave_applications.view` and `teacher_leave_applications.update`, granted to `school_admin` only in `RolePermissionSeeder` (national_admin already has everything via its wildcard; `teacher` gets neither).

---

### Task 1: Backend — `TeacherLeaveApplication` resource (migration, model, policy, requests, resource, controller, routes, permissions)

**Files:**
- Create: `backend/database/migrations/2026_11_25_160000_create_teacher_leave_applications_table.php`
- Create: `backend/app/Models/TeacherLeaveApplication.php`
- Create: `backend/app/Policies/TeacherLeaveApplicationPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreTeacherLeaveApplicationRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/ReviewTeacherLeaveApplicationRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/TeacherLeaveApplicationResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/TeacherLeaveApplicationController.php`
- Modify: `backend/routes/api.php` (add 6 routes, mirroring the `student-leave-applications` block)
- Modify: `backend/database/seeders/PermissionSeeder.php` (add 2 permissions)
- Modify: `backend/database/seeders/RolePermissionSeeder.php` (grant both to `school_admin`)
- Test: `backend/tests/Feature/TeacherLeaveApplicationControllerTest.php`
- Test: `backend/tests/Feature/TeacherLeaveApplicationPermissionMappingTest.php`

**Interfaces:**
- Produces: `App\Models\TeacherLeaveApplication` (fillable: `teacher_id, school_id, district_id, from_date, to_date, reason, status, delegate_teacher_id, admin_comment, reviewed_by_id, reviewed_at`; `const STATUSES = ['pending', 'approved', 'rejected']`; relations `teacher()`, `delegate()`, `school()`, `district()`, `reviewedBy()`, all `BelongsTo`). This is the model Task 2 (`AttendancePolicy`/`AttendanceController`) queries directly.
- Produces: routes `POST /api/v1/teacher-leave-applications`, `GET /api/v1/teacher-leave-applications/mine`, `GET /api/v1/teacher-leave-applications/colleagues`, `GET /api/v1/teacher-leave-applications`, `GET /api/v1/teacher-leave-applications/{teacher_leave_application}`, `PATCH /api/v1/teacher-leave-applications/{teacher_leave_application}/review`.
- Produces: permissions `teacher_leave_applications.view`, `teacher_leave_applications.update` (granted to `school_admin`).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher's own leave request, reviewed by school_admin (never a
     * teacher — unlike student_leave_applications, there is exactly one
     * reviewer role per school). school_id/district_id are denormalized
     * from the applicant's own TeacherUser record at submission time
     * (never taken from the request), same convention as every other
     * TenantScoped table.
     *
     * delegate_teacher_id is nullable: a teacher with no homeroom section
     * has nothing to delegate. There is deliberately no section_id column
     * — "which sections does this affect" is never stored; it's computed
     * live as "every section where class_teacher_id = teacher_id" at
     * authorization-check time (see AttendancePolicy), so the delegation
     * stays correct even if homeroom assignments change later.
     */
    public function up(): void
    {
        Schema::create('teacher_leave_applications', function (Blueprint $table) {
            $table->id();

            // Polymorphic actor columns, matching the final shape every
            // other Auditable table has — no DB-level FK, since the actor
            // can be a User, TeacherUser, StudentUser, or GuardianUser.
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('teacher_id')->constrained('teacher_users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();

            $table->date('from_date');
            $table->date('to_date');
            $table->text('reason');
            // 'pending' | 'approved' | 'rejected' — validated at the
            // application layer, same convention as student_leave_
            // applications.status.
            $table->string('status')->default('pending');

            $table->foreignId('delegate_teacher_id')->nullable()->constrained('teacher_users')->nullOnDelete();

            $table->text('admin_comment')->nullable();
            // The reviewer here is always a staff User (school_admin),
            // never a TeacherUser — unlike student_leave_applications,
            // whose reviewer is the class teacher.
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_id', 'status']);
            $table->index(['teacher_id', 'status']);
            // Covers the "currently on leave" predicate reused by both
            // AttendancePolicy's delegate check and the index(current=1)
            // filter: status='approved' AND from_date<=today<=to_date.
            $table->index(['status', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_leave_applications');
    }
};
```

- [ ] **Step 2: Write the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherLeaveApplication extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    protected $fillable = [
        'teacher_id',
        'school_id',
        'district_id',
        'from_date',
        'to_date',
        'reason',
        'status',
        'delegate_teacher_id',
        'admin_comment',
        'reviewed_by_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }

    public function delegate(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'delegate_teacher_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }
}
```

- [ ] **Step 3: Write the policy**

```php
<?php

namespace App\Policies;

use App\Models\TeacherLeaveApplication;
use Illuminate\Contracts\Auth\Authenticatable;

class TeacherLeaveApplicationPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('teacher_leave_applications.view');
    }

    public function view(Authenticatable $user, TeacherLeaveApplication $application): bool
    {
        return $user->hasPermission('teacher_leave_applications.view');
    }

    /**
     * Unlike StudentLeaveApplicationPolicy::review(), there is no per-row
     * ownership check here — the reviewer is always school_admin (one
     * reviewer role per school), and TenantScope already narrows which
     * rows a school_admin can even reach. national_admin reaches this too
     * via its all-permissions wildcard (this app never excludes
     * national_admin from an ability by role-name check) — the "read-
     * only for national_admin" product decision is enforced by simply not
     * building review UI for it, not by a backend lockout.
     */
    public function review(Authenticatable $user, TeacherLeaveApplication $application): bool
    {
        return $user->hasPermission('teacher_leave_applications.update');
    }
}
```

- [ ] **Step 4: Write the FormRequests**

`backend/app/Http/Requests/Api/V1/StoreTeacherLeaveApplicationRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherLeaveApplication;
use App\Models\TeacherUser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherLeaveApplicationRequest extends FormRequest
{
    /**
     * A teacher submits their own leave application — not governed by
     * the Role/Permission system at all, same convention as
     * StoreStudentLeaveApplicationRequest: the actor just needs to be a
     * logged-in TeacherUser.
     */
    public function authorize(): bool
    {
        return $this->user() instanceof TeacherUser;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string|Closure>
     */
    public function rules(): array
    {
        $teacher = $this->user();

        return [
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'reason' => ['required', 'string', 'max:2000'],
            'delegate_teacher_id' => [
                'nullable',
                'integer',
                Rule::exists('teacher_users', 'id')->withoutTrashed(),
                function (string $attribute, mixed $value, Closure $fail) use ($teacher) {
                    if ($value === null || ! $teacher instanceof TeacherUser) {
                        return;
                    }

                    if ((int) $value === $teacher->id) {
                        $fail('You cannot name yourself as your own substitute.');

                        return;
                    }

                    $delegate = TeacherUser::find($value);

                    if ($delegate === null || $delegate->school_id !== $teacher->school_id) {
                        $fail('The selected substitute must be a teacher at your own school.');
                    }
                },
            ],
        ];
    }

    /**
     * Rejects a request whose date range overlaps an existing pending or
     * approved application by this same teacher — prevents two
     * conflicting requests/delegations coexisting. Runs after the field
     * rules above, so it's skipped if from_date/to_date already failed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $teacher = $this->user();

            if (! $teacher instanceof TeacherUser) {
                return;
            }

            $fromDate = $this->input('from_date');
            $toDate = $this->input('to_date');

            if (! $fromDate || ! $toDate) {
                return;
            }

            $overlaps = TeacherLeaveApplication::query()
                ->where('teacher_id', $teacher->id)
                ->whereIn('status', ['pending', 'approved'])
                ->where('from_date', '<=', $toDate)
                ->where('to_date', '>=', $fromDate)
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('from_date', 'You already have a pending or approved leave request that overlaps these dates.');
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/ReviewTeacherLeaveApplicationRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\TeacherLeaveApplication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReviewTeacherLeaveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', $this->route('teacher_leave_application')) ?? false;
    }

    /**
     * 'pending' is deliberately not a valid target — only store() sets
     * it; review() only ever moves an application forward.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_diff(TeacherLeaveApplication::STATUSES, ['pending']))],
            'admin_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
```

- [ ] **Step 5: Write the resource**

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherLeaveApplicationResource extends JsonResource
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
            'teacher_id' => $this->teacher_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'from_date' => $this->from_date?->toDateString(),
            'to_date' => $this->to_date?->toDateString(),
            'reason' => $this->reason,
            'status' => $this->status,
            'delegate_teacher_id' => $this->delegate_teacher_id,
            'admin_comment' => $this->admin_comment,
            'reviewed_by_id' => $this->reviewed_by_id,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
                'school_name' => $this->teacher->school?->name,
            ]),
            'delegate' => $this->whenLoaded('delegate', fn () => $this->delegate === null ? null : [
                'id' => $this->delegate->id,
                'name' => $this->delegate->name,
            ]),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy === null ? null : [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ]),
            // Lets the frontend show the approve/reject/comment form only
            // where review() would actually allow it — the client has no
            // way to re-derive the permission check itself.
            'can_review' => $request->user()?->can('review', $this->resource) ?? false,
        ];
    }
}
```

- [ ] **Step 6: Write the controller**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewTeacherLeaveApplicationRequest;
use App\Http\Requests\Api\V1\StoreTeacherLeaveApplicationRequest;
use App\Http\Resources\Api\V1\TeacherLeaveApplicationResource;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeacherLeaveApplicationController extends Controller
{
    private const WITH = ['teacher.school', 'delegate', 'reviewedBy'];

    /**
     * Staff listing, scoped by TenantScope (school/district) plus the
     * teacher_leave_applications.view permission. `current=1` narrows to
     * applications in effect right now (approved, today within range) —
     * the "who is on leave right now" view national_admin uses; omitted,
     * every status/date is returned.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', TeacherLeaveApplication::class);

        $applications = TeacherLeaveApplication::query()
            ->with(self::WITH)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->boolean('current'), fn ($query) => $query
                ->where('status', 'approved')
                ->whereDate('from_date', '<=', now())
                ->whereDate('to_date', '>=', now()))
            ->orderByDesc('created_at')
            ->get();

        return TeacherLeaveApplicationResource::collection($applications);
    }

    public function show(TeacherLeaveApplication $teacher_leave_application): TeacherLeaveApplicationResource
    {
        $this->authorize('view', $teacher_leave_application);

        return new TeacherLeaveApplicationResource($teacher_leave_application->load(self::WITH));
    }

    /**
     * A teacher submits their own leave application, scoped server-side
     * to their own school/district (never taken from the request) — same
     * reasoning as StudentLeaveApplicationController::store().
     */
    public function store(StoreTeacherLeaveApplicationRequest $request): JsonResponse
    {
        $teacher = $request->user();

        $application = TeacherLeaveApplication::create([
            'teacher_id' => $teacher->id,
            'school_id' => $teacher->school_id,
            'district_id' => $teacher->district_id,
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'reason' => $request->input('reason'),
            'status' => 'pending',
            'delegate_teacher_id' => $request->input('delegate_teacher_id'),
        ]);

        return (new TeacherLeaveApplicationResource($application->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * This teacher's own leave applications, most recent first.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $applications = TeacherLeaveApplication::query()
            ->with(self::WITH)
            ->where('teacher_id', $teacher->id)
            ->orderByDesc('created_at')
            ->get();

        return TeacherLeaveApplicationResource::collection($applications);
    }

    /**
     * Other teachers at this teacher's own school, for the substitute
     * picker — self-service, bypasses the permission system entirely
     * (same convention as store()/mine()); TenantScope on TeacherUser
     * already narrows the query to the caller's own school, so this
     * controller adds no explicit school_id filter. Deliberately minimal
     * (id + name only) — this endpoint has no permission check at all, so
     * it must never leak anything beyond a name.
     */
    public function colleagues(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $colleagues = TeacherUser::query()
            ->where('id', '!=', $teacher->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $colleagues]);
    }

    /**
     * Approve or reject, with an optional comment. The reviewer here is
     * always a staff User (only school_admin/national_admin ever hold
     * teacher_leave_applications.update — see RolePermissionSeeder), so
     * reviewed_by_id is stamped unconditionally — unlike
     * StudentLeaveApplicationController::review(), where the reviewer can
     * also be a TeacherUser.
     */
    public function review(ReviewTeacherLeaveApplicationRequest $request, TeacherLeaveApplication $teacher_leave_application): TeacherLeaveApplicationResource
    {
        $teacher_leave_application->update([
            'status' => $request->input('status'),
            'admin_comment' => $request->input('admin_comment'),
            'reviewed_by_id' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return new TeacherLeaveApplicationResource($teacher_leave_application->load(self::WITH));
    }
}
```

- [ ] **Step 7: Add the routes**

In `backend/routes/api.php`, find this block (search for `student-leave-applications`):

```php
        Route::post('/student-leave-applications', [StudentLeaveApplicationController::class, 'store']);
        // Must be registered before the {student_leave_application}
        // wildcard show route below, or "mine" would be matched as an
        // application ID — same gotcha as chapter-materials/mine.
        Route::get('/student-leave-applications/mine', [StudentLeaveApplicationController::class, 'mine']);
        Route::get('/student-leave-applications', [StudentLeaveApplicationController::class, 'index']);
        Route::get('/student-leave-applications/{student_leave_application}', [StudentLeaveApplicationController::class, 'show']);
        Route::patch('/student-leave-applications/{student_leave_application}/review', [StudentLeaveApplicationController::class, 'review']);
```

Add immediately after it:

```php
        Route::post('/teacher-leave-applications', [TeacherLeaveApplicationController::class, 'store']);
        // "mine" and "colleagues" must be registered before the
        // {teacher_leave_application} wildcard show route below, same
        // gotcha as student-leave-applications/mine.
        Route::get('/teacher-leave-applications/mine', [TeacherLeaveApplicationController::class, 'mine']);
        Route::get('/teacher-leave-applications/colleagues', [TeacherLeaveApplicationController::class, 'colleagues']);
        Route::get('/teacher-leave-applications', [TeacherLeaveApplicationController::class, 'index']);
        Route::get('/teacher-leave-applications/{teacher_leave_application}', [TeacherLeaveApplicationController::class, 'show']);
        Route::patch('/teacher-leave-applications/{teacher_leave_application}/review', [TeacherLeaveApplicationController::class, 'review']);
```

At the top of the file, find:

```php
use App\Http\Controllers\Api\V1\SubjectTeacherAssignmentController;
use App\Http\Controllers\Api\V1\TeacherProfileController;
```

Insert the new import alphabetically between them:

```php
use App\Http\Controllers\Api\V1\SubjectTeacherAssignmentController;
use App\Http\Controllers\Api\V1\TeacherLeaveApplicationController;
use App\Http\Controllers\Api\V1\TeacherProfileController;
```

- [ ] **Step 8: Add the permissions**

In `backend/database/seeders/PermissionSeeder.php`, find:

```php
            ['name' => 'student_leave_applications.view', 'label' => 'View student leave applications'],
            ['name' => 'student_leave_applications.update', 'label' => 'Review student leave applications'],
```

Add immediately after it:

```php
            // A teacher submits their own leave application and names a
            // substitute colleague outside this permission system
            // entirely (StoreTeacherLeaveApplicationRequest just checks
            // the actor is a TeacherUser) — these two only gate a staff
            // actor's ability to list/review every teacher's application.
            // Unlike student_leave_applications.update, reviewing here
            // has no additional per-row ownership check: the reviewer is
            // always school_admin, scoped only by TenantScope.
            ['name' => 'teacher_leave_applications.view', 'label' => 'View teacher leave applications'],
            ['name' => 'teacher_leave_applications.update', 'label' => 'Review teacher leave applications'],
```

- [ ] **Step 9: Grant the permissions to `school_admin`**

In `backend/database/seeders/RolePermissionSeeder.php`, find (inside the `'school_admin' => [...]` array):

```php
                'student_leave_applications.view', 'student_leave_applications.update',
                'attendances.view', 'attendances.update',
```

Change to:

```php
                'student_leave_applications.view', 'student_leave_applications.update',
                'teacher_leave_applications.view', 'teacher_leave_applications.update',
                'attendances.view', 'attendances.update',
```

- [ ] **Step 10: Write the permission-mapping drift guard test**

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherLeaveApplicationPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Guards the exact role list teacher_leave_applications.view/.update
     * are mapped to — school_admin explicitly in RolePermissionSeeder;
     * national_admin via its all-permissions wildcard. Granting either to
     * any other role (including teacher — submission/mine/colleagues are
     * all self-service bypass, not permission-gated) would be a real
     * authorization regression.
     */
    public function test_teacher_leave_applications_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = ['national_admin', 'school_admin'];

        foreach (['teacher_leave_applications.view', 'teacher_leave_applications.update'] as $permissionName) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            $this->assertSame($expected, $roles, "Expected {$permissionName} to be mapped to exactly national_admin and school_admin.");
        }
    }
}
```

- [ ] **Step 11: Run this test to verify it passes**

Run: `cd backend && php artisan test --filter=TeacherLeaveApplicationPermissionMappingTest --compact`
Expected: PASS (1 test, 2 assertions)

- [ ] **Step 12: Write the controller feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherUser;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherLeaveApplicationControllerTest extends TestCase
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

    public function test_teacher_can_submit_a_leave_application_scoped_to_their_own_school(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'reason' => 'Family event',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.delegate_teacher_id', null);

        $this->assertDatabaseHas('teacher_leave_applications', [
            'teacher_id' => $teacher->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'status' => 'pending',
        ]);
    }

    public function test_teacher_can_name_a_colleague_at_the_same_school_as_substitute(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $colleague = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-03',
            'reason' => 'Family event',
            'delegate_teacher_id' => $colleague->id,
        ])->assertCreated()->assertJsonPath('data.delegate.id', $colleague->id);
    }

    public function test_store_rejects_naming_yourself_as_substitute(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'reason' => 'Family event',
            'delegate_teacher_id' => $teacher->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('delegate_teacher_id');
    }

    public function test_store_rejects_a_substitute_from_a_different_school(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $otherSchoolTeacher = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'reason' => 'Family event',
            'delegate_teacher_id' => $otherSchoolTeacher->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('delegate_teacher_id');
    }

    public function test_store_rejects_to_date_before_from_date(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-05', 'to_date' => '2026-12-01', 'reason' => 'Family event',
        ])->assertUnprocessable()->assertJsonValidationErrors('to_date');
    }

    public function test_store_rejects_an_overlapping_existing_request(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-10', 'reason' => 'First request',
        ])->assertCreated();

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-05', 'to_date' => '2026-12-15', 'reason' => 'Overlapping request',
        ])->assertUnprocessable()->assertJsonValidationErrors('from_date');
    }

    public function test_store_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([]);

        $this->actingAs($staff, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-03', 'reason' => 'Family event',
        ])->assertForbidden();
    }

    public function test_mine_returns_only_this_teachers_own_applications(): void
    {
        $teacherA = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'A',
        ])->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'B',
        ])->assertCreated();

        $this->actingAs($teacherA, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'A');
    }

    public function test_colleagues_returns_only_other_teachers_at_the_same_school(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $colleague = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $otherSchoolTeacher = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications/colleagues')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($colleague->id));
        $this->assertFalse($ids->contains($teacher->id));
        $this->assertFalse($ids->contains($otherSchoolTeacher->id));
    }

    public function test_school_admin_sees_only_their_own_schools_applications(): void
    {
        $teacherA = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'A',
        ])->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'B',
        ])->assertCreated();

        $schoolAdminA = $this->actorWithPermissions(['teacher_leave_applications.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'A');
    }

    public function test_national_admin_sees_every_schools_applications(): void
    {
        $teacherA = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions([], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'A',
        ])->assertCreated();

        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'B',
        ])->assertCreated();

        $nationalAdmin = $this->actorWithPermissions(['teacher_leave_applications.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications')
            ->assertForbidden();
    }

    public function test_school_admin_can_approve_an_application(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $applicationId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $schoolAdmin = $this->actorWithPermissions(['teacher_leave_applications.view', 'teacher_leave_applications.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/teacher-leave-applications/{$applicationId}/review", [
                'status' => 'approved',
                'admin_comment' => 'Get well soon',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.admin_comment', 'Get well soon')
            ->assertJsonPath('data.reviewed_by.id', $schoolAdmin->id);
    }

    public function test_review_is_forbidden_for_a_teacher_without_the_update_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $applicationId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($otherTeacher, 'sanctum')
            ->patchJson("/api/v1/teacher-leave-applications/{$applicationId}/review", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_review_rejects_an_invalid_status(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $applicationId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/teacher-leave-applications', [
            'from_date' => '2026-12-01', 'to_date' => '2026-12-02', 'reason' => 'Sick',
        ])->json('data.id');

        $schoolAdmin = $this->actorWithPermissions(['teacher_leave_applications.view', 'teacher_leave_applications.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->patchJson("/api/v1/teacher-leave-applications/{$applicationId}/review", ['status' => 'pending'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_index_can_filter_to_currently_active_applications(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $active = TeacherLeaveApplication::create([
            'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id,
            'from_date' => now()->subDay()->toDateString(), 'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Currently on leave', 'status' => 'approved',
        ]);

        TeacherLeaveApplication::create([
            'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id,
            'from_date' => now()->addDays(10)->toDateString(), 'to_date' => now()->addDays(12)->toDateString(),
            'reason' => 'Future leave, still pending', 'status' => 'pending',
        ]);

        $nationalAdmin = $this->actorWithPermissions(['teacher_leave_applications.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/teacher-leave-applications?current=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }
}
```

Note: `$this->actorWithPermissions([])` (no school/district args) creates an unscoped `User` actor — used here to stand in for `national_admin`-shaped access in the two tests that don't need a specific role name, matching the exact convention already used throughout this codebase's test suite (e.g. `UserAuthorizationTest`).

- [ ] **Step 13: Run the full new test file to verify it fails first (TDD red)**

Run: `cd backend && php artisan test --filter=TeacherLeaveApplicationControllerTest --compact`
Expected: FAIL — classes/routes referenced don't exist yet if Steps 1-9 weren't done first. Since Steps 1-9 precede this in the task, this should actually now PASS. If any assertion fails, read the failure message and fix the controller/policy/request code above to match — do not change the test's expectations without re-checking them against the spec.

- [ ] **Step 14: Run migrations, then run both new test files to verify they pass**

Run: `cd backend && php artisan migrate`
Run: `cd backend && php artisan test --filter=TeacherLeaveApplication --compact`
Expected: PASS (both test classes, all tests green)

- [ ] **Step 15: Run Pint and the full backend suite**

Run: `cd backend && vendor/bin/pint --dirty --format agent`
Expected: `{"tool":"pint","result":"passed"}` (fix any reported issues, re-run)

Run: `cd backend && php artisan test --compact`
Expected: PASS, no regressions (total test count increased by the number of tests added in Steps 10 and 12)

- [ ] **Step 16: Commit**

```bash
git add backend/database/migrations/2026_11_25_160000_create_teacher_leave_applications_table.php \
  backend/app/Models/TeacherLeaveApplication.php \
  backend/app/Policies/TeacherLeaveApplicationPolicy.php \
  backend/app/Http/Requests/Api/V1/StoreTeacherLeaveApplicationRequest.php \
  backend/app/Http/Requests/Api/V1/ReviewTeacherLeaveApplicationRequest.php \
  backend/app/Http/Resources/Api/V1/TeacherLeaveApplicationResource.php \
  backend/app/Http/Controllers/Api/V1/TeacherLeaveApplicationController.php \
  backend/routes/api.php \
  backend/database/seeders/PermissionSeeder.php \
  backend/database/seeders/RolePermissionSeeder.php \
  backend/tests/Feature/TeacherLeaveApplicationControllerTest.php \
  backend/tests/Feature/TeacherLeaveApplicationPermissionMappingTest.php
git commit -m "Add TeacherLeaveApplication resource: teacher self-service leave submission reviewed by school_admin"
```

---

### Task 2: Backend — substitute delegation (AttendancePolicy + AttendanceController)

**Files:**
- Modify: `backend/app/Policies/AttendancePolicy.php`
- Modify: `backend/app/Http/Controllers/Api/V1/AttendanceController.php` (`mySections()` only)
- Test: `backend/tests/Feature/AttendanceDelegationTest.php`

**Interfaces:**
- Consumes: `App\Models\TeacherLeaveApplication` from Task 1 (fields `teacher_id`, `delegate_teacher_id`, `status`, `from_date`, `to_date`).
- Produces: `AttendancePolicy::viewSection()`/`recordSection()` now also return `true` for a `TeacherUser` named as an approved, currently-in-range delegate of the section's `class_teacher_id`. `AttendanceController::mySections()`'s response array gains delegated sections with `can_record: true`.

- [ ] **Step 1: Write the failing regression test**

```php
<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\TeacherLeaveApplication;
use App\Models\Upazila;
use App\Models\Version;
use App\Models\ClassVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AttendanceDelegationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);
    }

    private function studentInSection(): StudentProfile
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);

        return StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'section_id' => $this->section->id,
            'roll_number' => '1',
        ]);
    }

    public function test_an_approved_delegate_can_record_attendance_during_the_leave_window(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [
                ['student_profile_id' => $profile->id, 'status' => 'present'],
            ],
        ])->assertOk()->assertJsonPath('data.students.0.status', 'present');
    }

    public function test_a_named_delegate_cannot_record_before_the_application_is_approved(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'pending',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_a_delegate_cannot_record_outside_the_leave_date_range(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(7)->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($substitute, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_a_teacher_not_named_as_delegate_cannot_record(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $uninvolvedTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $profile = $this->studentInSection();

        $this->actingAs($uninvolvedTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertForbidden();
    }

    public function test_my_sections_lists_the_delegated_section_with_can_record_true(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $substitute = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        TeacherLeaveApplication::create([
            'teacher_id' => $classTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'from_date' => now()->subDay()->toDateString(),
            'to_date' => now()->addDay()->toDateString(),
            'reason' => 'Sick',
            'status' => 'approved',
            'delegate_teacher_id' => $substitute->id,
        ]);

        $response = $this->actingAs($substitute, 'sanctum')
            ->getJson('/api/v1/attendances/my-sections')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $this->section->id);
        $this->assertNotNull($row);
        $this->assertTrue($row['can_record']);
    }

    /**
     * Non-regression: an ordinary class teacher with no delegation
     * involved records exactly as before.
     */
    public function test_the_class_teacher_can_still_record_their_own_section_normally(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $profile = $this->studentInSection();

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/attendances/record', [
            'section_id' => $this->section->id,
            'date' => now()->toDateString(),
            'records' => [['student_profile_id' => $profile->id, 'status' => 'present']],
        ])->assertOk();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd backend && php artisan test --filter=AttendanceDelegationTest --compact`
Expected: FAIL — `test_an_approved_delegate_can_record_attendance_during_the_leave_window` and the `my_sections` test fail (403/missing section), since `AttendancePolicy`/`AttendanceController` don't know about delegation yet. The two "cannot record" tests and the non-regression test should already pass (nothing has changed their behavior yet).

- [ ] **Step 3: Update `AttendancePolicy`**

Replace the full contents of `backend/app/Policies/AttendancePolicy.php` with:

```php
<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherLeaveApplication;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class AttendancePolicy
{
    /**
     * Whether $user may view (roster or monthly report) attendance for
     * $section — the section's own class teacher, any teacher holding a
     * SubjectTeacherAssignment there (view-only), an approved substitute
     * delegate standing in for the class teacher during their leave, or a
     * staff actor with attendances.view (scoped only by TenantScope).
     */
    public function viewSection(Authenticatable $user, Section $section): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || SubjectTeacherAssignment::where('section_id', $section->id)->where('teacher_id', $user->id)->exists()
                || $this->isApprovedDelegateFor($user, $section);
        }

        return $user->hasPermission('attendances.view');
    }

    /**
     * Whether $user may record/update attendance for $section — the
     * section's own class teacher, or an approved substitute delegate
     * standing in for them during their leave (see isApprovedDelegateFor
     * below). Every other teacher assigned there is view-only. A non-
     * teacher staff actor with the broader attendances.update permission
     * can still override, scoped only by TenantScope.
     */
    public function recordSection(Authenticatable $user, Section $section): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || $this->isApprovedDelegateFor($user, $section);
        }

        return $user->hasPermission('attendances.update');
    }

    /**
     * $user stands in for $section's own class teacher today: an
     * approved TeacherLeaveApplication names $user as the substitute for
     * $section's class_teacher_id, and today falls within its date
     * range. Evaluated fresh on every call — no flag to flip when the
     * leave starts or ends, just this date-range-and-status predicate
     * (the same one TeacherLeaveApplicationController::index()'s
     * `current=1` filter and the national_admin "Teachers on Leave" view
     * use).
     */
    private function isApprovedDelegateFor(TeacherUser $user, Section $section): bool
    {
        if ($section->class_teacher_id === null) {
            return false;
        }

        return TeacherLeaveApplication::query()
            ->where('teacher_id', $section->class_teacher_id)
            ->where('delegate_teacher_id', $user->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', now())
            ->whereDate('to_date', '>=', now())
            ->exists();
    }
}
```

- [ ] **Step 4: Update `AttendanceController::mySections()`**

In `backend/app/Http/Controllers/Api/V1/AttendanceController.php`, add the import near the top (alphabetical within the existing `App\Models` import block):

```php
use App\Models\TeacherLeaveApplication;
```

Replace the full `mySections()` method with:

```php
    /**
     * Sections this teacher may touch attendance for — the ones they're
     * the class teacher of (can_record: true), any they hold an approved
     * substitute delegation for (can_record: true), plus any they only
     * hold a SubjectTeacherAssignment in (can_record: false, view only).
     */
    public function mySections(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $delegatedClassTeacherIds = TeacherLeaveApplication::query()
            ->where('delegate_teacher_id', $teacher->id)
            ->where('status', 'approved')
            ->whereDate('from_date', '<=', now())
            ->whereDate('to_date', '>=', now())
            ->pluck('teacher_id');

        $sections = Section::query()
            ->with(['shift.classVersion.schoolClass', 'shift.academicYear'])
            ->where(function ($query) use ($teacher, $delegatedClassTeacherIds) {
                $query->where('class_teacher_id', $teacher->id)
                    ->orWhereIn('class_teacher_id', $delegatedClassTeacherIds)
                    ->orWhereHas('subjectTeacherAssignments', fn ($assignments) => $assignments->where('teacher_id', $teacher->id));
            })
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $sections->map(fn (Section $section) => [
                'id' => $section->id,
                'name' => $section->bn_name ?? $section->name,
                'class_id' => $section->shift?->classVersion?->class_id,
                'class_name' => $section->shift?->classVersion?->schoolClass?->name,
                'class_version_id' => $section->shift?->class_version_id,
                'class_version' => $section->shift?->classVersion?->name,
                'shift_id' => $section->shift_id,
                'shift' => $section->shift?->name,
                'academic_year_id' => $section->shift?->academic_year_id,
                'academic_year' => $section->shift?->academicYear?->name,
                'can_record' => $section->class_teacher_id === $teacher->id
                    || $delegatedClassTeacherIds->contains($section->class_teacher_id),
            ])->values(),
        ]);
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `cd backend && php artisan test --filter=AttendanceDelegationTest --compact`
Expected: PASS (6 tests)

- [ ] **Step 6: Run Pint and the full backend suite**

Run: `cd backend && vendor/bin/pint --dirty --format agent`
Expected: `{"tool":"pint","result":"passed"}`

Run: `cd backend && php artisan test --compact`
Expected: PASS, no regressions — specifically confirm `AttendanceControllerTest` (the pre-existing suite) is still fully green, proving ordinary (non-delegated) attendance recording is unaffected.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Policies/AttendancePolicy.php \
  backend/app/Http/Controllers/Api/V1/AttendanceController.php \
  backend/tests/Feature/AttendanceDelegationTest.php
git commit -m "Let an approved leave's named substitute record attendance for the applicant's homeroom section"
```

---

### Task 3: Frontend — api-client types and functions

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`

**Interfaces:**
- Produces: `TeacherLeaveApplicationStatus`, `TeacherLeaveApplication`, `TeacherColleague` types; `createTeacherLeaveApplication`, `listMyTeacherLeaveApplications`, `listTeacherColleagues`, `listTeacherLeaveApplications`, `getTeacherLeaveApplication`, `reviewTeacherLeaveApplication` functions. Tasks 4, 5, and 6 all import from here.

- [ ] **Step 1: Append the new section to the end of `frontend/packages/api-client/src/index.ts`**

```ts
// ---------- Teacher leave applications ----------

export type TeacherLeaveApplicationStatus = 'pending' | 'approved' | 'rejected'

export interface TeacherColleague {
  id: number
  name: string
}

export interface TeacherLeaveApplication {
  id: number
  teacher_id: number
  school_id: number
  district_id: number | null
  from_date: string
  to_date: string
  reason: string
  status: TeacherLeaveApplicationStatus
  delegate_teacher_id: number | null
  admin_comment: string | null
  reviewed_by_id: number | null
  reviewed_at: string | null
  created_at: string
  teacher?: { id: number; name: string; school_name: string | null } | null
  delegate?: { id: number; name: string } | null
  reviewed_by?: { id: number; name: string } | null
  // Set server-side from TeacherLeaveApplicationPolicy::review() — true
  // only for an actor holding teacher_leave_applications.update, never
  // derived client-side.
  can_review: boolean
}

// A teacher submits their own leave application, optionally naming a
// substitute colleague at their own school — scoped server-side to the
// teacher's own school/district (see TeacherLeaveApplicationController::
// store()); the teacher never picks those themselves.
export function createTeacherLeaveApplication(
  payload: { from_date: string; to_date: string; reason: string; delegate_teacher_id?: number | null },
  token: string,
): Promise<{ data: TeacherLeaveApplication }> {
  return apiFetch<{ data: TeacherLeaveApplication }>('/teacher-leave-applications', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

// This teacher's own leave applications, most recent first.
export function listMyTeacherLeaveApplications(token: string): Promise<{ data: TeacherLeaveApplication[] }> {
  return apiFetch<{ data: TeacherLeaveApplication[] }>('/teacher-leave-applications/mine', {
    headers: authHeaders(token),
  })
}

// Other teachers at this teacher's own school, for the substitute picker
// — TenantScope narrows this to the caller's own school automatically.
export function listTeacherColleagues(token: string): Promise<{ data: TeacherColleague[] }> {
  return apiFetch<{ data: TeacherColleague[] }>('/teacher-leave-applications/colleagues', {
    headers: authHeaders(token),
  })
}

// Staff listing — any actor with teacher_leave_applications.view sees
// every application in their own school (TenantScope); `current: true`
// narrows to applications in effect right now (approved, today within
// range) — the "who is on leave right now" view.
export function listTeacherLeaveApplications(
  params: { status?: TeacherLeaveApplicationStatus; current?: boolean } = {},
  token: string,
): Promise<{ data: TeacherLeaveApplication[] }> {
  const query = buildQuery({ status: params.status, current: params.current ? 1 : undefined })

  return apiFetch<{ data: TeacherLeaveApplication[] }>(`/teacher-leave-applications${query}`, {
    headers: authHeaders(token),
  })
}

export function getTeacherLeaveApplication(id: number, token: string): Promise<{ data: TeacherLeaveApplication }> {
  return apiFetch<{ data: TeacherLeaveApplication }>(`/teacher-leave-applications/${id}`, {
    headers: authHeaders(token),
  })
}

// Approve or reject, with an optional comment — only valid for a row
// whose `can_review` came back true.
export function reviewTeacherLeaveApplication(
  id: number,
  payload: { status: 'approved' | 'rejected'; admin_comment?: string | null },
  token: string,
): Promise<{ data: TeacherLeaveApplication }> {
  return apiFetch<{ data: TeacherLeaveApplication }>(`/teacher-leave-applications/${id}/review`, {
    method: 'PATCH',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}
```

- [ ] **Step 2: Type-check the package**

Run: `cd frontend/packages/api-client && npx tsc --noEmit -p .`
Expected: no output (clean)

- [ ] **Step 3: Run the existing api-client test suite to confirm no regressions**

Run: `cd frontend && npx vitest run packages/api-client`
Expected: PASS, same test count as before (this task adds no new tests — the existing suite doesn't unit-test every endpoint function, only query-building helpers; matches the established convention of not testing `student-leave-applications`' functions either)

- [ ] **Step 4: Commit**

```bash
git add frontend/packages/api-client/src/index.ts
git commit -m "Add TeacherLeaveApplication types and client functions to api-client"
```

---

### Task 4: Frontend — teacher portal "Teacher Leave" page

**Files:**
- Create: `frontend/apps/teacher/src/pages/TeacherLeavePage.tsx`
- Modify: `frontend/apps/teacher/src/App.tsx` (route)
- Modify: `frontend/apps/teacher/src/components/TeacherAppShell.tsx` (nav item)
- Modify: `frontend/packages/i18n/src/common/en.json` (new keys)
- Modify: `frontend/packages/i18n/src/common/bn.json` (new keys)

**Interfaces:**
- Consumes: `createTeacherLeaveApplication`, `listMyTeacherLeaveApplications`, `listTeacherColleagues`, `TeacherLeaveApplication`, `TeacherColleague` from `@ttla/api-client` (Task 3); `useToast`/`ToastViewport` from `../components/Toast`; `Pagination` from `../components/Pagination`.

- [ ] **Step 1: Add the new i18n keys**

In `frontend/packages/i18n/src/common/en.json`, find this line (the teacher nav block):

```json
  "teacher.nav.leaveApplications": "Student Leave",
```

Add immediately after it:

```json
  "teacher.nav.teacherLeave": "Teacher Leave",
```

Find this line (right after the leave-applications dashboard keys, e.g. near `"teacher.dashboard.leaveApplicationsDesc"`) and add a new block right after it:

```json
  "teacher.teacherLeave.title": "Teacher Leave",
  "teacher.teacherLeave.formTitle": "Request leave",
  "teacher.teacherLeave.fromDate": "From date",
  "teacher.teacherLeave.toDate": "To date",
  "teacher.teacherLeave.reason": "Reason",
  "teacher.teacherLeave.reasonPlaceholder": "Why are you requesting leave?",
  "teacher.teacherLeave.delegate": "Substitute teacher (optional)",
  "teacher.teacherLeave.delegateNone": "No substitute",
  "teacher.teacherLeave.delegateHint": "If you are the class teacher of a section, naming a substitute lets them record attendance for it while you're away.",
  "teacher.teacherLeave.submit": "Submit request",
  "teacher.teacherLeave.submitting": "Submitting...",
  "teacher.teacherLeave.submitSuccess": "Leave request submitted.",
  "teacher.teacherLeave.submitError": "Could not submit your leave request. Please try again.",
  "teacher.teacherLeave.historyTitle": "My leave requests",
  "teacher.teacherLeave.loading": "Loading...",
  "teacher.teacherLeave.empty": "You haven't submitted any leave requests yet.",
  "teacher.teacherLeave.colFrom": "From",
  "teacher.teacherLeave.colTo": "To",
  "teacher.teacherLeave.colReason": "Reason",
  "teacher.teacherLeave.colSubstitute": "Substitute",
  "teacher.teacherLeave.colStatus": "Status",
  "teacher.teacherLeave.colComment": "Admin comment",
  "teacher.teacherLeave.noSubstitute": "—",
  "teacher.teacherLeave.previousPage": "Previous",
  "teacher.teacherLeave.nextPage": "Next",
```

Make the exact same two additions (key names identical, Bangla values) to `frontend/packages/i18n/src/common/bn.json`, in the same two locations (find `"teacher.nav.leaveApplications": "শিক্ষার্থীর ছুটি",` and the Bangla leave-applications dashboard block):

```json
  "teacher.nav.teacherLeave": "শিক্ষক ছুটি",
```

```json
  "teacher.teacherLeave.title": "শিক্ষক ছুটি",
  "teacher.teacherLeave.formTitle": "ছুটির আবেদন করুন",
  "teacher.teacherLeave.fromDate": "শুরুর তারিখ",
  "teacher.teacherLeave.toDate": "শেষের তারিখ",
  "teacher.teacherLeave.reason": "কারণ",
  "teacher.teacherLeave.reasonPlaceholder": "কেন ছুটি নিতে চান?",
  "teacher.teacherLeave.delegate": "বিকল্প শিক্ষক (ঐচ্ছিক)",
  "teacher.teacherLeave.delegateNone": "কোনো বিকল্প শিক্ষক নেই",
  "teacher.teacherLeave.delegateHint": "আপনি যদি কোনো শাখার শ্রেণি শিক্ষক হন, তাহলে একজন বিকল্প শিক্ষক নির্বাচন করলে তিনি আপনার অনুপস্থিতিতে সেই শাখার উপস্থিতি রেকর্ড করতে পারবেন।",
  "teacher.teacherLeave.submit": "আবেদন জমা দিন",
  "teacher.teacherLeave.submitting": "জমা হচ্ছে...",
  "teacher.teacherLeave.submitSuccess": "ছুটির আবেদন জমা হয়েছে।",
  "teacher.teacherLeave.submitError": "আপনার ছুটির আবেদন জমা দেওয়া যায়নি। আবার চেষ্টা করুন।",
  "teacher.teacherLeave.historyTitle": "আমার ছুটির আবেদন সমূহ",
  "teacher.teacherLeave.loading": "লোড হচ্ছে...",
  "teacher.teacherLeave.empty": "আপনি এখনো কোনো ছুটির আবেদন জমা দেননি।",
  "teacher.teacherLeave.colFrom": "শুরু",
  "teacher.teacherLeave.colTo": "শেষ",
  "teacher.teacherLeave.colReason": "কারণ",
  "teacher.teacherLeave.colSubstitute": "বিকল্প শিক্ষক",
  "teacher.teacherLeave.colStatus": "অবস্থা",
  "teacher.teacherLeave.colComment": "প্রশাসকের মন্তব্য",
  "teacher.teacherLeave.noSubstitute": "—",
  "teacher.teacherLeave.previousPage": "পূর্ববর্তী",
  "teacher.teacherLeave.nextPage": "পরবর্তী",
```

- [ ] **Step 2: Validate both JSON files and type-check the i18n package**

Run: `cd frontend/packages/i18n/src/common && node -e "JSON.parse(require('fs').readFileSync('en.json','utf8')); JSON.parse(require('fs').readFileSync('bn.json','utf8')); console.log('ok')"`
Expected: `ok`

Run: `cd frontend/packages/i18n && npx tsc --noEmit -p .`
Expected: no output (clean — confirms `bn.json` has every key `en.json` has)

- [ ] **Step 3: Write `TeacherLeavePage.tsx`**

```tsx
import { useEffect, useState, type FormEvent } from 'react'
import {
  ApiError,
  createTeacherLeaveApplication,
  listMyTeacherLeaveApplications,
  listTeacherColleagues,
  type TeacherColleague,
  type TeacherLeaveApplication,
  type TeacherLeaveApplicationStatus,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import Pagination from '../components/Pagination'

const t = getCommonStrings('en')

const PAGE_SIZE = 10

const STATUS_BADGE: Record<TeacherLeaveApplicationStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-success-50 text-success-600',
  rejected: 'bg-danger-50 text-danger-600',
}

const STATUS_LABEL: Record<TeacherLeaveApplicationStatus, string> = {
  pending: t['teacher.leaveApplications.statusPending'],
  approved: t['teacher.leaveApplications.statusApproved'],
  rejected: t['teacher.leaveApplications.statusRejected'],
}

// A teacher submits their own leave request here (scoped server-side to
// their own school by TeacherLeaveApplicationController::store()),
// optionally naming a colleague at the same school as a substitute — if
// approved, that substitute gains the ability to record attendance for
// every section this teacher is the class teacher of, for the leave's
// exact date range (see AttendancePolicy::isApprovedDelegateFor()). No
// edit/cancel action: if the dates are wrong, school_admin rejects it and
// the teacher resubmits, same as the student-leave flow.
export default function TeacherLeavePage() {
  const { token } = useAuth()

  const [fromDate, setFromDate] = useState('')
  const [toDate, setToDate] = useState('')
  const [reason, setReason] = useState('')
  const [delegateId, setDelegateId] = useState('')
  const [colleagues, setColleagues] = useState<TeacherColleague[]>([])
  const [submitting, setSubmitting] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  const [submitSuccess, setSubmitSuccess] = useState(false)

  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<TeacherLeaveApplication[]>([])
  const [page, setPage] = useState(1)

  function loadApplications() {
    if (!token) return

    setLoading(true)

    listMyTeacherLeaveApplications(token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadApplications()
  }, [token])

  useEffect(() => {
    if (!token) return

    listTeacherColleagues(token)
      .then((result) => setColleagues(result.data))
      .catch(() => setColleagues([]))
  }, [token])

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!token) return

    setSubmitting(true)
    setFormError(null)
    setSubmitSuccess(false)

    try {
      await createTeacherLeaveApplication(
        {
          from_date: fromDate,
          to_date: toDate,
          reason,
          delegate_teacher_id: delegateId ? Number(delegateId) : null,
        },
        token,
      )
      setFromDate('')
      setToDate('')
      setReason('')
      setDelegateId('')
      setSubmitSuccess(true)
      loadApplications()
    } catch (caught) {
      setFormError(caught instanceof ApiError ? caught.message : t['teacher.teacherLeave.submitError'])
    } finally {
      setSubmitting(false)
    }
  }

  const totalPages = Math.max(1, Math.ceil(applications.length / PAGE_SIZE))
  const currentPage = Math.min(page, totalPages)
  const visibleApplications = applications.slice((currentPage - 1) * PAGE_SIZE, currentPage * PAGE_SIZE)

  return (
    <section className="px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.teacherLeave.title']}</h1>

      <div className="mt-4 max-w-xl rounded-md bg-white p-5 shadow-sm">
        <h2 className="text-sm font-semibold text-primary-800">{t['teacher.teacherLeave.formTitle']}</h2>

        <form className="mt-4 flex flex-col gap-4" onSubmit={handleSubmit}>
          <div className="flex flex-wrap gap-4">
            <label className="flex flex-1 flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['teacher.teacherLeave.fromDate']}</span>
              <input
                type="date"
                required
                className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
                value={fromDate}
                onChange={(event) => setFromDate(event.target.value)}
              />
            </label>
            <label className="flex flex-1 flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['teacher.teacherLeave.toDate']}</span>
              <input
                type="date"
                required
                min={fromDate || undefined}
                className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
                value={toDate}
                onChange={(event) => setToDate(event.target.value)}
              />
            </label>
          </div>

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['teacher.teacherLeave.reason']}</span>
            <textarea
              required
              className="min-h-[90px] rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              placeholder={t['teacher.teacherLeave.reasonPlaceholder']}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
          </label>

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['teacher.teacherLeave.delegate']}</span>
            <select
              className="h-11 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              value={delegateId}
              onChange={(event) => setDelegateId(event.target.value)}
            >
              <option value="">{t['teacher.teacherLeave.delegateNone']}</option>
              {colleagues.map((colleague) => (
                <option key={colleague.id} value={colleague.id}>
                  {colleague.name}
                </option>
              ))}
            </select>
            <span className="text-xs text-gray-400">{t['teacher.teacherLeave.delegateHint']}</span>
          </label>

          {formError && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{formError}</p>}
          {submitSuccess && (
            <p className="rounded-md bg-success-50 px-4 py-3 text-sm text-success-700">
              {t['teacher.teacherLeave.submitSuccess']}
            </p>
          )}

          <button
            type="submit"
            disabled={submitting}
            className="cursor-pointer self-start rounded-md bg-primary-500 px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {submitting ? t['teacher.teacherLeave.submitting'] : t['teacher.teacherLeave.submit']}
          </button>
        </form>
      </div>

      <h2 className="mt-10 text-lg font-semibold text-primary-800">{t['teacher.teacherLeave.historyTitle']}</h2>

      {loading && <p className="mt-3 text-sm text-primary-600">{t['teacher.teacherLeave.loading']}</p>}

      {!loading && applications.length === 0 && (
        <p className="mt-3 text-sm text-gray-500">{t['teacher.teacherLeave.empty']}</p>
      )}

      {!loading && applications.length > 0 && (
        <>
          <div className="mt-3 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[48rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colFrom']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colTo']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colReason']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colSubstitute']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colStatus']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.teacherLeave.colComment']}</th>
                </tr>
              </thead>
              <tbody>
                {visibleApplications.map((application, index) => (
                  <tr key={application.id} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                      {(currentPage - 1) * PAGE_SIZE + index + 1}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.from_date}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.to_date}</td>
                    <td className="max-w-[18rem] px-5 py-3.5 text-gray-700">{application.reason}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">
                      {application.delegate?.name ?? t['teacher.teacherLeave.noSubstitute']}
                    </td>
                    <td className="px-5 py-3.5 whitespace-nowrap">
                      <span className={`rounded-sm px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[application.status]}`}>
                        {STATUS_LABEL[application.status]}
                      </span>
                    </td>
                    <td className="max-w-[14rem] px-5 py-3.5 text-gray-500">{application.admin_comment ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <Pagination
            currentPage={currentPage}
            totalPages={totalPages}
            onPageChange={(targetPage) => setPage(targetPage)}
            visibleCount={visibleApplications.length}
            total={applications.length}
            showingLabel={t['management.table.showing']}
            ofLabel={t['management.table.of']}
            resultsLabel={t['management.table.results']}
            previousLabel={t['teacher.teacherLeave.previousPage']}
            nextLabel={t['teacher.teacherLeave.nextPage']}
          />
        </>
      )}
    </section>
  )
}
```

(Before writing this file, open `frontend/apps/teacher/src/components/Pagination.tsx` and confirm its prop names match exactly what's used above — `currentPage`, `totalPages`, `onPageChange`, `visibleCount`, `total`, `showingLabel`, `ofLabel`, `resultsLabel`, `previousLabel`, `nextLabel`. These are copied from the working usage in `frontend/apps/teacher/src/pages/LeaveApplicationsPage.tsx`'s own `<Pagination>` call — if that file's call site has since changed, match the current one instead of this plan's copy.)

- [ ] **Step 4: Wire the route**

In `frontend/apps/teacher/src/App.tsx`, this file's page imports are in chronological/insertion order, not alphabetical (`TeacherLogin, DashboardHomePage, LeaveApplicationsPage, HolidaysPage, AttendancePage, ClassRoutinePage, MarksEntryPage, MarksheetsPage, ClassMaterialsPage`). Add the new import as the last line in that import group, after `import ClassMaterialsPage from './pages/ClassMaterialsPage'`:

```tsx
import TeacherLeavePage from './pages/TeacherLeavePage'
```

Add the route, right after the existing `student-leave` route:

```tsx
              <Route path="student-leave" element={<LeaveApplicationsPage />} />
              <Route path="teacher-leave" element={<TeacherLeavePage />} />
```

- [ ] **Step 5: Wire the nav item**

In `frontend/apps/teacher/src/components/TeacherAppShell.tsx`, find:

```tsx
  { to: '/student-leave', label: t['teacher.nav.leaveApplications'], icon: 'event_busy' },
```

Add immediately after it:

```tsx
  { to: '/teacher-leave', label: t['teacher.nav.teacherLeave'], icon: 'work_off' },
```

- [ ] **Step 6: Type-check the teacher app**

Run: `cd frontend/apps/teacher && npx tsc --noEmit -p .`
Expected: no output (clean)

- [ ] **Step 7: Run the frontend test suite**

Run: `cd frontend && npx vitest run`
Expected: PASS, same test count as before (no new tests added by this task — the teacher app has no existing test suite to extend, matching its current state)

- [ ] **Step 8: Commit**

```bash
git add frontend/apps/teacher/src/pages/TeacherLeavePage.tsx \
  frontend/apps/teacher/src/App.tsx \
  frontend/apps/teacher/src/components/TeacherAppShell.tsx \
  frontend/packages/i18n/src/common/en.json \
  frontend/packages/i18n/src/common/bn.json
git commit -m "Add teacher portal Teacher Leave page: submit a request, name a substitute, view own history"
```

---

### Task 5: Frontend — management portal, school_admin review page

**Files:**
- Create: `frontend/apps/management/src/pages/TeacherLeaveApplicationsPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx` (new `RequireSchoolAdmin` guard, route, nav item)
- Modify: `frontend/packages/i18n/src/common/en.json` (new keys)
- Modify: `frontend/packages/i18n/src/common/bn.json` (new keys)

**Interfaces:**
- Consumes: `listTeacherLeaveApplications`, `reviewTeacherLeaveApplication`, `TeacherLeaveApplication`, `TeacherLeaveApplicationStatus` from `@ttla/api-client` (Task 3); `useToast`/`ToastViewport` from `../components/Toast`; `buttonNeutral`, `buttonPrimary` from `../components/styles`.

- [ ] **Step 1: Add the new i18n keys**

In `frontend/packages/i18n/src/common/en.json`, find:

```json
  "management.nav.usersRoles": "Users & Roles",
```

Add immediately after the `management.nav.*` block it belongs to (right after the existing leave/attendance-adjacent nav keys — find `"management.nav.promoteStudents": "Promote Students",` and add right after it):

```json
  "management.nav.teacherLeaveApplications": "Teacher Leave",
  "management.nav.teachersOnLeave": "Teachers on Leave",
```

Then add a new block anywhere in the `management.*` section (e.g. right after the `management.users.title`/`management.roles.title`/`management.permissions.title` block added in the previous session):

```json
  "management.teacherLeaveApplications.title": "Teacher Leave Applications",
  "management.teacherLeaveApplications.colTeacher": "Teacher",
  "management.teacherLeaveApplications.colFrom": "From",
  "management.teacherLeaveApplications.colTo": "To",
  "management.teacherLeaveApplications.colReason": "Reason",
  "management.teacherLeaveApplications.colSubstitute": "Substitute",
  "management.teacherLeaveApplications.colStatus": "Status",
  "management.teacherLeaveApplications.colComment": "Comment",
  "management.teacherLeaveApplications.filterAllStatus": "All statuses",
  "management.teacherLeaveApplications.statusPending": "Pending",
  "management.teacherLeaveApplications.statusApproved": "Approved",
  "management.teacherLeaveApplications.statusRejected": "Rejected",
  "management.teacherLeaveApplications.approve": "Approve",
  "management.teacherLeaveApplications.reject": "Reject",
  "management.teacherLeaveApplications.reviewTitle": "Review leave request",
  "management.teacherLeaveApplications.commentLabel": "Comment (optional)",
  "management.teacherLeaveApplications.commentPlaceholder": "Add a note for the teacher...",
  "management.teacherLeaveApplications.submit": "Submit decision",
  "management.teacherLeaveApplications.submitting": "Submitting...",
  "management.teacherLeaveApplications.loading": "Loading...",
  "management.teacherLeaveApplications.empty": "No leave applications found.",
  "management.teachersOnLeave.title": "Teachers on Leave",
  "management.teachersOnLeave.colSchool": "School",
  "management.teachersOnLeave.loading": "Loading...",
  "management.teachersOnLeave.empty": "No teachers are currently on leave.",
```

Make the exact same additions (same key names, Bangla values) to `frontend/packages/i18n/src/common/bn.json`:

```json
  "management.nav.teacherLeaveApplications": "শিক্ষক ছুটি",
  "management.nav.teachersOnLeave": "ছুটিতে থাকা শিক্ষক",
```

```json
  "management.teacherLeaveApplications.title": "শিক্ষক ছুটির আবেদন",
  "management.teacherLeaveApplications.colTeacher": "শিক্ষক",
  "management.teacherLeaveApplications.colFrom": "শুরু",
  "management.teacherLeaveApplications.colTo": "শেষ",
  "management.teacherLeaveApplications.colReason": "কারণ",
  "management.teacherLeaveApplications.colSubstitute": "বিকল্প শিক্ষক",
  "management.teacherLeaveApplications.colStatus": "অবস্থা",
  "management.teacherLeaveApplications.colComment": "মন্তব্য",
  "management.teacherLeaveApplications.filterAllStatus": "সকল অবস্থা",
  "management.teacherLeaveApplications.statusPending": "অপেক্ষমাণ",
  "management.teacherLeaveApplications.statusApproved": "অনুমোদিত",
  "management.teacherLeaveApplications.statusRejected": "প্রত্যাখ্যাত",
  "management.teacherLeaveApplications.approve": "অনুমোদন করুন",
  "management.teacherLeaveApplications.reject": "প্রত্যাখ্যান করুন",
  "management.teacherLeaveApplications.reviewTitle": "ছুটির আবেদন পর্যালোচনা করুন",
  "management.teacherLeaveApplications.commentLabel": "মন্তব্য (ঐচ্ছিক)",
  "management.teacherLeaveApplications.commentPlaceholder": "শিক্ষকের জন্য একটি মন্তব্য লিখুন...",
  "management.teacherLeaveApplications.submit": "সিদ্ধান্ত জমা দিন",
  "management.teacherLeaveApplications.submitting": "জমা হচ্ছে...",
  "management.teacherLeaveApplications.loading": "লোড হচ্ছে...",
  "management.teacherLeaveApplications.empty": "কোনো ছুটির আবেদন পাওয়া যায়নি।",
  "management.teachersOnLeave.title": "ছুটিতে থাকা শিক্ষক",
  "management.teachersOnLeave.colSchool": "বিদ্যালয়",
  "management.teachersOnLeave.loading": "লোড হচ্ছে...",
  "management.teachersOnLeave.empty": "বর্তমানে কোনো শিক্ষক ছুটিতে নেই।",
```

- [ ] **Step 2: Validate both JSON files and type-check the i18n package**

Run: `cd frontend/packages/i18n/src/common && node -e "JSON.parse(require('fs').readFileSync('en.json','utf8')); JSON.parse(require('fs').readFileSync('bn.json','utf8')); console.log('ok')"`
Expected: `ok`

Run: `cd frontend/packages/i18n && npx tsc --noEmit -p .`
Expected: no output (clean)

- [ ] **Step 3: Write `TeacherLeaveApplicationsPage.tsx`**

```tsx
import { useEffect, useState } from 'react'
import {
  ApiError,
  listTeacherLeaveApplications,
  reviewTeacherLeaveApplication,
  type TeacherLeaveApplication,
  type TeacherLeaveApplicationStatus,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { buttonNeutral, buttonPrimary } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

const STATUS_BADGE: Record<TeacherLeaveApplicationStatus, string> = {
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-success-50 text-success-600',
  rejected: 'bg-danger-50 text-danger-600',
}

const STATUS_LABEL: Record<TeacherLeaveApplicationStatus, string> = {
  pending: t['management.teacherLeaveApplications.statusPending'],
  approved: t['management.teacherLeaveApplications.statusApproved'],
  rejected: t['management.teacherLeaveApplications.statusRejected'],
}

function ReviewModal({
  application,
  onClose,
  onReviewed,
}: {
  application: TeacherLeaveApplication
  onClose: () => void
  onReviewed: (updated: TeacherLeaveApplication) => void
}) {
  const { token } = useAuth()
  const [decision, setDecision] = useState<'approved' | 'rejected' | null>(null)
  const [comment, setComment] = useState(application.admin_comment ?? '')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function handleSubmit() {
    if (!decision || !token) return

    setSubmitting(true)
    setError(null)

    try {
      const result = await reviewTeacherLeaveApplication(
        application.id,
        { status: decision, admin_comment: comment.trim() || null },
        token,
      )
      onReviewed(result.data)
    } catch (caught) {
      setError(caught instanceof ApiError ? caught.message : String(caught))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/45 p-5" onClick={onClose}>
      <div
        className="w-full max-w-md overflow-hidden rounded-md bg-white shadow-2xl"
        onClick={(event) => event.stopPropagation()}
        role="dialog"
        aria-modal="true"
        aria-label={t['management.teacherLeaveApplications.reviewTitle']}
      >
        <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
          <h3 className="text-base font-semibold text-gray-800">{t['management.teacherLeaveApplications.reviewTitle']}</h3>
          <button
            type="button"
            className="cursor-pointer text-xl leading-none text-gray-400 transition-colors duration-150 hover:text-gray-600"
            onClick={onClose}
            aria-label={t['management.table.cancel']}
          >
            ×
          </button>
        </div>

        <div className="flex flex-col gap-4 p-5">
          <div className="text-sm text-gray-700">
            <p className="font-medium text-gray-900">{application.teacher?.name}</p>
            <p className="mt-1 text-gray-500">{application.reason}</p>
            <p className="mt-1 text-xs text-gray-400">
              {application.from_date} — {application.to_date}
            </p>
          </div>

          <div className="flex gap-3">
            <button
              type="button"
              onClick={() => setDecision('approved')}
              className={`flex-1 cursor-pointer rounded-md border px-3 py-2 text-sm font-semibold transition-colors ${
                decision === 'approved'
                  ? 'border-success-500 bg-success-50 text-success-600'
                  : 'border-gray-200 text-gray-600 hover:border-success-300'
              }`}
            >
              {t['management.teacherLeaveApplications.approve']}
            </button>
            <button
              type="button"
              onClick={() => setDecision('rejected')}
              className={`flex-1 cursor-pointer rounded-md border px-3 py-2 text-sm font-semibold transition-colors ${
                decision === 'rejected'
                  ? 'border-danger-500 bg-danger-50 text-danger-600'
                  : 'border-gray-200 text-gray-600 hover:border-danger-300'
              }`}
            >
              {t['management.teacherLeaveApplications.reject']}
            </button>
          </div>

          <label className="flex flex-col gap-1.5 text-sm">
            <span className="font-medium text-gray-700">{t['management.teacherLeaveApplications.commentLabel']}</span>
            <textarea
              className="min-h-[80px] rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              placeholder={t['management.teacherLeaveApplications.commentPlaceholder']}
              value={comment}
              onChange={(event) => setComment(event.target.value)}
            />
          </label>

          {error && <p className="rounded-md bg-danger-50 px-4 py-3 text-sm text-danger-600">{error}</p>}
        </div>

        <div className="flex justify-end gap-3 border-t border-gray-100 px-5 py-4">
          <button type="button" className={buttonNeutral} onClick={onClose} disabled={submitting}>
            {t['management.table.cancel']}
          </button>
          <button type="button" className={buttonPrimary} onClick={handleSubmit} disabled={submitting || !decision}>
            {submitting ? t['management.teacherLeaveApplications.submitting'] : t['management.teacherLeaveApplications.submit']}
          </button>
        </div>
      </div>
    </div>
  )
}

// school_admin reviews their own school's teacher leave requests here
// (TenantScope already narrows the list; national_admin could technically
// call the same backend endpoints but gets no review UI — see the
// national_admin-only read-only "Teachers on Leave" page instead).
export default function TeacherLeaveApplicationsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<TeacherLeaveApplication[]>([])
  const [statusFilter, setStatusFilter] = useState<TeacherLeaveApplicationStatus | ''>('')
  const [reviewing, setReviewing] = useState<TeacherLeaveApplication | null>(null)

  function load() {
    if (!token) return

    setLoading(true)

    listTeacherLeaveApplications({ status: statusFilter || undefined }, token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    load()
  }, [token, statusFilter])

  function handleReviewed(updated: TeacherLeaveApplication) {
    setApplications((previous) => previous.map((application) => (application.id === updated.id ? updated : application)))
    setReviewing(null)
    showToast({ type: 'success', message: t['management.table.updatedSuccess'] })
  }

  return (
    <section className="space-y-5">
      <h2 className="text-lg font-semibold text-gray-800">{t['management.teacherLeaveApplications.title']}</h2>

      <div className="flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        <select
          className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={statusFilter}
          onChange={(event) => setStatusFilter(event.target.value as TeacherLeaveApplicationStatus | '')}
        >
          <option value="">{t['management.teacherLeaveApplications.filterAllStatus']}</option>
          <option value="pending">{t['management.teacherLeaveApplications.statusPending']}</option>
          <option value="approved">{t['management.teacherLeaveApplications.statusApproved']}</option>
          <option value="rejected">{t['management.teacherLeaveApplications.statusRejected']}</option>
        </select>
      </div>

      {loading && <p className="text-sm text-gray-500">{t['management.teacherLeaveApplications.loading']}</p>}

      {!loading && applications.length === 0 && (
        <p className="text-sm text-gray-500">{t['management.teacherLeaveApplications.empty']}</p>
      )}

      {!loading && applications.length > 0 && (
        <div className="overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[64rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTeacher']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colFrom']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTo']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colReason']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colSubstitute']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colStatus']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.actions']}</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((application, index) => (
                <tr key={application.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{index + 1}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{application.teacher?.name}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.from_date}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.to_date}</td>
                  <td className="max-w-[16rem] px-5 py-3.5 text-gray-700">{application.reason}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.delegate?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <span className={`rounded-sm px-2 py-0.5 text-xs font-medium ${STATUS_BADGE[application.status]}`}>
                      {STATUS_LABEL[application.status]}
                    </span>
                  </td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    {application.can_review && (
                      <button
                        type="button"
                        className="cursor-pointer leading-none text-gray-500 transition-colors duration-150 hover:text-primary-500"
                        onClick={() => setReviewing(application)}
                        aria-label={t['management.teacherLeaveApplications.reviewTitle']}
                      >
                        <span className="material-symbols-outlined !text-md">rate_review</span>
                      </button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {reviewing && (
        <ReviewModal application={reviewing} onClose={() => setReviewing(null)} onReviewed={handleReviewed} />
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
```

- [ ] **Step 4: Add a `RequireSchoolAdmin` guard**

In `frontend/apps/management/src/App.tsx`, find:

```tsx
function RequireSchoolManager() {
  const { role } = useAuth()

  return role === 'national_admin' || role === 'school_admin' ? (
    <Outlet />
  ) : (
    <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">
      {t['management.table.accessDenied']}
    </p>
  )
}
```

Add immediately after it:

```tsx
// Narrower than RequireSchoolManager: national_admin technically holds
// teacher_leave_applications.update too (its all-permissions wildcard —
// see TeacherLeaveApplicationPolicy::review()'s own comment), but this
// review page is deliberately school_admin-only per the product decision
// that national_admin gets a read-only view instead (TeachersOnLeavePage).
function RequireSchoolAdmin() {
  const { role } = useAuth()

  return role === 'school_admin' ? (
    <Outlet />
  ) : (
    <p className="max-w-lg rounded-md bg-danger-50 px-5 py-4 text-danger-600">
      {t['management.table.accessDenied']}
    </p>
  )
}
```

- [ ] **Step 5: Add the import, nav item, and route**

This file's page imports are case-insensitive alphabetical. Find:

```tsx
import TeacherDetailPage from './pages/TeacherDetailPage'
import TeacherRoutinePage from './pages/TeacherRoutinePage'
```

Insert the new import alphabetically between them:

```tsx
import TeacherDetailPage from './pages/TeacherDetailPage'
import TeacherLeaveApplicationsPage from './pages/TeacherLeaveApplicationsPage'
import TeacherRoutinePage from './pages/TeacherRoutinePage'
```

Add the nav item definition, right after `usersRolesNavItem`'s definition:

```tsx
// school_admin only — reviews their own school's teacher leave requests
// (TenantScope-filtered). national_admin gets a separate, read-only
// "Teachers on Leave" item instead (teachersOnLeaveNavItem, below) — see
// RequireSchoolAdmin's own comment for why this isn't shared.
const teacherLeaveApplicationsNavItem: SidebarItem = {
  to: '/dashboard/teacher-leave-applications',
  label: t['management.nav.teacherLeaveApplications'],
  icon: 'work_off',
}
```

In the `navItems` array inside `DashboardLayout()`, find:

```tsx
    ...(role === 'national_admin' || role === 'school_admin'
      ? [attendanceNavItem, publishResultsNavItem, promoteStudentsNavItem, routineNavItem]
      : []),
```

Add immediately after it:

```tsx
    ...(role === 'school_admin' ? [teacherLeaveApplicationsNavItem] : []),
```

In the `<Route element={<RequireSchoolManager />}>` block (inside `<Route path="/dashboard" ...>`), find:

```tsx
                <Route path="routine/teacher" element={<TeacherRoutinePage />} />
                <Route path="routine/class" element={<ManagementClassRoutinePage />} />
```

This block is shared by both roles — do NOT add the new route here (it must be school_admin-only). Instead, add a new sibling route block right after the `</Route>` that closes the `RequireSchoolManager` block (i.e. after the `users-roles/permissions` route and its closing tag, at the same nesting level as the `holidays`/`schools`/`class-materials` route blocks):

```tsx
              <Route path="teacher-leave-applications" element={<RequireSchoolAdmin />}>
                <Route index element={<TeacherLeaveApplicationsPage />} />
              </Route>
```

- [ ] **Step 6: Type-check the management app**

Run: `cd frontend/apps/management && npx tsc --noEmit -p .`
Expected: no output (clean)

- [ ] **Step 7: Run the frontend test suite**

Run: `cd frontend && npx vitest run`
Expected: PASS, same test count as before

- [ ] **Step 8: Commit**

```bash
git add frontend/apps/management/src/pages/TeacherLeaveApplicationsPage.tsx \
  frontend/apps/management/src/App.tsx \
  frontend/packages/i18n/src/common/en.json \
  frontend/packages/i18n/src/common/bn.json
git commit -m "Add management portal Teacher Leave Applications review page (school_admin only)"
```

---

### Task 6: Frontend — management portal, national_admin read-only "Teachers on Leave" page

**Files:**
- Create: `frontend/apps/management/src/pages/TeachersOnLeavePage.tsx`
- Modify: `frontend/apps/management/src/App.tsx` (route, nav item)

**Interfaces:**
- Consumes: `listTeacherLeaveApplications`, `TeacherLeaveApplication` from `@ttla/api-client` (Task 3). i18n keys already added in Task 5 (`management.teachersOnLeave.*`, `management.nav.teachersOnLeave`) — no further i18n changes needed here.

- [ ] **Step 1: Write `TeachersOnLeavePage.tsx`**

```tsx
import { useEffect, useState } from 'react'
import { listTeacherLeaveApplications, type TeacherLeaveApplication } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

// Read-only, national_admin only — every teacher currently on approved
// leave (today within from_date/to_date), across every school. No
// approve/reject action: that stays exclusively a school_admin
// capability (see TeacherLeaveApplicationsPage).
export default function TeachersOnLeavePage() {
  const { token } = useAuth()
  const [loading, setLoading] = useState(true)
  const [applications, setApplications] = useState<TeacherLeaveApplication[]>([])

  useEffect(() => {
    if (!token) return

    setLoading(true)

    listTeacherLeaveApplications({ current: true }, token)
      .then((result) => setApplications(result.data))
      .catch(() => setApplications([]))
      .finally(() => setLoading(false))
  }, [token])

  return (
    <section className="space-y-5">
      <h2 className="text-lg font-semibold text-gray-800">{t['management.teachersOnLeave.title']}</h2>

      {loading && <p className="text-sm text-gray-500">{t['management.teachersOnLeave.loading']}</p>}

      {!loading && applications.length === 0 && (
        <p className="text-sm text-gray-500">{t['management.teachersOnLeave.empty']}</p>
      )}

      {!loading && applications.length > 0 && (
        <div className="overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.table.serial']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTeacher']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teachersOnLeave.colSchool']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colFrom']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colTo']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.teacherLeaveApplications.colSubstitute']}</th>
              </tr>
            </thead>
            <tbody>
              {applications.map((application, index) => (
                <tr key={application.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{index + 1}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{application.teacher?.name}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.teacher?.school_name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.from_date}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.to_date}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{application.delegate?.name ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
```

- [ ] **Step 2: Add the import, nav item, and route**

In `frontend/apps/management/src/App.tsx`, this file's page imports are case-insensitive alphabetical. After Task 5, find:

```tsx
import TeacherRoutinePage from './pages/TeacherRoutinePage'
import TeachersPage from './pages/TeachersPage'
```

Insert the new import alphabetically between them:

```tsx
import TeacherRoutinePage from './pages/TeacherRoutinePage'
import TeachersOnLeavePage from './pages/TeachersOnLeavePage'
import TeachersPage from './pages/TeachersPage'
```

Add the nav item definition, right after `teacherLeaveApplicationsNavItem`'s definition:

```tsx
// national_admin only — read-only. Approving/rejecting stays exclusively
// a school_admin action (see teacherLeaveApplicationsNavItem above);
// national_admin can technically call the same review endpoint (its
// wildcard permission set is never excluded from an ability by role-name
// check in this app) but this portal deliberately builds no UI for it.
const teachersOnLeaveNavItem: SidebarItem = {
  to: '/dashboard/teachers-on-leave',
  label: t['management.nav.teachersOnLeave'],
  icon: 'person_off',
}
```

In the `navItems` array inside `DashboardLayout()`, find the line added in Task 5:

```tsx
    ...(role === 'school_admin' ? [teacherLeaveApplicationsNavItem] : []),
```

Add immediately after it:

```tsx
    ...(role === 'national_admin' ? [teachersOnLeaveNavItem] : []),
```

Add the route as a sibling of the `teacher-leave-applications` route added in Task 5 (same nesting level, reusing the existing `RequireNationalAdmin` guard):

```tsx
              <Route path="teachers-on-leave" element={<RequireNationalAdmin />}>
                <Route index element={<TeachersOnLeavePage />} />
              </Route>
```

- [ ] **Step 3: Type-check the management app**

Run: `cd frontend/apps/management && npx tsc --noEmit -p .`
Expected: no output (clean)

- [ ] **Step 4: Run the frontend test suite**

Run: `cd frontend && npx vitest run`
Expected: PASS, same test count as before

- [ ] **Step 5: Manual browser verification (both new management pages + the teacher portal page + the delegation end-to-end)**

Using the `run` skill or a manually started `php artisan serve` + `npm run dev:teacher` + `npm run dev:management`, log in as each seeded account (from `database/seeders/UserSeeder.php`: `school.admin@tlap.test`, `national.admin@tlap.test`, password `password` for both; a teacher account must be created/seeded for this check since `UserSeeder` doesn't seed one directly — use any existing `TeacherUser` row, or create one via the management portal's Users/Teachers page) and confirm:
- As a teacher: "Teacher Leave" appears in the sidebar; submitting a request with a named substitute succeeds and appears in "My leave requests" as Pending.
- As school_admin: "Teacher Leave" appears in the sidebar (review page); the submitted request appears with Approve/Reject available; approving it succeeds and the row updates to Approved.
- As the named substitute teacher: after approval, their Attendance "my-sections" picker includes the applicant's section, and recording attendance for it succeeds.
- As national_admin: "Teachers on Leave" appears in the sidebar (read-only); the now-approved, in-range application appears with teacher/school/dates/substitute, with no action buttons; the school_admin-only review page is NOT in national_admin's sidebar and returns the access-denied message if navigated to directly.

- [ ] **Step 6: Commit**

```bash
git add frontend/apps/management/src/pages/TeachersOnLeavePage.tsx \
  frontend/apps/management/src/App.tsx
git commit -m "Add management portal read-only Teachers on Leave page (national_admin only)"
```

## Self-Review Notes

- **Spec coverage:** every Goal in the spec maps to a task — submission+delegate naming (Task 1 backend, Task 4 frontend), school_admin review (Task 1 backend, Task 5 frontend), substitute attendance rights (Task 2), national_admin read-only view (Task 1's `current` filter, Task 6 frontend). Every item in the spec's Non-Goals list is explicitly called out as NOT built, in the Global Constraints and in individual task comments, so an implementer doesn't accidentally add it back.
- **Placeholder scan:** no TBD/TODO; every step has complete, copy-pasteable code; no step says "similar to Task N" without the actual code.
- **Type consistency:** `TeacherLeaveApplication`/`TeacherColleague` (Task 3) are consumed with identical field names in Tasks 4/5/6; `delegate_teacher_id`/`teacher_id`/`school_id` names match end-to-end from the migration (Task 1) through the Policy (Task 2) to the frontend types (Task 3). `can_review` (not `can_review_teacher_leave` or similar) is used consistently.
