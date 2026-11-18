# De-scope National Content Tables Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Drop the `school_id`/`district_id` tenant columns from `academic_years`, `books`, `chapters`, `chapter_materials`, `chapter_videos`, and `questions`; delete the `chapter_video_progress` feature entirely; restrict create/update/delete on these six resources to `national_admin`.

**Architecture:** Each of the six tables currently uses the `TenantScoped` model trait (a global Eloquent scope that auto-filters queries by the authenticated actor's `school_id`/`district_id`). This plan removes the trait and the two columns from each model, strips the matching denormalization-from-parent code in each controller, and tightens `RolePermissionSeeder` so only `national_admin` keeps the write verbs for these resources. The video-progress feature (`chapter_video_progress` table + `ChapterVideoProgress` model + its controller/request/resource/routes) is deleted outright, including the dashboard stat it fed.

**Tech Stack:** Laravel 13 (PHP 8.5), PHPUnit, MySQL (production parity — this repo has a documented history of SQLite masking MySQL-only migration bugs, so Task 10 runs against MySQL).

**Spec:** `docs/superpowers/specs/2026-11-18-national-content-descoping-design.md`

## Global Constraints

- No production data exists in any of the six tables or in `chapter_video_progress` — confirmed during brainstorming. Migrations are simple column/table drops; no data-preserving dedup logic.
- `books.class_version_id` keeps pointing at a school-owned `class_versions` row — do not re-parent `Book` to a global class. This was explicitly rejected during brainstorming.
- Do not touch `exams`, `exam_attempts`, `exam_questions`, `exam_attempt_questions`, `exam_attempt_answers`, `exam_random_chapters`, `question_options`, `content_access_logs`, or `school_classes` — out of scope for this sub-project.
- `*.view` permissions for all six resources are unchanged on every role that currently holds them — only the create/update/delete verbs move to `national_admin`-only.
- Follow this repo's migration convention: explicit `dropForeign(['col'])` + `dropColumn('col')` (see `database/migrations/2026_11_01_070100_add_book_id_to_chapters_table.php`), not the `dropConstrainedForeignId()` shorthand.
- Run `vendor/bin/pint --dirty --format agent` after modifying any PHP file in each task, before committing.

---

## Task 1: De-scope `academic_years`

**Files:**
- Create: `backend/database/migrations/2026_11_18_100000_drop_tenant_columns_from_academic_years_table.php`
- Modify: `backend/app/Models/AcademicYear.php`
- Modify: `backend/app/Http/Controllers/Api/V1/AcademicYearController.php`
- Modify: `backend/app/Http/Requests/Api/V1/StoreAcademicYearRequest.php`
- Modify: `backend/app/Http/Requests/Api/V1/UpdateAcademicYearRequest.php`
- Modify: `backend/app/Http/Resources/Api/V1/AcademicYearResource.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/AcademicYearListPaginationTest.php`
- Create: `backend/tests/Feature/AcademicYearAuthoringPermissionMappingTest.php`
- Test: `backend/tests/Feature/AcademicYearListPaginationTest.php`, `backend/tests/Feature/AcademicYearAuthoringPermissionMappingTest.php`

**Interfaces:**
- Produces: `AcademicYear` with no `school_id`/`district_id`, unique on `name` alone, one `is_current` row nationally. Later tasks (2–7) follow this exact pattern for their own table.

- [ ] **Step 1: Write the failing tests**

Replace the full contents of `backend/tests/Feature/AcademicYearListPaginationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearListPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_year_index_is_paginated(): void
    {
        for ($i = 1; $i <= 18; $i++) {
            AcademicYear::create([
                'name' => "Year {$i}",
                'start_date' => "202{$i}-01-01",
                'end_date' => "202{$i}-12-31",
            ]);
        }

        $response = $this->getJson('/api/v1/academic-years');

        $response->assertOk();
        $response->assertJsonCount(15, 'data');
        $response->assertJsonPath('meta.total', 18);
    }

    public function test_academic_year_index_filters_by_search(): void
    {
        AcademicYear::create(['name' => 'Session 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        AcademicYear::create(['name' => 'Session 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->getJson('/api/v1/academic-years?search=2026')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Session 2026');
    }

    public function test_set_current_endpoint_unsets_any_other_year_nationally(): void
    {
        $yearA = AcademicYear::create(['name' => 'Year A', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
        $yearB = AcademicYear::create(['name' => 'Year B', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $role = Role::create(['name' => 'role_academic_year_test', 'label' => 'Test Role']);
        $permission = Permission::firstOrCreate(['name' => 'academic_years.update'], ['label' => 'academic_years.update']);
        $role->permissions()->attach($permission);
        $actor = User::factory()->create(['role_id' => $role->id]);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/academic-years/{$yearB->id}/set-current")
            ->assertOk();

        $this->assertFalse($yearA->refresh()->is_current);
        $this->assertTrue($yearB->refresh()->is_current);
    }
}
```

(Add `use App\Models\Permission;`, `use App\Models\Role;`, and `use App\Models\User;` to this file's import list alongside the existing `use App\Models\AcademicYear;`.)

Create `backend/tests/Feature/AcademicYearAuthoringPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * academic_years is national calendar data now — only national_admin
     * may author it. school_admin previously held create/update/delete;
     * granting it to any other role here would be a real authorization
     * regression, not just redundancy.
     */
    public function test_academic_years_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'academic_years.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=AcademicYearListPaginationTest --compact` and `php artisan test --filter=AcademicYearAuthoringPermissionMappingTest --compact`
Expected: FAIL — `school_id`/`district_id` columns still exist and are still required in `StoreAcademicYearRequest`, and `academic_years.create` is still mapped to `school_admin` too.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * academic_years becomes national calendar data — the same academic
     * year applies to every school in Bangladesh — so the per-school
     * tenant columns and the (school_id, name) uniqueness are no longer
     * meaningful. No production data exists yet (dev/test only), so this
     * is a clean column drop, not a data migration.
     */
    public function up(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropUnique(['school_id', 'name']);
            $table->dropColumn(['school_id', 'district_id']);

            $table->unique('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique(['name']);

            $table->foreignId('school_id')->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();

            $table->unique(['school_id', 'name']);
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Update the model**

Replace `backend/app/Models/AcademicYear.php` in full:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicYear extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'is_current',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }
}
```

(The `school()`/`district()` `BelongsTo` relations and their imports are removed — there is no `school_id`/`district_id` to resolve them from.)

- [ ] **Step 5: Update the controller**

Replace `backend/app/Http/Controllers/Api/V1/AcademicYearController.php` in full:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicYearRequest;
use App\Http\Requests\Api\V1\UpdateAcademicYearRequest;
use App\Http\Resources\Api\V1\AcademicYearResource;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AcademicYearController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $academicYears = AcademicYear::query()
            ->when($request->boolean('current_only'), fn ($query) => $query->where('is_current', true))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return AcademicYearResource::collection($academicYears);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicYear $academic_year): AcademicYearResource
    {
        return new AcademicYearResource($academic_year);
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted year with the same name is restored and updated in
     * place, rather than attempting a second insert that would collide
     * with the still-present unique index on a trashed row.
     */
    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $this->authorize('create', AcademicYear::class);

        $attributes = [
            ...$request->safe()->only(['name', 'start_date', 'end_date', 'description']),
            // Explicit rather than relying on the DB column default, so the
            // model attribute returned in the response matches what's
            // actually stored instead of being left unset in memory.
            'is_current' => $request->boolean('is_current'),
        ];

        $academicYear = DB::transaction(function () use ($attributes) {
            if ($attributes['is_current'] ?? false) {
                $this->unsetOtherCurrentYears();
            }

            $academicYear = AcademicYear::onlyTrashed()
                ->where('name', $attributes['name'])
                ->first();

            if ($academicYear) {
                $academicYear->restore();
                $academicYear->update($attributes);
            } else {
                $academicYear = AcademicYear::create($attributes);
            }

            return $academicYear;
        });

        return (new AcademicYearResource($academicYear))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicYearRequest $request, AcademicYear $academic_year): AcademicYearResource
    {
        $this->authorize('update', $academic_year);

        $data = $request->validated();

        DB::transaction(function () use ($academic_year, $data) {
            if ($data['is_current'] ?? false) {
                $this->unsetOtherCurrentYears($academic_year->id);
            }

            $academic_year->update($data);
        });

        return new AcademicYearResource($academic_year);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicYear $academic_year): Response
    {
        $this->authorize('delete', $academic_year);

        $academic_year->delete();

        return response()->noContent();
    }

    /**
     * Mark this academic year as the current one nationally, unsetting
     * any other year previously marked current.
     */
    public function setCurrent(AcademicYear $academic_year): AcademicYearResource
    {
        $this->authorize('setCurrent', $academic_year);

        DB::transaction(function () use ($academic_year) {
            $this->unsetOtherCurrentYears($academic_year->id);
            $academic_year->update(['is_current' => true]);
        });

        return new AcademicYearResource($academic_year);
    }

    /**
     * Only one academic year nationally may be marked current at a time.
     */
    private function unsetOtherCurrentYears(?int $exceptId = null): void
    {
        AcademicYear::where('is_current', true)
            ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
            ->update(['is_current' => false]);
    }
}
```

- [ ] **Step 6: Update the Store/Update requests**

Replace `backend/app/Http/Requests/Api/V1/StoreAcademicYearRequest.php` in full:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicYear;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicYear::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('academic_years', 'name')->withoutTrashed()],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after:start_date'],
            'is_current' => ['boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
```

In `backend/app/Http/Requests/Api/V1/UpdateAcademicYearRequest.php`, change the `name` rule (the `authorize()` method already doesn't reference `school_id` and needs no change):

```php
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('academic_years', 'name')->ignore($academicYear),
            ],
```

(was `Rule::unique('academic_years', 'name')->ignore($academicYear)->where('school_id', $academicYear->school_id)`)

- [ ] **Step 7: Update the resource**

In `backend/app/Http/Resources/Api/V1/AcademicYearResource.php`, remove the `'school_id'`, `'district_id'`, `'school'`, and `'district'` keys from the returned array (and the now-unused `SchoolResource`/`DistrictResource` references):

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicYearResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
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
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_current' => $this->is_current,
            'description' => $this->description,
        ];
    }
}
```

- [ ] **Step 8: Restrict authoring to national_admin in RolePermissionSeeder**

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'school_admin'` array, change:

```php
                'academic_years.view', 'academic_years.create', 'academic_years.update', 'academic_years.delete',
```

to:

```php
                'academic_years.view',
```

- [ ] **Step 9: Run the tests to verify they pass**

Run: `php artisan test --filter=AcademicYearListPaginationTest --compact`, `php artisan test --filter=AcademicYearAuthoringPermissionMappingTest --compact`, `php artisan test --filter=AcademicYearController --compact` (covers any other existing test file touching this controller)
Expected: PASS

- [ ] **Step 10: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_18_100000_drop_tenant_columns_from_academic_years_table.php app/Models/AcademicYear.php app/Http/Controllers/Api/V1/AcademicYearController.php app/Http/Requests/Api/V1/StoreAcademicYearRequest.php app/Http/Requests/Api/V1/UpdateAcademicYearRequest.php app/Http/Resources/Api/V1/AcademicYearResource.php database/seeders/RolePermissionSeeder.php tests/Feature/AcademicYearListPaginationTest.php tests/Feature/AcademicYearAuthoringPermissionMappingTest.php
git commit -m "Make academic_years national calendar data, authored by national_admin only"
```

---

## Task 2: De-scope `books`

**Files:**
- Create: `backend/database/migrations/2026_11_18_100100_drop_tenant_columns_from_books_table.php`
- Modify: `backend/app/Models/Book.php`
- Modify: `backend/app/Http/Controllers/Api/V1/BookController.php`
- Modify: `backend/app/Http/Resources/Api/V1/BookResource.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/BookControllerTest.php`
- Create: `backend/tests/Feature/BookAuthoringPermissionMappingTest.php`
- Test: `backend/tests/Feature/BookControllerTest.php`, `backend/tests/Feature/BookAuthoringPermissionMappingTest.php`

**Interfaces:**
- Consumes: migration/model/controller/seeder pattern established in Task 1.
- Produces: `Book` with no `school_id`/`district_id`; `books.create`/`update`/`delete` mapped to `national_admin` only. Task 3 (`Chapter`) and the `LmsContentDemoSeeder` fix in Task 8 depend on `Book` no longer exposing `school_id`/`district_id`.

- [ ] **Step 1: Write the failing tests**

Replace the full contents of `backend/tests/Feature/BookControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class BookControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private ClassVersion $versionA;

    private ClassVersion $versionB;

    private Subject $subject;

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

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Class 6']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $this->versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->schoolB->id, 'district_id' => $this->schoolB->district_id, 'name' => 'Bangla Version']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'name' => 'Existing Book',
        ]);
        $payload = ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'New Book'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/books', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['books.create', 'books.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/books', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/books/{$book->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_multiple_books_allowed_per_class_version_and_subject(): void
    {
        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics'])
            ->assertCreated();

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics Practice Book'])
            ->assertCreated();

        $this->assertSame(2, Book::where('class_version_id', $this->versionA->id)->where('subject_id', $this->subject->id)->count());
    }

    public function test_actor_with_permission_can_update_a_book_belonging_to_a_different_schools_version(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionB->id,
            'subject_id' => $this->subject->id,
            'name' => 'School B Book',
        ]);

        $actor = $this->actorWithPermissions(['books.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/books/{$book->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_store_restores_a_soft_deleted_book_with_the_same_identity(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'name' => 'Mathematics',
        ]);
        $book->delete();

        $allowed = $this->actorWithPermissions(['books.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/books', ['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'name' => 'Mathematics'])
            ->assertCreated()
            ->assertJsonPath('data.id', $book->id);
    }

    public function test_destroy_is_blocked_while_the_book_still_has_chapters(): void
    {
        $book = Book::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'name' => 'Mathematics',
        ]);
        $book->chapters()->create(['name' => 'Chapter 1']);

        $actor = $this->actorWithPermissions(['books.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->deleteJson("/api/v1/books/{$book->id}")
            ->assertUnprocessable();
    }
}
```

Create `backend/tests/Feature/BookAuthoringPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * books is national content now — only national_admin may author it.
     * trainer previously held books.create/update; granting it to any
     * other role here would be a real authorization regression.
     */
    public function test_books_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'books.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=BookControllerTest --compact`, `php artisan test --filter=BookAuthoringPermissionMappingTest --compact`
Expected: FAIL — `school_id` column is still `NOT NULL` with no value supplied, and `books.create` is still mapped to `trainer` too.

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * books.school_id/district_id were denormalized from class_version_id
     * purely for tenant-scope filtering. That filtering is removed — any
     * authenticated actor with books.view can see any book — so these
     * columns are dropped. books.class_version_id is unchanged: a book is
     * still reached through one school's own class_versions row (see the
     * spec's "Rejected alternative" for why re-parenting to a global
     * class is explicitly out of scope).
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['school_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('school_id')->after('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Update the model**

In `backend/app/Models/Book.php`, remove `TenantScoped` from the `use` statement and remove `'school_id'`/`'district_id'` from `$fillable`:

```php
class Book extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'class_version_id',
        'subject_id',
        'name',
        'bn_name',
        'order_index',
        'description',
        'is_active',
        'pdf_original_filename',
        'pdf_stored_path',
        'pdf_disk',
        'pdf_mime_type',
        'pdf_size_bytes',
        'pdf_uploaded_by_id',
        'pdf_uploaded_at',
        'pdf_status',
    ];
```

Remove the now-unused `use App\Models\Concerns\TenantScoped;` import.

- [ ] **Step 5: Update the controller**

In `backend/app/Http/Controllers/Api/V1/BookController.php`, `store()`, remove the two denormalization lines:

```php
        $data = $request->validated();

        $book = Book::onlyTrashed()
```

(was: `$data = $request->validated(); $data['school_id'] = $classVersion->school_id; $data['district_id'] = $classVersion->district_id; $book = Book::onlyTrashed()`)

- [ ] **Step 6: Update the resource**

In `backend/app/Http/Resources/Api/V1/BookResource.php`, remove the `'school_id'` and `'district_id'` keys from the returned array.

- [ ] **Step 7: Restrict authoring to national_admin in RolePermissionSeeder**

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'trainer'` array, change:

```php
                'books.view', 'books.create', 'books.update',
```

to:

```php
                'books.view',
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --filter=BookControllerTest --compact`, `php artisan test --filter=BookAuthoringPermissionMappingTest --compact`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_18_100100_drop_tenant_columns_from_books_table.php app/Models/Book.php app/Http/Controllers/Api/V1/BookController.php app/Http/Resources/Api/V1/BookResource.php database/seeders/RolePermissionSeeder.php tests/Feature/BookControllerTest.php tests/Feature/BookAuthoringPermissionMappingTest.php
git commit -m "Make books national content, authored by national_admin only"
```

---

## Task 3: De-scope `chapters`

**Files:**
- Create: `backend/database/migrations/2026_11_18_100200_drop_tenant_columns_from_chapters_table.php`
- Modify: `backend/app/Models/Chapter.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterController.php`
- Modify: `backend/app/Http/Resources/Api/V1/ChapterResource.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/ChapterControllerTest.php`
- Create: `backend/tests/Feature/ChapterAuthoringPermissionMappingTest.php`
- Test: `backend/tests/Feature/ChapterControllerTest.php`, `backend/tests/Feature/ChapterAuthoringPermissionMappingTest.php`

**Interfaces:**
- Consumes: `Book` with no `school_id`/`district_id` (Task 2).
- Produces: `Chapter` with no `school_id`/`district_id`; `chapters.create`/`update`/`delete` mapped to `national_admin` only. Tasks 4 and 7 (`ChapterMaterial`, `Question`) depend on `Chapter` no longer exposing `school_id`/`district_id`.

- [ ] **Step 1: Write the failing tests**

Replace the full contents of `backend/tests/Feature/ChapterControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ChapterControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private Book $bookA;

    private Book $bookB;

    private School $schoolA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Class 6']);

        $versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $this->bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $this->bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
    }

    public function test_store_and_update_are_authorized(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Existing Chapter']);
        $payload = ['book_id' => $this->bookA->id, 'name' => 'New Chapter'];

        $denied = $this->actorWithNoPermissions($this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/chapters', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['chapters.create', 'chapters.update'], $this->schoolA->id, $this->schoolA->district_id);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/chapters', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/chapters/{$chapter->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_index_filters_by_book_id(): void
    {
        Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter 1']);
        Chapter::create(['book_id' => $this->bookB->id, 'name' => 'Chapter 1']);

        $actor = $this->actorWithPermissions(['chapters.view'], null, null);

        $response = $this->actingAs($actor, 'sanctum')->getJson("/api/v1/chapters?book_id={$this->bookA->id}");

        $response->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_actor_with_permission_can_update_a_chapter_belonging_to_a_different_schools_book(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookB->id, 'name' => 'School B Chapter']);

        $actor = $this->actorWithPermissions(['chapters.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapters/{$chapter->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_update_silently_ignores_an_attempt_to_change_book_id(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter 1']);

        $allowed = $this->actorWithPermissions(['chapters.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->patchJson("/api/v1/chapters/{$chapter->id}", ['book_id' => $this->bookB->id, 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.book_id', $this->bookA->id);

        $this->assertSame($this->bookA->id, $chapter->refresh()->book_id);
    }

    public function test_store_restores_a_soft_deleted_chapter_with_the_same_identity(): void
    {
        $chapter = Chapter::create(['book_id' => $this->bookA->id, 'name' => 'Chapter 1']);
        $chapter->delete();

        $allowed = $this->actorWithPermissions(['chapters.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($allowed, 'sanctum')
            ->postJson('/api/v1/chapters', ['book_id' => $this->bookA->id, 'name' => 'Chapter 1'])
            ->assertCreated()
            ->assertJsonPath('data.id', $chapter->id);
    }
}
```

Create `backend/tests/Feature/ChapterAuthoringPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapters_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'chapters.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ChapterControllerTest --compact`, `php artisan test --filter=ChapterAuthoringPermissionMappingTest --compact`
Expected: FAIL

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same reasoning as books (2026_11_18_100100): chapters.school_id/
     * district_id were denormalized purely for tenant-scope filtering,
     * which is removed.
     */
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['school_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table) {
            $table->foreignId('school_id')->after('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Update the model**

In `backend/app/Models/Chapter.php`, remove `TenantScoped` from the `use` statement, its import, and remove `'school_id'`/`'district_id'` from `$fillable`:

```php
class Chapter extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'book_id',
        'name',
        'bn_name',
        'order_index',
        'chapter_number',
        'bn_chapter_number',
        'description',
    ];
```

- [ ] **Step 5: Update the controller**

In `backend/app/Http/Controllers/Api/V1/ChapterController.php`, `store()`, remove the two denormalization lines:

```php
        $book = Book::findOrFail($request->integer('book_id'));
        $data = $this->applyAutoNumbering($request->validated());

        $chapter = Chapter::onlyTrashed()
```

(was: `... $data = $this->applyAutoNumbering($request->validated()); $data['school_id'] = $book->school_id; $data['district_id'] = $book->district_id; $chapter = Chapter::onlyTrashed() ...`)

- [ ] **Step 6: Update the resource**

In `backend/app/Http/Resources/Api/V1/ChapterResource.php`, remove the `'school_id'` and `'district_id'` keys from the returned array.

- [ ] **Step 7: Restrict authoring to national_admin in RolePermissionSeeder**

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'trainer'` array, change:

```php
                'chapters.view', 'chapters.create', 'chapters.update',
```

to:

```php
                'chapters.view',
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --filter=ChapterControllerTest --compact`, `php artisan test --filter=ChapterAuthoringPermissionMappingTest --compact`
Expected: PASS

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_18_100200_drop_tenant_columns_from_chapters_table.php app/Models/Chapter.php app/Http/Controllers/Api/V1/ChapterController.php app/Http/Resources/Api/V1/ChapterResource.php database/seeders/RolePermissionSeeder.php tests/Feature/ChapterControllerTest.php tests/Feature/ChapterAuthoringPermissionMappingTest.php
git commit -m "Make chapters national content, authored by national_admin only"
```

---

## Task 4: De-scope `chapter_materials`

**Files:**
- Create: `backend/database/migrations/2026_11_18_100300_drop_tenant_columns_from_chapter_materials_table.php`
- Modify: `backend/app/Models/ChapterMaterial.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/ChapterMaterialControllerTest.php`
- Create: `backend/tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php`
- Test: `backend/tests/Feature/ChapterMaterialControllerTest.php`, `backend/tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php`

**Interfaces:**
- Consumes: `Chapter` with no `school_id`/`district_id` (Task 3).
- Produces: `ChapterMaterial` with no `school_id`/`district_id`; `chapter_materials.create`/`update`/`delete` mapped to `national_admin` only.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Feature/ChapterMaterialControllerTest.php`, the `setUp()` method builds `$this->chapterA`/`$this->chapterB` via `Book::create([... 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id ...])` and `Chapter::create([... 'school_id' => $bookA->school_id, 'district_id' => $bookA->district_id ...])`. Update `setUp()`:

```php
        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'name' => 'Chapter 1']);
```

Find `test_actor_with_permission_gets_404_updating_another_schools_material` (it builds a `ChapterMaterial` directly with `'school_id' => $this->chapterB->school_id, 'district_id' => $this->chapterB->district_id` and asserts `->assertNotFound()`). Replace the whole method:

```php
    public function test_actor_with_permission_can_update_a_material_belonging_to_a_different_schools_chapter(): void
    {
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterB->id,
            'type' => 'primary',
            'title' => 'School B Material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
        ]);

        $actor = $this->actorWithPermissions(['chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$material->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }
```

Create `backend/tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterMaterialAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapter_materials_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'chapter_materials.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ChapterMaterialControllerTest --compact`, `php artisan test --filter=ChapterMaterialAuthoringPermissionMappingTest --compact`
Expected: FAIL

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same reasoning as books/chapters: chapter_materials.school_id/
     * district_id were denormalized purely for tenant-scope filtering,
     * which is removed.
     */
    public function up(): void
    {
        Schema::table('chapter_materials', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['school_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapter_materials', function (Blueprint $table) {
            $table->foreignId('school_id')->after('chapter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Update the model**

In `backend/app/Models/ChapterMaterial.php`, remove `TenantScoped` from the `use` statement, its import, and remove `'school_id'`/`'district_id'` from `$fillable`.

- [ ] **Step 5: Update the controller**

In `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`, `store()`, remove the two denormalization lines from the `ChapterMaterial::create([...])` array:

```php
        $material = ChapterMaterial::create([
            'chapter_id' => $chapter->id,
            'type' => $request->input('type'),
```

(was: `... 'chapter_id' => $chapter->id, 'school_id' => $chapter->school_id, 'district_id' => $chapter->district_id, 'type' => $request->input('type'), ...`)

`ChapterMaterialResource` (checked during planning) does not expose `school_id`/`district_id` — no change needed there.

- [ ] **Step 6: Restrict authoring to national_admin in RolePermissionSeeder**

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'trainer'` array, change:

```php
                'chapter_materials.view', 'chapter_materials.create', 'chapter_materials.update',
```

to:

```php
                'chapter_materials.view',
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=ChapterMaterialControllerTest --compact`, `php artisan test --filter=ChapterMaterialAuthoringPermissionMappingTest --compact`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_18_100300_drop_tenant_columns_from_chapter_materials_table.php app/Models/ChapterMaterial.php app/Http/Controllers/Api/V1/ChapterMaterialController.php database/seeders/RolePermissionSeeder.php tests/Feature/ChapterMaterialControllerTest.php tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php
git commit -m "Make chapter_materials national content, authored by national_admin only"
```

---

## Task 5: De-scope `chapter_videos` (columns only — progress removal is Task 6)

**Files:**
- Create: `backend/database/migrations/2026_11_18_100400_drop_tenant_columns_from_chapter_videos_table.php`
- Modify: `backend/app/Models/ChapterVideo.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/ChapterVideoControllerTest.php`
- Create: `backend/tests/Feature/ChapterVideoAuthoringPermissionMappingTest.php`
- Test: `backend/tests/Feature/ChapterVideoControllerTest.php`, `backend/tests/Feature/ChapterVideoAuthoringPermissionMappingTest.php`

**Interfaces:**
- Consumes: `Chapter` with no `school_id`/`district_id` (Task 3).
- Produces: `ChapterVideo` with no `school_id`/`district_id`; `chapter_videos.create`/`update`/`delete` mapped to `national_admin` only. The `updateProgress`/`applyVideoProgress` methods and their imports in `ChapterVideoController` are untouched here — Task 6 removes them.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Feature/ChapterVideoControllerTest.php`, update `setUp()` the same way as Task 4 (Book/Chapter without `school_id`/`district_id`):

```php
        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'name' => 'Chapter 1']);
```

Remove `'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id,` (and the `chapterB` equivalent) from every `ChapterVideo::create([...])` call in this file (in `test_reorder_sets_order_index_from_the_given_sequence`, `test_reorder_rejects_an_id_from_a_different_chapter`, `test_index_excludes_disabled_videos_unless_the_caller_can_manage_them`, `test_show_404s_a_disabled_video_unless_the_caller_can_manage_it`).

Find `test_actor_with_permission_gets_404_updating_another_schools_video` (builds a `ChapterVideo` with `'school_id' => $this->chapterB->school_id, 'district_id' => $this->chapterB->district_id` and asserts `->assertNotFound()`). Replace the whole method:

```php
    public function test_actor_with_permission_can_update_a_video_belonging_to_a_different_schools_chapter(): void
    {
        $video = ChapterVideo::create([
            'chapter_id' => $this->chapterB->id,
            'youtube_video_id' => 'hhhhhhhhhhh',
            'title' => 'School B Video',
        ]);

        $actor = $this->actorWithPermissions(['chapter_videos.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/chapter-videos/{$video->id}", ['title' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Renamed');
    }
```

Create `backend/tests/Feature/ChapterVideoAuthoringPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChapterVideoAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    public function test_chapter_videos_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'chapter_videos.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ChapterVideoControllerTest --compact`, `php artisan test --filter=ChapterVideoAuthoringPermissionMappingTest --compact`
Expected: FAIL

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same reasoning as books/chapters/chapter_materials:
     * chapter_videos.school_id/district_id were denormalized purely for
     * tenant-scope filtering, which is removed.
     */
    public function up(): void
    {
        Schema::table('chapter_videos', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['school_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chapter_videos', function (Blueprint $table) {
            $table->foreignId('school_id')->after('chapter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Update the model**

In `backend/app/Models/ChapterVideo.php`, remove `TenantScoped` from the `use` statement, its import, and remove `'school_id'`/`'district_id'` from `$fillable`.

- [ ] **Step 5: Update the controller**

In `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`, `store()`, remove the two denormalization lines:

```php
        $data['youtube_video_id'] = YoutubeUrl::extractVideoId($data['youtube_url']);
        unset($data['youtube_url']);

        $video = ChapterVideo::create($data);
```

(was: `... unset($data['youtube_url']); $data['school_id'] = $chapter->school_id; $data['district_id'] = $chapter->district_id; $video = ChapterVideo::create($data);`)

`ChapterVideoResource` (checked during planning) does not expose `school_id`/`district_id` — no change needed there. The `updateProgress`/`applyVideoProgress` methods and `ChapterVideoProgress`/`UpdateChapterVideoProgressRequest`/`ChapterVideoProgressResource` imports stay untouched in this task — Task 6 removes them.

- [ ] **Step 6: Restrict authoring to national_admin in RolePermissionSeeder**

In `backend/database/seeders/RolePermissionSeeder.php`, in the `'trainer'` array, change:

```php
                'chapter_videos.view', 'chapter_videos.create', 'chapter_videos.update',
```

to:

```php
                'chapter_videos.view',
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=ChapterVideoControllerTest --compact`, `php artisan test --filter=ChapterVideoAuthoringPermissionMappingTest --compact`
Expected: PASS (`ChapterVideoProgressControllerTest` will still reference the now-columnless `ChapterVideo` fine, since it never set `school_id`/`district_id` on the video itself in a way this task touches — it still passes until Task 6 deletes it)

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_18_100400_drop_tenant_columns_from_chapter_videos_table.php app/Models/ChapterVideo.php app/Http/Controllers/Api/V1/ChapterVideoController.php database/seeders/RolePermissionSeeder.php tests/Feature/ChapterVideoControllerTest.php tests/Feature/ChapterVideoAuthoringPermissionMappingTest.php
git commit -m "Make chapter_videos national content, authored by national_admin only"
```

---

## Task 6: Remove the `chapter_video_progress` feature entirely

**Files:**
- Create: `backend/database/migrations/2026_11_18_100500_drop_chapter_video_progress_table.php`
- Delete: `backend/app/Models/ChapterVideoProgress.php`
- Delete: `backend/app/Http/Requests/Api/V1/UpdateChapterVideoProgressRequest.php`
- Delete: `backend/app/Http/Resources/Api/V1/ChapterVideoProgressResource.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Support/StudentDashboardBuilder.php`
- Delete: `backend/tests/Feature/ChapterVideoProgressControllerTest.php`
- Delete: `backend/tests/Unit/ChapterVideoProgressModelTest.php`
- Modify: `backend/tests/Unit/StudentDashboardBuilderTest.php`
- Test: `backend/tests/Unit/StudentDashboardBuilderTest.php`

**Interfaces:**
- Produces: no `chapter_video_progress` table, no video-progress API surface, no `video_completed` key in the student dashboard's per-chapter rollup (`video_total` stays). Task 8 (`LmsContentDemoSeeder` fix) depends on this being done first.

- [ ] **Step 1: Delete the obsolete tests and write the updated dashboard test**

Delete `backend/tests/Feature/ChapterVideoProgressControllerTest.php` and `backend/tests/Unit/ChapterVideoProgressModelTest.php`.

In `backend/tests/Unit/StudentDashboardBuilderTest.php`:
- `test_video_and_material_counts_exclude_inactive_book_disabled_video_and_archived_material`: remove whatever lines create `ChapterVideoProgress` rows and remove the assertion `$this->assertSame(1, $chapters[0]['video_completed']);`. Keep the `video_total`/`materials_total`/`materials_accessed` assertions.
- `test_video_and_material_counts_exclude_rows_whose_user_type_is_not_student_user_even_with_a_matching_id`: this test exists specifically to prove `ChapterVideoProgress`'s polymorphic `user_type` filtering works — that feature no longer exists, so delete this test method entirely.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=StudentDashboardBuilderTest --compact`
Expected: FAIL — `video_completed` key doesn't exist in the assertion target yet because `StudentDashboardBuilder` hasn't changed, so the old key is still present; once you've removed the assertion per Step 1 the remaining failure is `ChapterVideoProgress` class/table still referenced. (If PHPUnit reports a class-not-found or missing-table error instead, that's the expected failure state before Step 5.)

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Video-progress tracking is removed outright — no replacement. No
     * production data exists (dev/test only), so this is a clean table
     * drop.
     */
    public function up(): void
    {
        Schema::dropIfExists('chapter_video_progress');
    }

    /**
     * Reverse the migrations. Recreates the table in its final shape
     * (post 2026_11_12_100400_make_content_log_and_video_progress_polymorphic),
     * not its original shape.
     */
    public function down(): void
    {
        Schema::create('chapter_video_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_video_id')->constrained()->cascadeOnDelete();
            $table->string('user_type')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('not_started');
            $table->unsignedInteger('max_position_seconds')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['chapter_video_id', 'user_type', 'user_id']);
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Delete the model, request, and resource**

Delete `backend/app/Models/ChapterVideoProgress.php`, `backend/app/Http/Requests/Api/V1/UpdateChapterVideoProgressRequest.php`, and `backend/app/Http/Resources/Api/V1/ChapterVideoProgressResource.php`.

- [ ] **Step 5: Remove the progress endpoint from the controller and routes**

In `backend/app/Http/Controllers/Api/V1/ChapterVideoController.php`:
- Remove the `use App\Http\Requests\Api\V1\UpdateChapterVideoProgressRequest;`, `use App\Http\Resources\Api\V1\ChapterVideoProgressResource;`, `use App\Models\ChapterVideoProgress;`, and `use Illuminate\Database\QueryException;` imports (the `use Illuminate\Support\Facades\DB;` import stays — `reorder()` still uses it).
- Remove the entire `updateProgress()` method and the entire `applyVideoProgress()` private method.

In `backend/routes/api.php`, remove this line from the `auth:sanctum` group:

```php
        Route::put('/chapter-videos/{chapter_video}/progress', [ChapterVideoController::class, 'updateProgress']);
```

- [ ] **Step 6: Remove the dashboard's video-completion stat**

In `backend/app/Support/StudentDashboardBuilder.php`:
- Remove the `use App\Models\ChapterVideoProgress;` import.
- Remove the `$completedVideoIds = ChapterVideoProgress::query()...->pluck('chapter_video_id');` block.
- In the `$chapterRollups` map, remove the `'video_completed' => ...` key (keep `'video_total' => $chapterVideos->count(),`).
- Remove the now-unused `$completedVideoIds` parameter from the closure's `use` clause.

The chapter rollup array in `buildSubjectRollup()` becomes:

```php
            return [
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->name,
                'video_total' => $chapterVideos->count(),
                'materials_total' => $chapterMaterials->count(),
                'materials_accessed' => $chapterMaterials->filter(fn ($material) => $accessedMaterialIds->contains($material->id))->count(),
            ];
```

and the closure signature becomes:

```php
        $chapterRollups = $chapters->map(function ($chapter) use ($videosByChapter, $materialsByChapter, $accessedMaterialIds) {
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=StudentDashboardBuilderTest --compact`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_18_100500_drop_chapter_video_progress_table.php app/Http/Controllers/Api/V1/ChapterVideoController.php routes/api.php app/Support/StudentDashboardBuilder.php tests/Unit/StudentDashboardBuilderTest.php
git rm app/Models/ChapterVideoProgress.php app/Http/Requests/Api/V1/UpdateChapterVideoProgressRequest.php app/Http/Resources/Api/V1/ChapterVideoProgressResource.php tests/Feature/ChapterVideoProgressControllerTest.php tests/Unit/ChapterVideoProgressModelTest.php
git commit -m "Remove video-progress tracking entirely"
```

---

## Task 7: De-scope `questions`

**Files:**
- Create: `backend/database/migrations/2026_11_18_100600_drop_tenant_columns_from_questions_table.php`
- Modify: `backend/app/Models/Question.php`
- Modify: `backend/app/Http/Controllers/Api/V1/QuestionController.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/tests/Feature/QuestionControllerTest.php`
- Create: `backend/tests/Feature/QuestionAuthoringPermissionMappingTest.php`
- Test: `backend/tests/Feature/QuestionControllerTest.php`, `backend/tests/Feature/QuestionAuthoringPermissionMappingTest.php`

**Interfaces:**
- Consumes: `Chapter` with no `school_id`/`district_id` (Task 3).
- Produces: `Question` with no `school_id`/`district_id`; `questions.create`/`update`/`delete` mapped to `national_admin` only.

- [ ] **Step 1: Write the failing tests**

In `backend/tests/Feature/QuestionControllerTest.php`, update `setUp()` the same way as Task 4/5:

```php
        $bookA = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);
        $bookB = Book::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'name' => 'Mathematics Book']);

        $this->chapterA = Chapter::create(['book_id' => $bookA->id, 'name' => 'Chapter 1']);
        $this->chapterB = Chapter::create(['book_id' => $bookB->id, 'name' => 'Chapter 1']);
```

Remove `'school_id' => $this->chapterA->school_id, 'district_id' => $this->chapterA->district_id,` (and the `chapterB` equivalent) from every `Question::create([...])` call in this file (in `test_index_excludes_inactive_questions_unless_the_caller_can_manage_them`, `test_reorder_sets_order_index_from_the_given_sequence`, `test_reorder_rejects_an_id_from_a_different_chapter`).

Find `test_actor_with_permission_gets_404_updating_another_schools_question` (builds a `Question` with `'school_id' => $this->chapterB->school_id, 'district_id' => $this->chapterB->district_id` and asserts `->assertNotFound()`). Replace the whole method:

```php
    public function test_actor_with_permission_can_update_a_question_belonging_to_a_different_schools_chapter(): void
    {
        $question = Question::create([
            'chapter_id' => $this->chapterB->id,
            'question_type' => 'mcq_single',
            'question_text' => 'School B Question',
        ]);

        $actor = $this->actorWithPermissions(['questions.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($actor, 'sanctum')
            ->patchJson("/api/v1/questions/{$question->id}", ['question_text' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.question_text', 'Renamed');
    }
```

Create `backend/tests/Feature/QuestionAuthoringPermissionMappingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionAuthoringPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * questions is national content now — only national_admin may author
     * it. teacher previously held questions.create/update; granting it
     * to any other role here would be a real authorization regression.
     */
    public function test_questions_create_is_mapped_to_exactly_national_admin(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'questions.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin'], $roles);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=QuestionControllerTest --compact`, `php artisan test --filter=QuestionAuthoringPermissionMappingTest --compact`
Expected: FAIL

- [ ] **Step 3: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same reasoning as books/chapters/chapter_materials/chapter_videos:
     * questions.school_id/district_id were denormalized purely for
     * tenant-scope filtering, which is removed.
     */
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropForeign(['school_id']);
            $table->dropForeign(['district_id']);
            $table->dropColumn(['school_id', 'district_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('school_id')->after('chapter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->after('school_id')->constrained()->cascadeOnDelete();
        });
    }
};
```

Run: `php artisan migrate`

- [ ] **Step 4: Update the model**

In `backend/app/Models/Question.php`, remove `TenantScoped` from the `use` statement, its import, and remove `'school_id'`/`'district_id'` from `$fillable`.

- [ ] **Step 5: Update the controller**

In `backend/app/Http/Controllers/Api/V1/QuestionController.php`:
- `store()`: remove the two denormalization lines:

```php
        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        $data = $request->safe()->except(['image', 'options']);
```

(was: `... $data = $request->safe()->except(['image', 'options']); $data['school_id'] = $chapter->school_id; $data['district_id'] = $chapter->district_id; ...`)

- `import()`: remove the two denormalization lines from the `Question::create([...])` call:

```php
                    $question = Question::create([
                        ...$parsed['question'],
                    ]);
```

(was: `... Question::create([ ...$parsed['question'], 'school_id' => $chapter->school_id, 'district_id' => $chapter->district_id, ]);`)

`QuestionResource` (checked during planning) does not expose `school_id`/`district_id` — no change needed there.

- [ ] **Step 6: Restrict authoring to national_admin in RolePermissionSeeder**

In `backend/database/seeders/RolePermissionSeeder.php`:
- In the `'teacher'` array, change `'questions.view', 'questions.create', 'questions.update',` to `'questions.view',`.
- In the `'trainer'` array, change `'questions.view', 'questions.create', 'questions.update',` to `'questions.view',`.

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=QuestionControllerTest --compact`, `php artisan test --filter=QuestionAuthoringPermissionMappingTest --compact`
Expected: PASS

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_11_18_100600_drop_tenant_columns_from_questions_table.php app/Models/Question.php app/Http/Controllers/Api/V1/QuestionController.php database/seeders/RolePermissionSeeder.php tests/Feature/QuestionControllerTest.php tests/Feature/QuestionAuthoringPermissionMappingTest.php
git commit -m "Make questions national content, authored by national_admin only"
```

---

## Task 8: Fix `LmsContentDemoSeeder` for the de-scoped models

**Files:**
- Modify: `backend/database/seeders/LmsContentDemoSeeder.php`
- Test: manual seeder smoke run (this seeder has no dedicated automated test — see Step 2)

**Interfaces:**
- Consumes: every model change from Tasks 1–7 (no `school_id`/`district_id` on `AcademicYear`/`Book`/`Chapter`/`ChapterMaterial`/`ChapterVideo`/`Question`; `ChapterVideoProgress` no longer exists).

- [ ] **Step 1: Update the seeder**

In `backend/database/seeders/LmsContentDemoSeeder.php`:

1. Remove the `use App\Models\ChapterVideoProgress;` import.
2. Line `$academicYear = AcademicYear::where('school_id', 1)->where('is_current', true)->first();` becomes:

```php
        $academicYear = AcademicYear::where('is_current', true)->first();
```

3. In `seedBookWithChapters()`, remove `'school_id' => $version->school_id, 'district_id' => $version->district_id,` from the `Book::updateOrCreate()` attributes array, and remove `'school_id' => $book->school_id, 'district_id' => $book->district_id,` from the `Chapter::updateOrCreate()` attributes array.
4. In `seedChapterContent()`, remove `'school_id' => $chapter->school_id, 'district_id' => $chapter->district_id,` from both `ChapterMaterial::updateOrCreate()` calls, and from the `ChapterVideo::updateOrCreate()` call.
5. In `seedChapterContent()`, delete the two `ChapterVideoProgress::updateOrCreate(...)` blocks and the `$firstVideo`/`$secondVideo` variables that only existed to feed them. The method ends after the `$createdVideos = collect($videos)->values()->map(...)` statement; `$createdVideos` becomes unused and its assignment can be simplified to a bare statement:

```php
        collect($videos)->values()->each(fn (array $video, int $index) => ChapterVideo::updateOrCreate(
            ['chapter_id' => $chapter->id, 'youtube_video_id' => $video['youtube_video_id']],
            [
                'title' => $video['title'],
                'instructor_name' => 'Teacher',
                'duration_seconds' => $video['duration_seconds'],
                'completion_threshold_percent' => 90,
                'order_index' => $index + 1,
                'is_enabled' => true,
            ],
        ));
```

   (`seedChapterContent()`'s `StudentProfile $studentProfile` parameter is now unused by this method body — but it's still passed from `run()` for the caller's own bookkeeping clarity; leave the parameter in place since removing it would require touching the two call sites for no behavioral benefit, and an unused parameter here isn't a lint failure in this codebase's Pint config.)
6. In `seedQuestions()`, remove `'school_id' => $chapter->school_id, 'district_id' => $chapter->district_id, ` from all three `Question::updateOrCreate()` attribute arrays (they become e.g. `['question_type' => 'mcq_single', 'marks' => 2, 'difficulty' => 'easy', 'order_index' => 1]`).

- [ ] **Step 2: Verify the seeder runs cleanly**

Run: `php artisan migrate:fresh --seed --env=testing` (or, if that artisan option isn't configured for a dedicated testing DB in this project, run against a disposable local DB — do not run against any shared/production database)

Expected: no errors; `LmsContentDemoSeeder::run()` completes (it's a no-op if its prerequisites aren't present, per its own docblock, but with `DatabaseSeeder`'s full chain seeded first, it should execute fully here without SQL errors about unknown columns or missing `ChapterVideoProgress` class).

- [ ] **Step 3: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/LmsContentDemoSeeder.php
git commit -m "Fix LmsContentDemoSeeder for the de-scoped national content tables"
```

---

## Task 9: Verify `AcademicYearPolicy`, `BookPolicy`, `ChapterPolicy`, `ChapterMaterialPolicy`, `ChapterVideoPolicy`, `QuestionPolicy` need no code changes

**Files:**
- Test: `backend/tests/Feature/AcademicYearAuthoringPermissionMappingTest.php`, `backend/tests/Feature/BookAuthoringPermissionMappingTest.php`, `backend/tests/Feature/ChapterAuthoringPermissionMappingTest.php`, `backend/tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php`, `backend/tests/Feature/ChapterVideoAuthoringPermissionMappingTest.php`, `backend/tests/Feature/QuestionAuthoringPermissionMappingTest.php` (all already created/passing from Tasks 1–7)

**Interfaces:**
- Consumes: the six permission-mapping tests already written.

This task is a verification checkpoint, not new code — the spec calls out explicitly that these six policies need no changes (they already just check `$user->hasPermission('<resource>.<verb>')`; the authorization tightening happens entirely in `RolePermissionSeeder`, already done in Tasks 1–7). There is nothing to implement here; it exists so the plan's step-by-step record shows this was checked rather than silently assumed.

- [ ] **Step 1: Confirm no policy file was touched**

Run: `git log --oneline -- app/Policies/AcademicYearPolicy.php app/Policies/BookPolicy.php app/Policies/ChapterPolicy.php app/Policies/ChapterMaterialPolicy.php app/Policies/ChapterVideoPolicy.php app/Policies/QuestionPolicy.php`
Expected: no commits from this plan appear in the output (these files are untouched).

- [ ] **Step 2: Re-run all six permission-mapping tests together**

Run: `php artisan test --filter=AuthoringPermissionMappingTest --compact`
Expected: PASS (all six)

No commit needed — this task makes no file changes.

---

## Task 10: Full backend test suite against MySQL

**Files:**
- None (verification only)

**Interfaces:**
- Consumes: every change from Tasks 1–9.

This repo has a documented history of SQLite masking MySQL-only migration/behavior bugs. This task is the final gate before considering this sub-project done.

- [ ] **Step 1: Confirm the test environment's DB connection targets MySQL**

Run: `php artisan config:show database.default` and `php artisan config:show database.connections.mysql` (or check `phpunit.xml`/`.env.testing` for `DB_CONNECTION`). If the test suite is configured to run against SQLite, switch `DB_CONNECTION`/`DB_DATABASE` (etc.) in `.env.testing` to point at a real MySQL test database before proceeding — do not skip this step by reasoning that "the SQLite run already passed."

- [ ] **Step 2: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, zero failures.

- [ ] **Step 3: If anything fails, fix forward**

Any failure here is a gap this plan's task-level test runs didn't catch (e.g. a test file this plan didn't touch that still references a dropped column, or a MySQL-specific FK/constraint-ordering issue invisible under SQLite). Fix the root cause in the relevant file from Tasks 1–8, re-run the single failing test, then re-run the full suite again. Do not weaken or delete a test to make it pass unless the test's own premise (e.g. asserting old tenant-scoped behavior) is what's actually obsolete — in which case replace it the same way Tasks 1–7 replaced the "another school's X returns 404" tests, not just delete it silently.

- [ ] **Step 4: Commit if any fix-forward changes were made**

```bash
vendor/bin/pint --dirty --format agent
git add -A
git commit -m "Fix stragglers found by the full MySQL test run"
```

(Skip this step entirely if Step 2 passed clean with no changes needed.)
