# Academic Exam Results — Phase 1: National Config Tables Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the three national, school_admin-and-below-read-only config tables the whole Academic Exam Results feature depends on — exam terms, the grade/GPA scale, and per-(class, subject) full-marks splits — with full backend CRUD and a management-portal UI for each, exactly as scoped in "Section A" and the "Frontend" section of the spec.

**Architecture:** Three independent, structurally identical resources (migration → model → policy → store/update requests → resource → controller → routes), each following the existing `HolidayController`/`HolidayPolicy`/`StoreHolidayRequest` pattern precisely (public `index`/`show`, `national_admin`-only mutate via a dedicated permission). No resource depends on another. The management-portal UI for each follows the existing `GeoCrudTable` pattern used by `HolidaysPage.tsx`/`AcademicYearsPage.tsx`.

**Tech Stack:** Laravel 13 / PHP 8.5 (backend), React + TypeScript + Vite (management frontend), MySQL in production / SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-11-20-academic-exam-results-design.md` — this plan implements only "Data model" rows 1-3 (`academic_exam_terms`, `academic_grade_configures`, `academic_subject_mark_configures`) and the matching slice of "Frontend" → Management. Everything else in the spec (marks entry, publishing, `MarksheetBuilder`, promotion, teacher/student UI) is out of scope for this plan — later phases.

## Global Constraints

- Table and model names are **plural** (`academic_exam_terms`, not `academic_exam_term`) — explicit user instruction during brainstorming.
- All three tables are **national** — no `school_id`, no `TenantScoped` trait. Every row applies to every school.
- Every table uses `Auditable` (polymorphic `created_by_id`/`created_by_type`/`updated_by_id`/`updated_by_type`, no DB-level FK) + `SoftDeletes`.
- Mutating (`store`/`update`/`destroy`) is `national_admin`-only; `index`/`show` are public (no auth), matching `holidays`' own tier exactly.
- After any backend PHP change: `vendor/bin/pint --dirty --format agent` must report `{"result":"passed"}`, and `php artisan test --compact` must stay fully green (not just the new test file).
- After any frontend change: `npx tsc -b --noEmit` (run from the specific app directory touched) must produce no output, and `npm run build` (same directory) must succeed.
- Every new/changed i18n key must exist in **both** `packages/i18n/src/common/en.json` and `packages/i18n/src/common/bn.json` — verify with:
  ```bash
  node -e "
  const en = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/en.json','utf8'));
  const bn = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/bn.json','utf8'));
  const enKeys = Object.keys(en).sort();
  const bnKeys = Object.keys(bn).sort();
  console.log('missing in bn:', enKeys.filter(k => !bnKeys.includes(k)));
  console.log('missing in en:', bnKeys.filter(k => !enKeys.includes(k)));
  "
  ```
  run from `frontend/`.
- Never commit without the two checks above passing for whatever you touched in that commit.

---

### Task 1: `academic_exam_terms` backend (migration, model, policy, requests, resource, controller, routes, tests)

**Files:**
- Create (via `php artisan make:migration create_academic_exam_terms_table --no-interaction`, then edit): `backend/database/migrations/<timestamp>_create_academic_exam_terms_table.php`
- Create: `backend/app/Models/AcademicExamTerm.php`
- Create: `backend/app/Policies/AcademicExamTermPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreAcademicExamTermRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateAcademicExamTermRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/AcademicExamTermResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/AcademicExamTermController.php`
- Create: `backend/tests/Feature/AcademicExamTermControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `App\Models\AcademicExamTerm` with fillable `name`, `bn_name`, `sort_order`, `is_annual` (bool), `is_active` (bool, default true). Route `GET/POST /api/v1/academic-exam-terms`, `GET/PATCH/DELETE /api/v1/academic-exam-terms/{academic_exam_term}`.
- Consumes: nothing from elsewhere in this plan (independent of Tasks 2-3). Uses the existing `App\Policies\Concerns` trait pattern is NOT needed here (no cross-school scoping — this table is national).

- [ ] **Step 1: Generate and write the migration**

```bash
cd backend && php artisan make:migration create_academic_exam_terms_table --no-interaction
```

Replace the generated file's contents with:

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
     * National reference data — the exam-term calendar (First Terminal,
     * Second Terminal, Annual/Final, ...) is the same for every school in
     * the country, same tier as academic_years/holidays. `is_annual`
     * marks exactly one row as the term whose result decides promotion
     * (enforced at the application layer, not here — see
     * StoreAcademicExamTermRequest/UpdateAcademicExamTermRequest).
     */
    public function up(): void
    {
        Schema::create('academic_exam_terms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->string('name');
            $table->string('bn_name')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_annual')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_exam_terms');
    }
};
```

- [ ] **Step 2: Create the model**

`backend/app/Models/AcademicExamTerm.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicExamTerm extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'name',
        'bn_name',
        'sort_order',
        'is_annual',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_annual' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
```

- [ ] **Step 3: Migrate and confirm the table exists**

```bash
cd backend && php artisan migrate --no-interaction
```

Expected: `academic_exam_terms ... DONE` in the output, no errors.

- [ ] **Step 4: Write the failing feature test**

`backend/tests/Feature/AcademicExamTermControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicExamTerm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicExamTermControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_is_public(): void
    {
        AcademicExamTerm::create(['name' => 'First Terminal Examination', 'bn_name' => 'প্রথম সাময়িক পরীক্ষা', 'sort_order' => 1]);

        $this->getJson('/api/v1/academic-exam-terms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'First Terminal Examination');
    }

    public function test_show_is_public(): void
    {
        $term = AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);

        $this->getJson("/api/v1/academic-exam-terms/{$term->id}")
            ->assertOk()
            ->assertJsonPath('data.is_annual', true);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-exam-terms', [
            'name' => 'First Terminal Examination',
        ])->assertForbidden();
    }

    public function test_store_creates_a_term(): void
    {
        $user = $this->actorWithPermissions(['academic_exam_terms.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-exam-terms', [
            'name' => 'First Terminal Examination',
            'bn_name' => 'প্রথম সাময়িক পরীক্ষা',
            'sort_order' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'First Terminal Examination')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('academic_exam_terms', ['name' => 'First Terminal Examination', 'is_active' => true]);
    }

    public function test_store_rejects_a_second_annual_term(): void
    {
        AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);

        $user = $this->actorWithPermissions(['academic_exam_terms.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-exam-terms', [
            'name' => 'Final Examination',
            'sort_order' => 4,
            'is_annual' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_annual');
    }

    public function test_update_requires_permission(): void
    {
        $term = AcademicExamTerm::create(['name' => 'Second Terminal Examination', 'sort_order' => 2]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-exam-terms/{$term->id}", [
            'name' => 'Updated',
        ])->assertForbidden();
    }

    public function test_update_rejects_marking_a_second_term_annual(): void
    {
        AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);
        $second = AcademicExamTerm::create(['name' => 'Second Terminal Examination', 'sort_order' => 2]);

        $user = $this->actorWithPermissions(['academic_exam_terms.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-exam-terms/{$second->id}", [
            'is_annual' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_annual');
    }

    public function test_update_allows_re_saving_the_existing_annual_term(): void
    {
        $term = AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);
        $user = $this->actorWithPermissions(['academic_exam_terms.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-exam-terms/{$term->id}", [
            'name' => 'Annual/Final Examination',
            'is_annual' => true,
        ])->assertOk()->assertJsonPath('data.name', 'Annual/Final Examination');
    }

    public function test_destroy_requires_permission(): void
    {
        $term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-exam-terms/{$term->id}")->assertForbidden();
    }

    public function test_destroy_deletes_the_term(): void
    {
        $term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $user = $this->actorWithPermissions(['academic_exam_terms.delete']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-exam-terms/{$term->id}")->assertNoContent();

        $this->assertSoftDeleted('academic_exam_terms', ['id' => $term->id]);
    }
}
```

- [ ] **Step 5: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=AcademicExamTermControllerTest
```

Expected: FAIL — route `/api/v1/academic-exam-terms` doesn't exist yet (404s), or the `actorWithPermissions` calls fail because `hasPermission` on a permission that doesn't yet exist — either way, every assertion should fail, none should pass.

- [ ] **Step 6: Write the policy**

`backend/app/Policies/AcademicExamTermPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\AcademicExamTerm;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicExamTermPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_terms.view');
    }

    public function view(Authenticatable $user, AcademicExamTerm $academicExamTerm): bool
    {
        return $user->hasPermission('academic_exam_terms.view');
    }

    /**
     * national_admin only — the exam-term calendar is national, not
     * per-school, same reasoning as HolidayPolicy.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_terms.create');
    }

    public function update(Authenticatable $user, AcademicExamTerm $academicExamTerm): bool
    {
        return $user->hasPermission('academic_exam_terms.update');
    }

    public function delete(Authenticatable $user, AcademicExamTerm $academicExamTerm): bool
    {
        return $user->hasPermission('academic_exam_terms.delete');
    }
}
```

- [ ] **Step 7: Write the Store/Update form requests**

`backend/app/Http/Requests/Api/V1/StoreAcademicExamTermRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamTerm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAcademicExamTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicExamTerm::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'bn_name' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_annual' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Only one term may be the annual/final one — promotion (a later
     * phase of this feature) reads exactly one row with is_annual=true.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->boolean('is_annual') && AcademicExamTerm::where('is_annual', true)->exists()) {
                $validator->errors()->add('is_annual', 'Another exam term is already marked as the annual/final term.');
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateAcademicExamTermRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamTerm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAcademicExamTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_exam_term')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'bn_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_annual' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Same single-annual-term guard as the store request, excluding this
     * row itself (re-saving the already-annual term must not self-reject).
     */
    public function withValidator(Validator $validator): void
    {
        $term = $this->route('academic_exam_term');

        $validator->after(function (Validator $validator) use ($term) {
            if ($this->boolean('is_annual') && AcademicExamTerm::where('is_annual', true)->whereKeyNot($term->id)->exists()) {
                $validator->errors()->add('is_annual', 'Another exam term is already marked as the annual/final term.');
            }
        });
    }
}
```

- [ ] **Step 8: Write the API resource**

`backend/app/Http/Resources/Api/V1/AcademicExamTermResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicExamTermResource extends JsonResource
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
            'sort_order' => $this->sort_order,
            'is_annual' => $this->is_annual,
            'is_active' => $this->is_active,
        ];
    }
}
```

- [ ] **Step 9: Write the controller**

`backend/app/Http/Controllers/Api/V1/AcademicExamTermController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicExamTermRequest;
use App\Http\Requests\Api\V1\UpdateAcademicExamTermRequest;
use App\Http\Resources\Api\V1\AcademicExamTermResource;
use App\Models\AcademicExamTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AcademicExamTermController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Public, same tier as classes/subjects/holidays — national
     * reference data, not sensitive. Ordered by sort_order so the
     * configured term sequence (First Terminal, Second Terminal,
     * Annual...) stays stable regardless of insertion order.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $terms = AcademicExamTerm::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 15));

        return AcademicExamTermResource::collection($terms);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicExamTerm $academic_exam_term): AcademicExamTermResource
    {
        return new AcademicExamTermResource($academic_exam_term);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAcademicExamTermRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_annual'] = $request->boolean('is_annual');
        $data['is_active'] = $request->boolean('is_active', true);

        $term = AcademicExamTerm::create($data);

        return (new AcademicExamTermResource($term))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicExamTermRequest $request, AcademicExamTerm $academic_exam_term): AcademicExamTermResource
    {
        $academic_exam_term->update($request->validated());

        return new AcademicExamTermResource($academic_exam_term);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicExamTerm $academic_exam_term): Response
    {
        $this->authorize('delete', $academic_exam_term);

        $academic_exam_term->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 10: Register the routes**

In `backend/routes/api.php`, add the import near the other controller `use` statements (alphabetical, next to `AcademicYearController`):

```php
use App\Http\Controllers\Api\V1\AcademicExamTermController;
```

Add to the public block, right after the `academic-years` line:

```php
Route::apiResource('academic-exam-terms', AcademicExamTermController::class)->only(['index', 'show']);
```

Add to the `auth:sanctum` block, right after the `academic-years` lines:

```php
Route::apiResource('academic-exam-terms', AcademicExamTermController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 11: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=AcademicExamTermControllerTest
```

Expected: `"result":"passed"`, all 10 tests green.

- [ ] **Step 12: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: Pint `{"result":"passed"}`; full suite fully green (no regressions in any other test file).

- [ ] **Step 13: Commit**

```bash
cd backend && git add app/Models/AcademicExamTerm.php app/Policies/AcademicExamTermPolicy.php app/Http/Requests/Api/V1/StoreAcademicExamTermRequest.php app/Http/Requests/Api/V1/UpdateAcademicExamTermRequest.php app/Http/Resources/Api/V1/AcademicExamTermResource.php app/Http/Controllers/Api/V1/AcademicExamTermController.php database/migrations tests/Feature/AcademicExamTermControllerTest.php routes/api.php
git commit -m "Add academic_exam_terms: national exam-term calendar with single-annual-term guard"
```

---

### Task 2: `academic_grade_configures` backend (migration, model, policy, requests, resource, controller, routes, tests)

**Files:**
- Create (via `php artisan make:migration create_academic_grade_configures_table --no-interaction`, then edit): `backend/database/migrations/<timestamp>_create_academic_grade_configures_table.php`
- Create: `backend/app/Models/AcademicGradeConfigure.php`
- Create: `backend/app/Policies/AcademicGradeConfigurePolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreAcademicGradeConfigureRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateAcademicGradeConfigureRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/AcademicGradeConfigureResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/AcademicGradeConfigureController.php`
- Create: `backend/tests/Feature/AcademicGradeConfigureControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `App\Models\AcademicGradeConfigure` with fillable `min_percent`, `max_percent`, `grade_name`, `bn_grade_name`, `grade_point`, `sort_order`, `is_active`, and a static `AcademicGradeConfigure::forPercent(float $percent): ?self` — **this exact signature is relied on by `MarksheetBuilder` in a later phase; do not rename it.** Routes `GET/POST /api/v1/academic-grade-configures`, `GET/PATCH/DELETE /api/v1/academic-grade-configures/{academic_grade_configure}`.
- Consumes: nothing from Task 1 or Task 3 (fully independent).

- [ ] **Step 1: Generate and write the migration**

```bash
cd backend && php artisan make:migration create_academic_grade_configures_table --no-interaction
```

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
     * National reference data — one grade scale applies to every school
     * in the country (same tier as academic_years/holidays/
     * academic_exam_terms). Each row is a percentage range mapped to a
     * grade name and grade point — e.g. 80.00-100.00 -> A+ -> 5.00.
     * Ranges must not overlap among active rows (enforced in
     * StoreAcademicGradeConfigureRequest/UpdateAcademicGradeConfigureRequest,
     * not here) — AcademicGradeConfigure::forPercent() assumes exactly one
     * active row matches any given percentage.
     */
    public function up(): void
    {
        Schema::create('academic_grade_configures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->decimal('min_percent', 5, 2);
            $table->decimal('max_percent', 5, 2);
            $table->string('grade_name');
            $table->string('bn_grade_name')->nullable();
            $table->decimal('grade_point', 3, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['min_percent', 'max_percent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_grade_configures');
    }
};
```

- [ ] **Step 2: Create the model**

`backend/app/Models/AcademicGradeConfigure.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicGradeConfigure extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'min_percent',
        'max_percent',
        'grade_name',
        'bn_grade_name',
        'grade_point',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_percent' => 'decimal:2',
            'max_percent' => 'decimal:2',
            'grade_point' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The grade whose [min_percent, max_percent] range contains $percent,
     * among active rows — the single lookup every grade/GPA computation
     * in the Academic Exam Results feature uses (MarksheetBuilder,
     * promotion eligibility display, both added in later phases).
     */
    public static function forPercent(float $percent): ?self
    {
        return static::query()
            ->where('is_active', true)
            ->where('min_percent', '<=', $percent)
            ->where('max_percent', '>=', $percent)
            ->orderByDesc('min_percent')
            ->first();
    }
}
```

- [ ] **Step 3: Migrate**

```bash
cd backend && php artisan migrate --no-interaction
```

Expected: `academic_grade_configures ... DONE`.

- [ ] **Step 4: Write the failing feature test**

`backend/tests/Feature/AcademicGradeConfigureControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicGradeConfigure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicGradeConfigureControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_index_is_public(): void
    {
        AcademicGradeConfigure::create([
            'min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00, 'sort_order' => 1,
        ]);

        $this->getJson('/api/v1/academic-grade-configures')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.grade_name', 'A+');
    }

    public function test_show_is_public(): void
    {
        $grade = AcademicGradeConfigure::create([
            'min_percent' => 33, 'max_percent' => 39.99, 'grade_name' => 'D', 'grade_point' => 1.00, 'sort_order' => 6,
        ]);

        $this->getJson("/api/v1/academic-grade-configures/{$grade->id}")
            ->assertOk()
            ->assertJsonPath('data.grade_point', '1.00');
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00,
        ])->assertForbidden();
    }

    public function test_store_creates_a_grade_row(): void
    {
        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'bn_grade_name' => 'এ প্লাস', 'grade_point' => 5.00, 'sort_order' => 1,
        ])->assertCreated()->assertJsonPath('data.grade_name', 'A+');

        $this->assertDatabaseHas('academic_grade_configures', ['grade_name' => 'A+']);
    }

    public function test_store_rejects_an_overlapping_range(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 70, 'max_percent' => 79.99, 'grade_name' => 'A', 'grade_point' => 4.00, 'sort_order' => 2]);

        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 75, 'max_percent' => 85, 'grade_name' => 'Overlap', 'grade_point' => 4.50,
        ])->assertUnprocessable()->assertJsonValidationErrors('min_percent');
    }

    public function test_store_rejects_max_percent_below_min_percent(): void
    {
        $user = $this->actorWithPermissions(['academic_grade_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-grade-configures', [
            'min_percent' => 80, 'max_percent' => 70, 'grade_name' => 'Bad', 'grade_point' => 4.00,
        ])->assertUnprocessable()->assertJsonValidationErrors('max_percent');
    }

    public function test_update_requires_permission(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'grade_name' => 'Updated',
        ])->assertForbidden();
    }

    public function test_update_allows_re_saving_its_own_unchanged_range(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$grade->id}", [
            'min_percent' => 80, 'max_percent' => 100, 'bn_grade_name' => 'এ প্লাস',
        ])->assertOk()->assertJsonPath('data.bn_grade_name', 'এ প্লাস');
    }

    public function test_update_rejects_moving_into_another_rows_range(): void
    {
        $first = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        AcademicGradeConfigure::create(['min_percent' => 70, 'max_percent' => 79.99, 'grade_name' => 'A', 'grade_point' => 4.00]);

        $user = $this->actorWithPermissions(['academic_grade_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-grade-configures/{$first->id}", [
            'min_percent' => 75,
        ])->assertUnprocessable()->assertJsonValidationErrors('min_percent');
    }

    public function test_destroy_requires_permission(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-grade-configures/{$grade->id}")->assertForbidden();
    }

    public function test_destroy_deletes_the_row(): void
    {
        $grade = AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        $user = $this->actorWithPermissions(['academic_grade_configures.delete']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-grade-configures/{$grade->id}")->assertNoContent();

        $this->assertSoftDeleted('academic_grade_configures', ['id' => $grade->id]);
    }

    public function test_for_percent_finds_the_matching_active_row(): void
    {
        AcademicGradeConfigure::create(['min_percent' => 80, 'max_percent' => 100, 'grade_name' => 'A+', 'grade_point' => 5.00]);
        AcademicGradeConfigure::create(['min_percent' => 70, 'max_percent' => 79.99, 'grade_name' => 'A', 'grade_point' => 4.00]);
        AcademicGradeConfigure::create(['min_percent' => 0, 'max_percent' => 32.99, 'grade_name' => 'F', 'grade_point' => 0.00, 'is_active' => false]);

        $this->assertSame('A', AcademicGradeConfigure::forPercent(75.5)->grade_name);
        $this->assertNull(AcademicGradeConfigure::forPercent(20.0));
    }
}
```

- [ ] **Step 5: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=AcademicGradeConfigureControllerTest
```

Expected: FAIL (route/permission/model don't exist yet).

- [ ] **Step 6: Write the policy**

`backend/app/Policies/AcademicGradeConfigurePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\AcademicGradeConfigure;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicGradeConfigurePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_grade_configures.view');
    }

    public function view(Authenticatable $user, AcademicGradeConfigure $academicGradeConfigure): bool
    {
        return $user->hasPermission('academic_grade_configures.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_grade_configures.create');
    }

    public function update(Authenticatable $user, AcademicGradeConfigure $academicGradeConfigure): bool
    {
        return $user->hasPermission('academic_grade_configures.update');
    }

    public function delete(Authenticatable $user, AcademicGradeConfigure $academicGradeConfigure): bool
    {
        return $user->hasPermission('academic_grade_configures.delete');
    }
}
```

- [ ] **Step 7: Write the Store/Update form requests**

`backend/app/Http/Requests/Api/V1/StoreAcademicGradeConfigureRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicGradeConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAcademicGradeConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicGradeConfigure::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'min_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_percent' => ['required', 'numeric', 'min:0', 'max:100', 'gte:min_percent'],
            'grade_name' => ['required', 'string', 'max:50'],
            'bn_grade_name' => ['nullable', 'string', 'max:50'],
            'grade_point' => ['required', 'numeric', 'min:0', 'max:5'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * No two active rows' [min_percent, max_percent] ranges may overlap —
     * AcademicGradeConfigure::forPercent() assumes exactly one active row
     * matches any given percentage.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->hasAny(['min_percent', 'max_percent'])) {
                return;
            }

            if ($this->boolean('is_active', true) && $this->overlapsAnotherActiveRow()) {
                $validator->errors()->add('min_percent', 'This range overlaps another active grade configuration.');
            }
        });
    }

    private function overlapsAnotherActiveRow(): bool
    {
        return AcademicGradeConfigure::where('is_active', true)
            ->where('min_percent', '<=', $this->input('max_percent'))
            ->where('max_percent', '>=', $this->input('min_percent'))
            ->exists();
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateAcademicGradeConfigureRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicGradeConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAcademicGradeConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_grade_configure')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'min_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'max_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100', 'gte:min_percent'],
            'grade_name' => ['sometimes', 'required', 'string', 'max:50'],
            'bn_grade_name' => ['sometimes', 'nullable', 'string', 'max:50'],
            'grade_point' => ['sometimes', 'required', 'numeric', 'min:0', 'max:5'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Same overlap guard as the store request, comparing the *effective*
     * range (submitted value, falling back to the existing row) and
     * excluding this row itself.
     */
    public function withValidator(Validator $validator): void
    {
        $configure = $this->route('academic_grade_configure');

        $validator->after(function (Validator $validator) use ($configure) {
            if ($validator->errors()->hasAny(['min_percent', 'max_percent'])) {
                return;
            }

            $isActive = $this->boolean('is_active', $configure->is_active);
            if (! $isActive) {
                return;
            }

            $min = $this->input('min_percent', $configure->min_percent);
            $max = $this->input('max_percent', $configure->max_percent);

            $overlaps = AcademicGradeConfigure::where('is_active', true)
                ->whereKeyNot($configure->id)
                ->where('min_percent', '<=', $max)
                ->where('max_percent', '>=', $min)
                ->exists();

            if ($overlaps) {
                $validator->errors()->add('min_percent', 'This range overlaps another active grade configuration.');
            }
        });
    }
}
```

- [ ] **Step 8: Write the API resource**

`backend/app/Http/Resources/Api/V1/AcademicGradeConfigureResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicGradeConfigureResource extends JsonResource
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
            'min_percent' => (string) $this->min_percent,
            'max_percent' => (string) $this->max_percent,
            'grade_name' => $this->grade_name,
            'bn_grade_name' => $this->bn_grade_name,
            'grade_point' => (string) $this->grade_point,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
```

- [ ] **Step 9: Write the controller**

`backend/app/Http/Controllers/Api/V1/AcademicGradeConfigureController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicGradeConfigureRequest;
use App\Http\Requests\Api\V1\UpdateAcademicGradeConfigureRequest;
use App\Http\Resources\Api\V1\AcademicGradeConfigureResource;
use App\Models\AcademicGradeConfigure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AcademicGradeConfigureController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Public, same tier as holidays/academic-exam-terms. Ordered by
     * sort_order (admin-controlled, typically highest grade first).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $grades = AcademicGradeConfigure::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 15));

        return AcademicGradeConfigureResource::collection($grades);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicGradeConfigure $academic_grade_configure): AcademicGradeConfigureResource
    {
        return new AcademicGradeConfigureResource($academic_grade_configure);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAcademicGradeConfigureRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        $grade = AcademicGradeConfigure::create($data);

        return (new AcademicGradeConfigureResource($grade))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicGradeConfigureRequest $request, AcademicGradeConfigure $academic_grade_configure): AcademicGradeConfigureResource
    {
        $academic_grade_configure->update($request->validated());

        return new AcademicGradeConfigureResource($academic_grade_configure);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicGradeConfigure $academic_grade_configure): Response
    {
        $this->authorize('delete', $academic_grade_configure);

        $academic_grade_configure->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 10: Register the routes**

In `backend/routes/api.php`, add the import:

```php
use App\Http\Controllers\Api\V1\AcademicGradeConfigureController;
```

Public block (after the `academic-exam-terms` line added in Task 1):

```php
Route::apiResource('academic-grade-configures', AcademicGradeConfigureController::class)->only(['index', 'show']);
```

`auth:sanctum` block (after the `academic-exam-terms` line added in Task 1):

```php
Route::apiResource('academic-grade-configures', AcademicGradeConfigureController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 11: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=AcademicGradeConfigureControllerTest
```

Expected: all 12 tests green.

- [ ] **Step 12: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: both green, no regressions.

- [ ] **Step 13: Commit**

```bash
cd backend && git add app/Models/AcademicGradeConfigure.php app/Policies/AcademicGradeConfigurePolicy.php app/Http/Requests/Api/V1/StoreAcademicGradeConfigureRequest.php app/Http/Requests/Api/V1/UpdateAcademicGradeConfigureRequest.php app/Http/Resources/Api/V1/AcademicGradeConfigureResource.php app/Http/Controllers/Api/V1/AcademicGradeConfigureController.php database/migrations tests/Feature/AcademicGradeConfigureControllerTest.php routes/api.php
git commit -m "Add academic_grade_configures: national grade/GPA scale with overlap guard"
```

---

### Task 3: `academic_subject_mark_configures` backend (migration, model, policy, requests, resource, controller, routes, tests)

**Files:**
- Create (via `php artisan make:migration create_academic_subject_mark_configures_table --no-interaction`, then edit): `backend/database/migrations/<timestamp>_create_academic_subject_mark_configures_table.php`
- Create: `backend/app/Models/AcademicSubjectMarkConfigure.php`
- Create: `backend/app/Policies/AcademicSubjectMarkConfigurePolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreAcademicSubjectMarkConfigureRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateAcademicSubjectMarkConfigureRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/AcademicSubjectMarkConfigureResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/AcademicSubjectMarkConfigureController.php`
- Create: `backend/tests/Feature/AcademicSubjectMarkConfigureControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `App\Models\AcademicSubjectMarkConfigure` with fillable `class_id`, `subject_id`, `cq_full_marks`, `mcq_full_marks`, `practical_full_marks`, and a `totalFullMarks(): int` accessor-style method — **this exact method name is relied on by `MarksheetBuilder` in a later phase.** Routes `GET/POST /api/v1/academic-subject-mark-configures`, `GET/PATCH/DELETE /api/v1/academic-subject-mark-configures/{academic_subject_mark_configure}`.
- Consumes: `App\Models\SchoolClass` (existing, `school_classes` table) and `App\Models\Subject` (existing, `subjects` table) — both already present in this codebase, no changes needed to either.

- [ ] **Step 1: Generate and write the migration**

```bash
cd backend && php artisan make:migration create_academic_subject_mark_configures_table --no-interaction
```

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
     * National reference data — one row per (class, subject) pair, used
     * by every school in the country, same tier as academic_exam_terms/
     * academic_grade_configures. Each of the three full-marks columns is
     * independently nullable: a subject with no practical component
     * simply leaves practical_full_marks null (never zero), so it's
     * never counted toward a student's total or pass/fail check for that
     * subject — see AcademicExamMark (added in a later phase of this
     * feature) for where that rule is actually applied.
     */
    public function up(): void
    {
        Schema::create('academic_subject_mark_configures', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cq_full_marks')->nullable();
            $table->unsignedSmallInteger('mcq_full_marks')->nullable();
            $table->unsignedSmallInteger('practical_full_marks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['class_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_subject_mark_configures');
    }
};
```

- [ ] **Step 2: Create the model**

`backend/app/Models/AcademicSubjectMarkConfigure.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicSubjectMarkConfigure extends Model
{
    use Auditable, SoftDeletes;

    protected $fillable = [
        'class_id',
        'subject_id',
        'cq_full_marks',
        'mcq_full_marks',
        'practical_full_marks',
    ];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * Sum of whichever components are configured (non-null) — the
     * denominator every percentage calculation in this feature uses.
     * Relied on by MarksheetBuilder (added in a later phase); keep this
     * exact method name.
     */
    public function totalFullMarks(): int
    {
        return ($this->cq_full_marks ?? 0) + ($this->mcq_full_marks ?? 0) + ($this->practical_full_marks ?? 0);
    }
}
```

- [ ] **Step 3: Migrate**

```bash
cd backend && php artisan migrate --no-interaction
```

Expected: `academic_subject_mark_configures ... DONE`.

- [ ] **Step 4: Write the failing feature test**

`backend/tests/Feature/AcademicSubjectMarkConfigureControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicSubjectMarkConfigure;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicSubjectMarkConfigureControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private SchoolClass $class;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $this->subject = Subject::create(['name' => 'Mathematics']);
    }

    public function test_index_is_public(): void
    {
        AcademicSubjectMarkConfigure::create([
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30,
        ]);

        $this->getJson('/api/v1/academic-subject-mark-configures')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cq_full_marks', 70);
    }

    public function test_show_is_public(): void
    {
        $configure = AcademicSubjectMarkConfigure::create([
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 50, 'mcq_full_marks' => 25, 'practical_full_marks' => 25,
        ]);

        $this->getJson("/api/v1/academic-subject-mark-configures/{$configure->id}")
            ->assertOk()
            ->assertJsonPath('data.practical_full_marks', 25);
    }

    public function test_store_is_forbidden_without_permission(): void
    {
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30,
        ])->assertForbidden();
    }

    public function test_store_creates_a_configure_row(): void
    {
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30,
        ])->assertCreated()->assertJsonPath('data.cq_full_marks', 70);

        $this->assertDatabaseHas('academic_subject_mark_configures', ['class_id' => $this->class->id, 'subject_id' => $this->subject->id]);
    }

    public function test_store_rejects_all_three_components_null(): void
    {
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('cq_full_marks');
    }

    public function test_store_rejects_a_duplicate_class_subject_pair(): void
    {
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $user = $this->actorWithPermissions(['academic_subject_mark_configures.create']);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/academic-subject-mark-configures', [
            'class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 100,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_update_requires_permission(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-subject-mark-configures/{$configure->id}", [
            'cq_full_marks' => 80,
        ])->assertForbidden();
    }

    public function test_update_changes_full_marks(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.update']);

        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-subject-mark-configures/{$configure->id}", [
            'cq_full_marks' => 80,
        ])->assertOk()->assertJsonPath('data.cq_full_marks', 80);
    }

    public function test_update_rejects_a_partial_update_that_collides_with_another_row(): void
    {
        $english = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $english->id, 'cq_full_marks' => 60, 'mcq_full_marks' => 40]);

        $user = $this->actorWithPermissions(['academic_subject_mark_configures.update']);

        // Submits only subject_id — class_id is absent, so the request's
        // effective class_id falls back to the row's own stored value
        // (the class above). The resulting (class, subject) pair collides
        // with the first row, even though class_id itself was never sent.
        $this->actingAs($user, 'sanctum')->patchJson("/api/v1/academic-subject-mark-configures/{$configure->id}", [
            'subject_id' => $this->subject->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('class_id');
    }

    public function test_destroy_requires_permission(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions([]);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-subject-mark-configures/{$configure->id}")->assertForbidden();
    }

    public function test_destroy_deletes_the_row(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);
        $user = $this->actorWithPermissions(['academic_subject_mark_configures.delete']);

        $this->actingAs($user, 'sanctum')->deleteJson("/api/v1/academic-subject-mark-configures/{$configure->id}")->assertNoContent();

        $this->assertSoftDeleted('academic_subject_mark_configures', ['id' => $configure->id]);
    }

    public function test_total_full_marks_sums_only_configured_components(): void
    {
        $configure = AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $this->assertSame(100, $configure->totalFullMarks());
    }
}
```

- [ ] **Step 5: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=AcademicSubjectMarkConfigureControllerTest
```

Expected: FAIL (route/permission/model don't exist yet).

- [ ] **Step 6: Write the policy**

`backend/app/Policies/AcademicSubjectMarkConfigurePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicSubjectMarkConfigurePolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.view');
    }

    public function view(Authenticatable $user, AcademicSubjectMarkConfigure $academicSubjectMarkConfigure): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.create');
    }

    public function update(Authenticatable $user, AcademicSubjectMarkConfigure $academicSubjectMarkConfigure): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.update');
    }

    public function delete(Authenticatable $user, AcademicSubjectMarkConfigure $academicSubjectMarkConfigure): bool
    {
        return $user->hasPermission('academic_subject_mark_configures.delete');
    }
}
```

- [ ] **Step 7: Write the Store/Update form requests**

`backend/app/Http/Requests/Api/V1/StoreAcademicSubjectMarkConfigureRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAcademicSubjectMarkConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicSubjectMarkConfigure::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => [
                'required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed(),
                Rule::unique('academic_subject_mark_configures')->withoutTrashed()->where(fn ($query) => $query->where('subject_id', $this->input('subject_id'))),
            ],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'cq_full_marks' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'mcq_full_marks' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'practical_full_marks' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * At least one component must be configured, or every downstream
     * percentage calculation (full marks = 0) would divide by zero.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->filled('cq_full_marks') && ! $this->filled('mcq_full_marks') && ! $this->filled('practical_full_marks')) {
                $validator->errors()->add('cq_full_marks', 'At least one of CQ, MCQ, or Practical full marks must be set.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_id.unique' => 'This class/subject pair is already configured.',
        ];
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateAcademicSubjectMarkConfigureRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAcademicSubjectMarkConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_subject_mark_configure')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'class_id' => ['sometimes', 'required', 'integer', Rule::exists('school_classes', 'id')->withoutTrashed()],
            'subject_id' => ['sometimes', 'required', 'integer', Rule::exists('subjects', 'id')->withoutTrashed()],
            'cq_full_marks' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'mcq_full_marks' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
            'practical_full_marks' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    /**
     * The class/subject uniqueness check and the at-least-one-component
     * guard both live here, not in `rules()`, and both use *effective*
     * values (submitted, falling back to the existing row) — not just for
     * the component guard, but for uniqueness too. A `Rule::unique(...)`
     * attached only to `class_id`'s own rule entry would never run on a
     * PATCH that submits only `subject_id` (a `sometimes` rule simply
     * skips validating a field that's absent from the request), silently
     * letting that request move the row onto a (class_id, subject_id)
     * pair that's already configured elsewhere. Computing both effective
     * values here checks the real resulting pair regardless of which
     * field(s) were actually submitted.
     */
    public function withValidator(Validator $validator): void
    {
        $configure = $this->route('academic_subject_mark_configure');

        $validator->after(function (Validator $validator) use ($configure) {
            if ($validator->errors()->hasAny(['class_id', 'subject_id'])) {
                return;
            }

            $classId = $this->input('class_id', $configure->class_id);
            $subjectId = $this->input('subject_id', $configure->subject_id);

            $duplicate = AcademicSubjectMarkConfigure::where('class_id', $classId)
                ->where('subject_id', $subjectId)
                ->whereKeyNot($configure->id)
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('class_id', 'This class/subject pair is already configured.');
            }

            $cq = $this->has('cq_full_marks') ? $this->input('cq_full_marks') : $configure->cq_full_marks;
            $mcq = $this->has('mcq_full_marks') ? $this->input('mcq_full_marks') : $configure->mcq_full_marks;
            $practical = $this->has('practical_full_marks') ? $this->input('practical_full_marks') : $configure->practical_full_marks;

            if ($cq === null && $mcq === null && $practical === null) {
                $validator->errors()->add('cq_full_marks', 'At least one of CQ, MCQ, or Practical full marks must be set.');
            }
        });
    }
}
```

- [ ] **Step 8: Write the API resource**

`backend/app/Http/Resources/Api/V1/AcademicSubjectMarkConfigureResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicSubjectMarkConfigureResource extends JsonResource
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
            'class_id' => $this->class_id,
            'subject_id' => $this->subject_id,
            'cq_full_marks' => $this->cq_full_marks,
            'mcq_full_marks' => $this->mcq_full_marks,
            'practical_full_marks' => $this->practical_full_marks,
            'class' => new ClassResource($this->whenLoaded('schoolClass')),
            'subject' => new SubjectResource($this->whenLoaded('subject')),
        ];
    }
}
```

- [ ] **Step 9: Write the controller**

`backend/app/Http/Controllers/Api/V1/AcademicSubjectMarkConfigureController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicSubjectMarkConfigureRequest;
use App\Http\Requests\Api\V1\UpdateAcademicSubjectMarkConfigureRequest;
use App\Http\Resources\Api\V1\AcademicSubjectMarkConfigureResource;
use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AcademicSubjectMarkConfigureController extends Controller
{
    private const WITH = ['schoolClass', 'subject'];

    /**
     * Display a listing of the resource.
     *
     * Public, same tier as classes/subjects. Filterable by class_id so
     * the teacher-portal mark entry screen (a later phase) can ask
     * "what's configured for this class" in one call.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $configures = AcademicSubjectMarkConfigure::query()
            ->with(self::WITH)
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('subject_id'), fn ($query, $subjectId) => $query->where('subject_id', $subjectId))
            ->orderBy('class_id')
            ->paginate($request->integer('per_page', 15));

        return AcademicSubjectMarkConfigureResource::collection($configures);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicSubjectMarkConfigure $academic_subject_mark_configure): AcademicSubjectMarkConfigureResource
    {
        return new AcademicSubjectMarkConfigureResource($academic_subject_mark_configure->load(self::WITH));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAcademicSubjectMarkConfigureRequest $request): JsonResponse
    {
        $configure = AcademicSubjectMarkConfigure::create($request->validated());

        return (new AcademicSubjectMarkConfigureResource($configure->load(self::WITH)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicSubjectMarkConfigureRequest $request, AcademicSubjectMarkConfigure $academic_subject_mark_configure): AcademicSubjectMarkConfigureResource
    {
        $academic_subject_mark_configure->update($request->validated());

        return new AcademicSubjectMarkConfigureResource($academic_subject_mark_configure->load(self::WITH));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicSubjectMarkConfigure $academic_subject_mark_configure): Response
    {
        $this->authorize('delete', $academic_subject_mark_configure);

        $academic_subject_mark_configure->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 10: Register the routes**

In `backend/routes/api.php`, add the import:

```php
use App\Http\Controllers\Api\V1\AcademicSubjectMarkConfigureController;
```

Public block (after the `academic-grade-configures` line added in Task 2):

```php
Route::apiResource('academic-subject-mark-configures', AcademicSubjectMarkConfigureController::class)->only(['index', 'show']);
```

`auth:sanctum` block (after the `academic-grade-configures` lines added in Task 2):

```php
Route::apiResource('academic-subject-mark-configures', AcademicSubjectMarkConfigureController::class)->only(['store', 'update', 'destroy']);
```

- [ ] **Step 11: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=AcademicSubjectMarkConfigureControllerTest
```

Expected: all 12 tests green.

- [ ] **Step 12: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: both green.

- [ ] **Step 13: Commit**

```bash
cd backend && git add app/Models/AcademicSubjectMarkConfigure.php app/Policies/AcademicSubjectMarkConfigurePolicy.php app/Http/Requests/Api/V1/StoreAcademicSubjectMarkConfigureRequest.php app/Http/Requests/Api/V1/UpdateAcademicSubjectMarkConfigureRequest.php app/Http/Resources/Api/V1/AcademicSubjectMarkConfigureResource.php app/Http/Controllers/Api/V1/AcademicSubjectMarkConfigureController.php database/migrations tests/Feature/AcademicSubjectMarkConfigureControllerTest.php routes/api.php
git commit -m "Add academic_subject_mark_configures: national per-class-subject full marks"
```

---

### Task 4: Permissions and role grants for all three resources

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`

**Interfaces:**
- Produces: 12 new permission rows (`academic_exam_terms.{view,create,update,delete}`, `academic_grade_configures.{view,create,update,delete}`, `academic_subject_mark_configures.{view,create,update,delete}`), with `.view` granted to every role that currently has `holidays.view`.
- Consumes: Tasks 1-3's permission names exactly as used in their Policy classes (`academic_exam_terms.view`/`.create`/`.update`/`.delete`, etc. — must match verbatim or the policies silently deny everyone).

- [ ] **Step 1: Add the 12 permissions**

In `backend/database/seeders/PermissionSeeder.php`, add after the existing `holidays.*` block:

```php
// The exam-term calendar, grade/GPA scale, and per-class-subject full
// marks are all national config (same tier as holidays.*) — .create/
// .update/.delete are only ever granted to national_admin; every other
// role gets .view only.
['name' => 'academic_exam_terms.view', 'label' => 'View academic exam terms'],
['name' => 'academic_exam_terms.create', 'label' => 'Create academic exam terms'],
['name' => 'academic_exam_terms.update', 'label' => 'Update academic exam terms'],
['name' => 'academic_exam_terms.delete', 'label' => 'Delete academic exam terms'],

['name' => 'academic_grade_configures.view', 'label' => 'View the academic grade scale'],
['name' => 'academic_grade_configures.create', 'label' => 'Create academic grade configuration'],
['name' => 'academic_grade_configures.update', 'label' => 'Update academic grade configuration'],
['name' => 'academic_grade_configures.delete', 'label' => 'Delete academic grade configuration'],

['name' => 'academic_subject_mark_configures.view', 'label' => 'View subject mark configuration'],
['name' => 'academic_subject_mark_configures.create', 'label' => 'Create subject mark configuration'],
['name' => 'academic_subject_mark_configures.update', 'label' => 'Update subject mark configuration'],
['name' => 'academic_subject_mark_configures.delete', 'label' => 'Delete subject mark configuration'],
```

- [ ] **Step 2: Grant `.view` to every role that already has `holidays.view`**

In `backend/database/seeders/RolePermissionSeeder.php`, every occurrence of `'holidays.view'` in the `district_officer`, `school_admin`, `teacher`, `student`, `trainer`, `office_staff`, `parent_guardian`, `it_admin`, and `auditor` arrays needs the three new `.view` permissions added right after it. For example, the `teacher` array's line:

```php
'classrooms.view', 'class_routines.view', 'holidays.view',
```

becomes:

```php
'classrooms.view', 'class_routines.view', 'holidays.view',
'academic_exam_terms.view', 'academic_grade_configures.view', 'academic_subject_mark_configures.view',
```

Apply the identical two-line change (keep `holidays.view`'s line as-is, add the new line right after it) to every one of the 9 role arrays listed above. `national_admin` needs no change — it already gets `$allPermissions` (every permission, including the 12 new ones automatically).

- [ ] **Step 3: Re-seed and verify**

```bash
cd backend && php artisan db:seed --class=PermissionSeeder --no-interaction && php artisan db:seed --class=RolePermissionSeeder --no-interaction
```

Expected: both seeders run with no errors.

```bash
php artisan tinker --execute '
$teacher = App\Models\Role::where("name", "teacher")->first();
echo $teacher->permissions()->where("name", "like", "academic_%")->pluck("name")->implode(", ") . PHP_EOL;
$admin = App\Models\Role::where("name", "national_admin")->first();
echo $admin->permissions()->where("name", "like", "academic_%")->count() . PHP_EOL;
'
```

Expected: the teacher line lists exactly the 3 `.view` permissions; the national_admin count is `12`.

- [ ] **Step 4: Run the full backend suite**

```bash
cd backend && php artisan test --compact && vendor/bin/pint --dirty --format agent
```

Expected: both green — this task touches only seeders, which no feature test depends on (tests create their own permissions via `CreatesAuthorizedActors`), so this should be a pure no-op for the test suite.

- [ ] **Step 5: Commit**

```bash
cd backend && git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php
git commit -m "Grant academic exam-results config permissions to all view-tier roles"
```

---

### Task 5: `api-client` additions for all three resources

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`

**Interfaces:**
- Produces: TypeScript types `AcademicExamTerm`, `AcademicExamTermPayload`, `AcademicGradeConfigure`, `AcademicGradeConfigurePayload`, `AcademicSubjectMarkConfigure`, `AcademicSubjectMarkConfigurePayload`, and functions `listAcademicExamTerms`/`createAcademicExamTerm`/`updateAcademicExamTerm`/`deleteAcademicExamTerm` (and the equivalent triads for the other two resources) — **these exact names are what Task 6-8's pages import.**
- Consumes: the existing `listGeo`/`createGeo`/`updateGeo`/`deleteGeo` generics and `PaginatedResponse<T>` type already defined earlier in this same file (used by every other national-config resource, e.g. `listDivisions`/`listSubjects`), plus the existing `SchoolClass`/`Subject` types for the nested `class`/`subject` fields on `AcademicSubjectMarkConfigure`.

- [ ] **Step 1: Add the three sections**

Find the end of the file (after `reviewLeaveApplication`, the last export) in `frontend/packages/api-client/src/index.ts` and append:

```typescript
// ---------- Academic exam terms ----------

export interface AcademicExamTerm {
  id: number
  name: string
  bn_name: string | null
  sort_order: number
  is_annual: boolean
  is_active: boolean
}

export interface AcademicExamTermPayload {
  name: string
  bn_name?: string | null
  sort_order?: number | null
  is_annual?: boolean
  is_active?: boolean
}

export function listAcademicExamTerms(
  params: { page?: number; perPage?: number; activeOnly?: boolean } = {},
): Promise<PaginatedResponse<AcademicExamTerm>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    active_only: params.activeOnly ? 'true' : undefined,
  }

  return apiFetch<PaginatedResponse<AcademicExamTerm>>(`/academic-exam-terms${buildQuery(query)}`)
}

export function createAcademicExamTerm(payload: AcademicExamTermPayload, token: string): Promise<{ data: AcademicExamTerm }> {
  return createGeo<AcademicExamTerm, AcademicExamTermPayload>('academic-exam-terms', payload, token)
}

export function updateAcademicExamTerm(
  id: number,
  payload: Partial<AcademicExamTermPayload>,
  token: string,
): Promise<{ data: AcademicExamTerm }> {
  return updateGeo<AcademicExamTerm, Partial<AcademicExamTermPayload>>('academic-exam-terms', id, payload, token)
}

export function deleteAcademicExamTerm(id: number, token: string): Promise<void> {
  return deleteGeo('academic-exam-terms', id, token)
}

// ---------- Academic grade configures ----------

export interface AcademicGradeConfigure {
  id: number
  min_percent: string
  max_percent: string
  grade_name: string
  bn_grade_name: string | null
  grade_point: string
  sort_order: number
  is_active: boolean
}

export interface AcademicGradeConfigurePayload {
  min_percent: number
  max_percent: number
  grade_name: string
  bn_grade_name?: string | null
  grade_point: number
  sort_order?: number | null
  is_active?: boolean
}

export function listAcademicGradeConfigures(
  params: { page?: number; perPage?: number; activeOnly?: boolean } = {},
): Promise<PaginatedResponse<AcademicGradeConfigure>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    active_only: params.activeOnly ? 'true' : undefined,
  }

  return apiFetch<PaginatedResponse<AcademicGradeConfigure>>(`/academic-grade-configures${buildQuery(query)}`)
}

export function createAcademicGradeConfigure(
  payload: AcademicGradeConfigurePayload,
  token: string,
): Promise<{ data: AcademicGradeConfigure }> {
  return createGeo<AcademicGradeConfigure, AcademicGradeConfigurePayload>('academic-grade-configures', payload, token)
}

export function updateAcademicGradeConfigure(
  id: number,
  payload: Partial<AcademicGradeConfigurePayload>,
  token: string,
): Promise<{ data: AcademicGradeConfigure }> {
  return updateGeo<AcademicGradeConfigure, Partial<AcademicGradeConfigurePayload>>('academic-grade-configures', id, payload, token)
}

export function deleteAcademicGradeConfigure(id: number, token: string): Promise<void> {
  return deleteGeo('academic-grade-configures', id, token)
}

// ---------- Academic subject mark configures ----------

export interface AcademicSubjectMarkConfigure {
  id: number
  class_id: number
  subject_id: number
  cq_full_marks: number | null
  mcq_full_marks: number | null
  practical_full_marks: number | null
  class?: SchoolClass | null
  subject?: Subject | null
}

export interface AcademicSubjectMarkConfigurePayload {
  class_id: number
  subject_id: number
  cq_full_marks?: number | null
  mcq_full_marks?: number | null
  practical_full_marks?: number | null
}

export function listAcademicSubjectMarkConfigures(
  params: { page?: number; perPage?: number; classId?: number; subjectId?: number } = {},
): Promise<PaginatedResponse<AcademicSubjectMarkConfigure>> {
  const query: Record<string, string | number | undefined> = {
    page: params.page,
    per_page: params.perPage,
    class_id: params.classId,
    subject_id: params.subjectId,
  }

  return apiFetch<PaginatedResponse<AcademicSubjectMarkConfigure>>(`/academic-subject-mark-configures${buildQuery(query)}`)
}

export function createAcademicSubjectMarkConfigure(
  payload: AcademicSubjectMarkConfigurePayload,
  token: string,
): Promise<{ data: AcademicSubjectMarkConfigure }> {
  return createGeo<AcademicSubjectMarkConfigure, AcademicSubjectMarkConfigurePayload>('academic-subject-mark-configures', payload, token)
}

export function updateAcademicSubjectMarkConfigure(
  id: number,
  payload: Partial<AcademicSubjectMarkConfigurePayload>,
  token: string,
): Promise<{ data: AcademicSubjectMarkConfigure }> {
  return updateGeo<AcademicSubjectMarkConfigure, Partial<AcademicSubjectMarkConfigurePayload>>(
    'academic-subject-mark-configures',
    id,
    payload,
    token,
  )
}

export function deleteAcademicSubjectMarkConfigure(id: number, token: string): Promise<void> {
  return deleteGeo('academic-subject-mark-configures', id, token)
}
```

- [ ] **Step 2: Typecheck**

```bash
cd frontend/packages/api-client && npx tsc -b --noEmit
```

Expected: no output (clean).

- [ ] **Step 3: Commit**

```bash
cd frontend && git add packages/api-client/src/index.ts
git commit -m "Add api-client types/functions for academic exam-results config resources"
```

---

### Task 6: Management — Exam Terms page

**Files:**
- Create: `frontend/apps/management/src/pages/AcademicExamTermsPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listAcademicExamTerms`/`createAcademicExamTerm`/`updateAcademicExamTerm`/`deleteAcademicExamTerm` and the `AcademicExamTerm`/`AcademicExamTermPayload` types from Task 5; the existing `GeoCrudTable`/`GeoField`/`inputClass` components (`frontend/apps/management/src/components/GeoCrudTable.tsx`, `frontend/apps/management/src/components/styles.ts`), unchanged.
- Produces: route `/dashboard/system-setup/academic-exam-terms`, nav item under "System Setup".

- [ ] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add after the existing `"management.systemSetup.subjects"` key:

```json
"management.systemSetup.academicExamTerms": "Exam Terms",
```

Add a new block anywhere alongside the other `management.fields.*`/`management.*Page.*`-style keys (next to `management.fields.allAcademicYears` is a reasonable spot):

```json
"management.academicExamTerms.title": "Exam Terms",
"management.fields.examTermName": "Name",
"management.fields.examTermBnName": "Bengali Name",
"management.fields.sortOrder": "Sort Order",
"management.fields.isAnnualTerm": "This is the Annual/Final term (decides promotion)",
```

Mirror the same five keys into `frontend/packages/i18n/src/common/bn.json`:

```json
"management.systemSetup.academicExamTerms": "পরীক্ষার মেয়াদ",
```

```json
"management.academicExamTerms.title": "পরীক্ষার মেয়াদ",
"management.fields.examTermName": "নাম",
"management.fields.examTermBnName": "বাংলা নাম",
"management.fields.sortOrder": "ক্রম",
"management.fields.isAnnualTerm": "এটি বার্ষিক/চূড়ান্ত পরীক্ষা (পরবর্তী শ্রেণিতে উত্তীর্ণ নির্ধারণ করে)",
```

Verify parity (run from `frontend/`):

```bash
node -e "
const en = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/en.json','utf8'));
const bn = JSON.parse(require('fs').readFileSync('packages/i18n/src/common/bn.json','utf8'));
const enKeys = Object.keys(en).sort();
const bnKeys = Object.keys(bn).sort();
console.log('missing in bn:', enKeys.filter(k => !bnKeys.includes(k)));
console.log('missing in en:', bnKeys.filter(k => !enKeys.includes(k)));
"
```

Expected: both arrays empty.

- [ ] **Step 2: Write the page**

`frontend/apps/management/src/pages/AcademicExamTermsPage.tsx`:

```tsx
import {
  createAcademicExamTerm,
  deleteAcademicExamTerm,
  listAcademicExamTerms,
  updateAcademicExamTerm,
  type AcademicExamTerm,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  name: '',
  bn_name: '',
  sort_order: '0',
  is_annual: 'false',
  is_active: 'true',
}

const requiredFields = ['name']

function toPayload(values: Record<string, string>) {
  return {
    name: values.name,
    bn_name: values.bn_name || null,
    sort_order: values.sort_order ? Number(values.sort_order) : null,
    is_annual: values.is_annual === 'true',
    is_active: values.is_active === 'true',
  }
}

function AnnualBadge({ isAnnual }: { isAnnual: boolean }) {
  return (
    <span
      className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${
        isAnnual ? 'bg-success-50 text-success-600' : 'bg-gray-100 text-gray-500'
      }`}
    >
      {isAnnual ? t['management.fields.yes'] : t['management.fields.no']}
    </span>
  )
}

export default function AcademicExamTermsPage() {
  const columns: GeoColumn<AcademicExamTerm>[] = [
    { key: 'sort_order', label: t['management.fields.sortOrder'], render: (row) => row.sort_order },
    { key: 'name', label: t['management.fields.examTermName'], render: (row) => row.bn_name ?? row.name },
    {
      key: 'is_annual',
      label: t['management.fields.isAnnualTerm'],
      render: (row) => <AnnualBadge isAnnual={row.is_annual} />,
    },
    {
      key: 'is_active',
      label: t['management.fields.isActive'],
      render: (row) => (row.is_active ? t['management.fields.yes'] : t['management.fields.no']),
    },
  ]

  return (
    <GeoCrudTable<AcademicExamTerm>
      title={t['management.academicExamTerms.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        name: row.name,
        bn_name: row.bn_name ?? '',
        sort_order: String(row.sort_order),
        is_annual: String(row.is_annual),
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.examTermName']} required error={fieldErrors.name}>
            <input className={inputClass} value={values.name} onChange={(event) => onChange('name', event.target.value)} />
          </GeoField>

          <GeoField label={t['management.fields.examTermBnName']} error={fieldErrors.bn_name}>
            <input className={inputClass} value={values.bn_name} onChange={(event) => onChange('bn_name', event.target.value)} />
          </GeoField>

          <GeoField label={t['management.fields.sortOrder']} error={fieldErrors.sort_order}>
            <input
              type="number"
              className={inputClass}
              value={values.sort_order}
              onChange={(event) => onChange('sort_order', event.target.value)}
            />
          </GeoField>

          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={values.is_annual === 'true'}
              onChange={(event) => onChange('is_annual', String(event.target.checked))}
            />
            {t['management.fields.isAnnualTerm']}
          </label>

          {fieldErrors.is_annual && <p className="text-sm text-danger-600">{fieldErrors.is_annual}</p>}

          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={values.is_active === 'true'}
              onChange={(event) => onChange('is_active', String(event.target.checked))}
            />
            {t['management.fields.isActive']}
          </label>
        </>
      )}
      fetchPage={(page) => listAcademicExamTerms({ page })}
      createRow={(values, token) => createAcademicExamTerm(toPayload(values), token)}
      updateRow={(id, values, token) => updateAcademicExamTerm(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteAcademicExamTerm(id, token)}
    />
  )
}
```

`GeoCrudTable`'s `fetchPage` prop is called as `fetchPage(page, filters)`, where `filters.search` is always a string (defaulting to `''`) — `listAcademicExamTerms` has no `search` param, so the second argument is simply not used here. This is safe: `load()` in `GeoCrudTable.tsx` only ever reads whatever `fetchPage` returns, so an unused filter has no effect beyond the search box not narrowing results, which is acceptable for a short, admin-only config list.

- [ ] **Step 3: Wire the route and nav item**

In `frontend/apps/management/src/App.tsx`, add the import next to the other page imports:

```tsx
import AcademicExamTermsPage from './pages/AcademicExamTermsPage'
```

Add the route inside the existing `<Route path="system-setup" element={<RequireNationalAdmin />}>` block, after the `subjects` route:

```tsx
<Route path="academic-exam-terms" element={<AcademicExamTermsPage />} />
```

Add the nav item to the `systemSetupItem.children` array, after the `subjects` entry:

```tsx
{ to: '/dashboard/system-setup/academic-exam-terms', label: t['management.systemSetup.academicExamTerms'], icon: 'event_note' },
```

- [ ] **Step 4: Typecheck and build**

```bash
cd frontend/apps/management && npx tsc -b --noEmit && npm run build
```

Expected: no typecheck output; build succeeds.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add apps/management/src/pages/AcademicExamTermsPage.tsx apps/management/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add management Exam Terms config page"
```

---

### Task 7: Management — Grade Configuration page

**Files:**
- Create: `frontend/apps/management/src/pages/AcademicGradeConfiguresPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listAcademicGradeConfigures`/`createAcademicGradeConfigure`/`updateAcademicGradeConfigure`/`deleteAcademicGradeConfigure` and the `AcademicGradeConfigure`/`AcademicGradeConfigurePayload` types from Task 5.
- Produces: route `/dashboard/system-setup/academic-grade-configures`, nav item under "System Setup".

- [ ] **Step 1: Add i18n keys**

`en.json`, after `management.systemSetup.academicExamTerms`:

```json
"management.systemSetup.academicGradeConfigures": "Grade Configuration",
```

New block, after the `academicExamTerms`-related keys added in Task 6:

```json
"management.academicGradeConfigures.title": "Grade Configuration",
"management.fields.minPercent": "Min %",
"management.fields.maxPercent": "Max %",
"management.fields.gradeName": "Grade",
"management.fields.gradeBnName": "Bengali Grade",
"management.fields.gradePoint": "Grade Point",
```

`bn.json`, mirrored:

```json
"management.systemSetup.academicGradeConfigures": "গ্রেড কনফিগারেশন",
```

```json
"management.academicGradeConfigures.title": "গ্রেড কনফিগারেশন",
"management.fields.minPercent": "সর্বনিম্ন %",
"management.fields.maxPercent": "সর্বোচ্চ %",
"management.fields.gradeName": "গ্রেড",
"management.fields.gradeBnName": "বাংলা গ্রেড",
"management.fields.gradePoint": "গ্রেড পয়েন্ট",
```

Verify parity with the same `node -e` command as Task 6 Step 1.

- [ ] **Step 2: Write the page**

`frontend/apps/management/src/pages/AcademicGradeConfiguresPage.tsx`:

```tsx
import {
  createAcademicGradeConfigure,
  deleteAcademicGradeConfigure,
  listAcademicGradeConfigures,
  updateAcademicGradeConfigure,
  type AcademicGradeConfigure,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  min_percent: '',
  max_percent: '',
  grade_name: '',
  bn_grade_name: '',
  grade_point: '',
  sort_order: '0',
  is_active: 'true',
}

const requiredFields = ['min_percent', 'max_percent', 'grade_name', 'grade_point']

function toPayload(values: Record<string, string>) {
  return {
    min_percent: Number(values.min_percent),
    max_percent: Number(values.max_percent),
    grade_name: values.grade_name,
    bn_grade_name: values.bn_grade_name || null,
    grade_point: Number(values.grade_point),
    sort_order: values.sort_order ? Number(values.sort_order) : null,
    is_active: values.is_active === 'true',
  }
}

export default function AcademicGradeConfiguresPage() {
  const columns: GeoColumn<AcademicGradeConfigure>[] = [
    { key: 'sort_order', label: t['management.fields.sortOrder'], render: (row) => row.sort_order },
    { key: 'grade_name', label: t['management.fields.gradeName'], render: (row) => row.bn_grade_name ?? row.grade_name },
    { key: 'range', label: `${t['management.fields.minPercent']} - ${t['management.fields.maxPercent']}`, render: (row) => `${row.min_percent}% - ${row.max_percent}%` },
    { key: 'grade_point', label: t['management.fields.gradePoint'], render: (row) => row.grade_point },
    {
      key: 'is_active',
      label: t['management.fields.isActive'],
      render: (row) => (row.is_active ? t['management.fields.yes'] : t['management.fields.no']),
    },
  ]

  return (
    <GeoCrudTable<AcademicGradeConfigure>
      title={t['management.academicGradeConfigures.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        min_percent: row.min_percent,
        max_percent: row.max_percent,
        grade_name: row.grade_name,
        bn_grade_name: row.bn_grade_name ?? '',
        grade_point: row.grade_point,
        sort_order: String(row.sort_order),
        is_active: String(row.is_active),
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.minPercent']} required error={fieldErrors.min_percent}>
            <input
              type="number"
              step="0.01"
              className={inputClass}
              value={values.min_percent}
              onChange={(event) => onChange('min_percent', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.maxPercent']} required error={fieldErrors.max_percent}>
            <input
              type="number"
              step="0.01"
              className={inputClass}
              value={values.max_percent}
              onChange={(event) => onChange('max_percent', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.gradeName']} required error={fieldErrors.grade_name}>
            <input className={inputClass} value={values.grade_name} onChange={(event) => onChange('grade_name', event.target.value)} />
          </GeoField>

          <GeoField label={t['management.fields.gradeBnName']} error={fieldErrors.bn_grade_name}>
            <input
              className={inputClass}
              value={values.bn_grade_name}
              onChange={(event) => onChange('bn_grade_name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.gradePoint']} required error={fieldErrors.grade_point}>
            <input
              type="number"
              step="0.01"
              className={inputClass}
              value={values.grade_point}
              onChange={(event) => onChange('grade_point', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.sortOrder']} error={fieldErrors.sort_order}>
            <input
              type="number"
              className={inputClass}
              value={values.sort_order}
              onChange={(event) => onChange('sort_order', event.target.value)}
            />
          </GeoField>

          <label className="flex items-center gap-2 text-sm text-gray-700">
            <input
              type="checkbox"
              checked={values.is_active === 'true'}
              onChange={(event) => onChange('is_active', String(event.target.checked))}
            />
            {t['management.fields.isActive']}
          </label>
        </>
      )}
      fetchPage={(page) => listAcademicGradeConfigures({ page })}
      createRow={(values, token) => createAcademicGradeConfigure(toPayload(values), token)}
      updateRow={(id, values, token) => updateAcademicGradeConfigure(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteAcademicGradeConfigure(id, token)}
    />
  )
}
```

- [ ] **Step 3: Wire the route and nav item**

`App.tsx` import:

```tsx
import AcademicGradeConfiguresPage from './pages/AcademicGradeConfiguresPage'
```

Route, after the `academic-exam-terms` route added in Task 6:

```tsx
<Route path="academic-grade-configures" element={<AcademicGradeConfiguresPage />} />
```

Nav item, after the `academic-exam-terms` entry added in Task 6:

```tsx
{ to: '/dashboard/system-setup/academic-grade-configures', label: t['management.systemSetup.academicGradeConfigures'], icon: 'grade' },
```

- [ ] **Step 4: Typecheck and build**

```bash
cd frontend/apps/management && npx tsc -b --noEmit && npm run build
```

- [ ] **Step 5: Commit**

```bash
cd frontend && git add apps/management/src/pages/AcademicGradeConfiguresPage.tsx apps/management/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add management Grade Configuration page"
```

---

### Task 8: Management — Subject Mark Configuration page

**Files:**
- Create: `frontend/apps/management/src/pages/AcademicSubjectMarkConfiguresPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listAcademicSubjectMarkConfigures`/`createAcademicSubjectMarkConfigure`/`updateAcademicSubjectMarkConfigure`/`deleteAcademicSubjectMarkConfigure` and types from Task 5; existing `listClasses`/`listSubjects` from `api-client` (already used elsewhere in this app, e.g. `SubjectsPage.tsx`/`ClassesPage.tsx`) for the Class/Subject pickers.
- Produces: route `/dashboard/system-setup/academic-subject-mark-configures`, nav item under "System Setup".

- [ ] **Step 1: Add i18n keys**

`en.json`, after `management.systemSetup.academicGradeConfigures`:

```json
"management.systemSetup.academicSubjectMarkConfigures": "Subject Mark Configuration",
```

New block:

```json
"management.academicSubjectMarkConfigures.title": "Subject Mark Configuration",
"management.fields.cqFullMarks": "CQ Full Marks",
"management.fields.mcqFullMarks": "MCQ Full Marks",
"management.fields.practicalFullMarks": "Practical Full Marks",
```

`bn.json`, mirrored:

```json
"management.systemSetup.academicSubjectMarkConfigures": "বিষয়ভিত্তিক নম্বর কনফিগারেশন",
```

```json
"management.academicSubjectMarkConfigures.title": "বিষয়ভিত্তিক নম্বর কনফিগারেশন",
"management.fields.cqFullMarks": "সিকিউ পূর্ণমান",
"management.fields.mcqFullMarks": "এমসিকিউ পূর্ণমান",
"management.fields.practicalFullMarks": "ব্যবহারিক পূর্ণমান",
```

Verify parity.

- [ ] **Step 2: Write the page**

`frontend/apps/management/src/pages/AcademicSubjectMarkConfiguresPage.tsx`:

```tsx
import { useEffect, useState } from 'react'
import {
  createAcademicSubjectMarkConfigure,
  deleteAcademicSubjectMarkConfigure,
  listAcademicSubjectMarkConfigures,
  listClasses,
  listSubjects,
  updateAcademicSubjectMarkConfigure,
  type AcademicSubjectMarkConfigure,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, type GeoColumn } from '../components/GeoCrudTable'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

const initialValues = {
  class_id: '',
  subject_id: '',
  cq_full_marks: '',
  mcq_full_marks: '',
  practical_full_marks: '',
}

const requiredFields = ['class_id', 'subject_id']

function toPayload(values: Record<string, string>) {
  return {
    class_id: Number(values.class_id),
    subject_id: Number(values.subject_id),
    cq_full_marks: values.cq_full_marks ? Number(values.cq_full_marks) : null,
    mcq_full_marks: values.mcq_full_marks ? Number(values.mcq_full_marks) : null,
    practical_full_marks: values.practical_full_marks ? Number(values.practical_full_marks) : null,
  }
}

export default function AcademicSubjectMarkConfiguresPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])

  useEffect(() => {
    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))
  }, [])

  const columns: GeoColumn<AcademicSubjectMarkConfigure>[] = [
    { key: 'class', label: t['management.fields.class'], render: (row) => row.class?.bn_name ?? row.class?.name ?? row.class_id },
    { key: 'subject', label: t['management.fields.subject'], render: (row) => row.subject?.bn_name ?? row.subject?.name ?? row.subject_id },
    { key: 'cq', label: t['management.fields.cqFullMarks'], render: (row) => row.cq_full_marks ?? '—' },
    { key: 'mcq', label: t['management.fields.mcqFullMarks'], render: (row) => row.mcq_full_marks ?? '—' },
    { key: 'practical', label: t['management.fields.practicalFullMarks'], render: (row) => row.practical_full_marks ?? '—' },
  ]

  return (
    <GeoCrudTable<AcademicSubjectMarkConfigure>
      title={t['management.academicSubjectMarkConfigures.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        class_id: String(row.class_id),
        subject_id: String(row.subject_id),
        cq_full_marks: row.cq_full_marks !== null ? String(row.cq_full_marks) : '',
        mcq_full_marks: row.mcq_full_marks !== null ? String(row.mcq_full_marks) : '',
        practical_full_marks: row.practical_full_marks !== null ? String(row.practical_full_marks) : '',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.class']} required error={fieldErrors.class_id}>
            <select className={inputClass} value={values.class_id} onChange={(event) => onChange('class_id', event.target.value)}>
              <option value="">—</option>
              {classes.map((schoolClass) => (
                <option key={schoolClass.id} value={schoolClass.id}>
                  {schoolClass.bn_name ?? schoolClass.name}
                </option>
              ))}
            </select>
          </GeoField>

          <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
            <select className={inputClass} value={values.subject_id} onChange={(event) => onChange('subject_id', event.target.value)}>
              <option value="">—</option>
              {subjects.map((subject) => (
                <option key={subject.id} value={subject.id}>
                  {subject.bn_name ?? subject.name}
                </option>
              ))}
            </select>
          </GeoField>

          <GeoField label={t['management.fields.cqFullMarks']} error={fieldErrors.cq_full_marks}>
            <input
              type="number"
              className={inputClass}
              value={values.cq_full_marks}
              onChange={(event) => onChange('cq_full_marks', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.mcqFullMarks']} error={fieldErrors.mcq_full_marks}>
            <input
              type="number"
              className={inputClass}
              value={values.mcq_full_marks}
              onChange={(event) => onChange('mcq_full_marks', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.fields.practicalFullMarks']} error={fieldErrors.practical_full_marks}>
            <input
              type="number"
              className={inputClass}
              value={values.practical_full_marks}
              onChange={(event) => onChange('practical_full_marks', event.target.value)}
            />
          </GeoField>
        </>
      )}
      fetchPage={(page) => listAcademicSubjectMarkConfigures({ page })}
      createRow={(values, token) => createAcademicSubjectMarkConfigure(toPayload(values), token)}
      updateRow={(id, values, token) => updateAcademicSubjectMarkConfigure(id, toPayload(values), token)}
      deleteRow={(id, token) => deleteAcademicSubjectMarkConfigure(id, token)}
    />
  )
}
```

- [ ] **Step 3: Wire the route and nav item**

`App.tsx` import:

```tsx
import AcademicSubjectMarkConfiguresPage from './pages/AcademicSubjectMarkConfiguresPage'
```

Route, after the `academic-grade-configures` route added in Task 7:

```tsx
<Route path="academic-subject-mark-configures" element={<AcademicSubjectMarkConfiguresPage />} />
```

Nav item, after the `academic-grade-configures` entry added in Task 7:

```tsx
{ to: '/dashboard/system-setup/academic-subject-mark-configures', label: t['management.systemSetup.academicSubjectMarkConfigures'], icon: 'checklist' },
```

- [ ] **Step 4: Typecheck and build**

```bash
cd frontend/apps/management && npx tsc -b --noEmit && npm run build
```

- [ ] **Step 5: Commit**

```bash
cd frontend && git add apps/management/src/pages/AcademicSubjectMarkConfiguresPage.tsx apps/management/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add management Subject Mark Configuration page"
```

---

### Task 9: End-to-end manual verification

**Files:** none (verification only — no code changes).

**Interfaces:** none.

- [ ] **Step 1: Run the full backend suite one more time**

```bash
cd backend && php artisan test --compact && vendor/bin/pint --dirty --format agent
```

Expected: both fully green.

- [ ] **Step 2: Typecheck and build the management app one more time**

```bash
cd frontend/apps/management && npx tsc -b --noEmit && npm run build
```

Expected: clean.

- [ ] **Step 3: Manual smoke test in the browser**

Start the backend (`php artisan serve` or the project's existing dev setup) and the management app's dev server if not already running. Log in as a national_admin and:

1. Navigate to System Setup → Exam Terms. Create "First Terminal Examination" (sort_order 1), "Second Terminal Examination" (sort_order 2), and "Annual Examination" (sort_order 3, check "This is the Annual/Final term"). Confirm trying to also mark "Second Terminal Examination" as annual shows a validation error.
2. Navigate to System Setup → Grade Configuration. Create the standard Bangladesh GPA-5 rows (80-100 → A+ → 5.00, 70-79.99 → A → 4.00, 60-69.99 → A- → 3.50, 50-59.99 → B → 3.00, 40-49.99 → C → 2.00, 33-39.99 → D → 1.00, 0-32.99 → F → 0.00). Confirm creating an overlapping range (e.g. 35-45) shows a validation error.
3. Navigate to System Setup → Subject Mark Configuration. Pick an existing class (e.g. "Class 6") and subject (e.g. "Mathematics"), set CQ=70, MCQ=30. Confirm creating a second row for the exact same class+subject pair shows a validation error.
4. Log in as a school_admin or teacher and confirm all three pages are either read-only or absent as appropriate (per Global Constraints: only national_admin can create/update/delete; everyone else only has `.view`, so these three specific pages — gated under `RequireNationalAdmin` — shouldn't be reachable at all for a school_admin, same as the existing Holiday Calendar page).

Report back what you observed; this step has no pass/fail assertion beyond "did the three screens behave as described."

- [ ] **Step 4: No commit for this task** (verification-only).

---

## What this plan does NOT cover

Explicitly deferred to later phases, per the spec: `academic_exam_marks` (mark entry), `academic_exam_publications` (the publish gate), `MarksheetBuilder` and marksheet viewing, and `student_class_promotions` (promotion). None of those are started by this plan.
