# Question Builder Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let teachers build and print question papers from a national question bank (categorized by class/subject/chapter, CQ or MCQ), with school admins able to print (but not edit) any paper from their own school, and national_admin managing the bank itself.

**Architecture:** Three new backend tables — `academic_questions` (a global, non-tenant-scoped national bank), `question_papers` (a teacher's saved, tenant-scoped paper), and `question_paper_items` (its ordered drag-and-drop content: a bank-question reference or a heading). Three new/updated frontend surfaces — a management-portal CRUD page for the bank (national_admin only), a teacher-portal drag-and-drop builder + "my papers" list, and a management-portal read/print-only papers page (school_admin only).

**Tech Stack:** Laravel 11 (PHP 8.5) + Sanctum, Eloquent Policies, FormRequests; React 19 + TypeScript + Vite, react-router-dom 7, `@dnd-kit/core`/`@dnd-kit/sortable` (new dependency, teacher app only).

**Spec:** `docs/superpowers/specs/2026-11-25-question-builder-design.md`

## Global Constraints

- No route-level `permission:` middleware anywhere — every authorization check goes through `$this->authorize()` in a controller or `->can()` in a FormRequest's `authorize()`, backed by a Policy class. Never hardcode a role name in a Policy; Policies check `$user->hasPermission('resource.action')` only (`national_admin`'s all-permissions wildcard handles its access automatically).
- `academic_questions` has no `school_id`/`district_id` columns and no `TenantScoped` trait — it is a single global bank, not scoped per school.
- `question_papers` denormalizes `school_id`/`district_id` from the creating teacher at submission time (never from the request) and uses the standard `TenantScoped` trait — this alone gives every school-scoped actor (teacher, school_admin) visibility limited to their own school, and `national_admin` unrestricted visibility, with no custom scope needed.
- `question_papers.update`/`.delete` are further restricted, in the Policy (not the permission system), to the paper's own creator — a colleague teacher at the same school may view/print but never edit/delete.
- Every new table uses the `Auditable` trait (`App\Models\Concerns\Auditable`) except `question_paper_items`, which is a fully-replaced child collection with no independent audit value.
- MCQ options are a plain JSON array of exactly four option strings, with no correct-answer marker anywhere (content only, no answer key, per the approved design).
- Frontend: every new user-facing string goes into BOTH `frontend/packages/i18n/src/common/en.json` and `frontend/packages/i18n/src/common/bn.json` as flat dotted keys (e.g. `"teacher.questionBuilder.title"`) — these are flat key-value JSON files, not nested objects. A key present in one file and missing from the other breaks the i18n package's type-checking build.
- Follow the existing per-app duplication convention: shared primitives like `Pagination`/`SearchableSelect` are deliberately duplicated per app (`frontend/apps/teacher/src/components/`, `frontend/apps/management/src/components/`) rather than centralized in `packages/ui`. The print-rendering view for a question paper follows the same convention: a small page-local component duplicated in both the teacher app's "My Question Papers" page and the management app's "Question Papers" page, not a new shared package component.

---

### Task 1: Academic Questions — the national question bank (backend)

**Files:**
- Create: `backend/database/migrations/2026_11_26_090000_create_academic_questions_table.php`
- Create: `backend/app/Models/AcademicQuestion.php`
- Create: `backend/app/Policies/AcademicQuestionPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreAcademicQuestionRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateAcademicQuestionRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/AcademicQuestionResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/AcademicQuestionController.php`
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/AcademicQuestionControllerTest.php`
- Create: `backend/tests/Feature/AcademicQuestionPermissionMappingTest.php`

**Interfaces:**
- Consumes: `App\Models\Concerns\Auditable` trait; `App\Models\SchoolClass` (`id`, `name`, `bn_name`); `App\Models\Subject` (`id`, `name`, `bn_name`); `Tests\Concerns\CreatesAuthorizedActors` (`actorWithPermissions`, `teacherActorWithPermissions`).
- Produces: `AcademicQuestion::TYPES = ['cq', 'mcq']` (Task 2's `question_paper_items` validation references `academic_questions` rows by id — no direct code dependency on this constant, but the same two type strings). Route `academic-questions` (`index`/`store`/`update`/`destroy`) at `/api/v1/academic-questions`. Permissions `academic_questions.view|create|update|delete`.

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The national question bank — global, not tenant-scoped. No
     * school_id/district_id columns at all: every school's teachers
     * search and draw from the exact same pool, managed exclusively by
     * national_admin. chapter_number/chapter_name are plain denormalized
     * fields, NOT a FK into the existing per-school `chapters` table
     * (which is reached through Book -> ClassVersion -> school and would
     * force the same chapter to be re-entered once per school).
     */
    public function up(): void
    {
        Schema::create('academic_questions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->unsignedInteger('chapter_number');
            $table->string('chapter_name');

            // 'cq' | 'mcq' — validated at the application layer, same
            // convention as teacher_leave_applications.status.
            $table->string('question_type');
            $table->text('question_text');
            // MCQ only: four option texts as a JSON array. Null for CQ.
            $table->json('options')->nullable();
            $table->unsignedInteger('marks');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['school_class_id', 'subject_id', 'chapter_number']);
            $table->index('question_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_questions');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate` (from `backend/`)
Expected: `academic_questions` table created with no errors.

- [ ] **Step 3: Create the model**

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicQuestion extends Model
{
    use Auditable, SoftDeletes;

    public const TYPES = ['cq', 'mcq'];

    protected $fillable = [
        'school_class_id',
        'subject_id',
        'chapter_number',
        'chapter_name',
        'question_type',
        'question_text',
        'options',
        'marks',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
```

Deliberately NOT `TenantScoped` — this is a single global bank, not a per-school resource.

- [ ] **Step 4: Create the policy**

```php
<?php

namespace App\Policies;

use App\Models\AcademicQuestion;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicQuestionPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_questions.view');
    }

    public function view(Authenticatable $user, AcademicQuestion $academicQuestion): bool
    {
        return $user->hasPermission('academic_questions.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_questions.create');
    }

    public function update(Authenticatable $user, AcademicQuestion $academicQuestion): bool
    {
        return $user->hasPermission('academic_questions.update');
    }

    public function delete(Authenticatable $user, AcademicQuestion $academicQuestion): bool
    {
        return $user->hasPermission('academic_questions.delete');
    }
}
```

- [ ] **Step 5: Add permissions to `PermissionSeeder.php`**

Open `backend/database/seeders/PermissionSeeder.php` and find this existing block (the teacher-leave-applications permissions, near the end of the `$permissions` array):

```php
            ['name' => 'teacher_leave_applications.view', 'label' => 'View teacher leave applications'],
            ['name' => 'teacher_leave_applications.update', 'label' => 'Review teacher leave applications'],
```

Add this new block immediately after it:

```php

            // The national question bank — categorized by class, subject,
            // and chapter. Only national_admin ever gets create/update/
            // delete (see RolePermissionSeeder); teacher gets .view to
            // browse it when building a question paper. school_admin gets
            // neither — it never browses the raw bank, only prints
            // already-saved question papers (see question_papers.* below).
            ['name' => 'academic_questions.view', 'label' => 'View the academic question bank'],
            ['name' => 'academic_questions.create', 'label' => 'Create academic questions'],
            ['name' => 'academic_questions.update', 'label' => 'Update academic questions'],
            ['name' => 'academic_questions.delete', 'label' => 'Delete academic questions'],

            // A teacher's saved, built question paper. .create is teacher-
            // only; .update/.delete are also teacher-only but additionally
            // gated to the paper's own creator by QuestionPaperPolicy — a
            // colleague at the same school can view/print but never edit.
            // school_admin gets .view only (print-only, per the feature's
            // explicit requirement) and never .create/.update/.delete.
            ['name' => 'question_papers.view', 'label' => 'View question papers'],
            ['name' => 'question_papers.create', 'label' => 'Create question papers'],
            ['name' => 'question_papers.update', 'label' => 'Update a question paper (creator only)'],
            ['name' => 'question_papers.delete', 'label' => 'Delete a question paper (creator only)'],
```

(Both `academic_questions.*` and `question_papers.*` are added here together, even though `question_papers.*` isn't wired up until Task 2 — seeding an unused permission name is harmless, and this keeps the two logically-related blocks adjacent instead of splitting the file into two edits.)

- [ ] **Step 6: Add role grants to `RolePermissionSeeder.php`**

Open `backend/database/seeders/RolePermissionSeeder.php`. `national_admin` already gets every permission via its `$allPermissions` wildcard — no change needed there.

In the `'teacher' => [...]` array, find this block (the single line
`'questions.view',` also appears alone in the `'trainer'` array further
down the file — use this exact 3-line block, not the single line on its
own, to land on the right one):

```php
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'questions.view',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
```

Change it to:

```php
                'versions.view', 'class_versions.view', 'shifts.view', 'sections.view', 'subject_teacher_assignments.view',
                'questions.view', 'academic_questions.view',
                'exams.view', 'exams.create', 'exams.update', 'exam_attempts.view', 'student_progress.view',
```

In that same `'teacher' => [...]` array, find this block (the line
`'attendances.view', 'attendances.update',` also appears alone in the
`'school_admin'` array further up the file — use this exact 2-line block,
not the single line on its own, to land on the right one: teacher's
version is followed by a line with no `academic_exam_marks.publish`,
school_admin's is):

```php
                'attendances.view', 'attendances.update',
                'academic_exam_marks.view', 'academic_exam_marks.update',
```

Change it to:

```php
                'attendances.view', 'attendances.update',
                'question_papers.view', 'question_papers.create', 'question_papers.update', 'question_papers.delete',
                'academic_exam_marks.view', 'academic_exam_marks.update',
```

In the `'school_admin' => [...]` array, find this line:

```php
                'teacher_leave_applications.view', 'teacher_leave_applications.update',
```

Change it to:

```php
                'teacher_leave_applications.view', 'teacher_leave_applications.update',
                'question_papers.view',
```

- [ ] **Step 7: Create the Store and Update FormRequests**

`backend/app/Http/Requests/Api/V1/StoreAcademicQuestionRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicQuestion::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'chapter_number' => ['required', 'integer', 'min:1'],
            'chapter_name' => ['required', 'string', 'max:255'],
            'question_type' => ['required', Rule::in(AcademicQuestion::TYPES)],
            'question_text' => ['required', 'string'],
            'options' => ['nullable', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:500'],
            'marks' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * MCQ requires exactly four options; CQ must not carry any — enforced
     * here rather than as a declarative rule because both directions
     * depend on question_type's own value, not a fixed field.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('question_type');
            $hasOptions = $this->filled('options');

            if ($type === 'mcq' && ! $hasOptions) {
                $validator->errors()->add('options', 'MCQ questions require four options.');
            }

            if ($type === 'cq' && $hasOptions) {
                $validator->errors()->add('options', 'Options are only applicable to MCQ questions.');
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateAcademicQuestionRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('academic_question')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'chapter_number' => ['required', 'integer', 'min:1'],
            'chapter_name' => ['required', 'string', 'max:255'],
            'question_type' => ['required', Rule::in(AcademicQuestion::TYPES)],
            'question_text' => ['required', 'string'],
            'options' => ['nullable', 'array', 'size:4'],
            'options.*' => ['required', 'string', 'max:500'],
            'marks' => ['required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->input('question_type');
            $hasOptions = $this->filled('options');

            if ($type === 'mcq' && ! $hasOptions) {
                $validator->errors()->add('options', 'MCQ questions require four options.');
            }

            if ($type === 'cq' && $hasOptions) {
                $validator->errors()->add('options', 'Options are only applicable to MCQ questions.');
            }
        });
    }
}
```

- [ ] **Step 8: Create the Resource**

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AcademicQuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'school_class_id' => $this->school_class_id,
            'subject_id' => $this->subject_id,
            'chapter_number' => $this->chapter_number,
            'chapter_name' => $this->chapter_name,
            'question_type' => $this->question_type,
            'question_text' => $this->question_text,
            'options' => $this->options,
            'marks' => $this->marks,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass === null ? null : [
                'id' => $this->schoolClass->id,
                'name' => $this->schoolClass->name,
                'bn_name' => $this->schoolClass->bn_name,
            ]),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject === null ? null : [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
                'bn_name' => $this->subject->bn_name,
            ]),
        ];
    }
}
```

- [ ] **Step 9: Create the Controller**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicQuestionRequest;
use App\Http\Requests\Api\V1\UpdateAcademicQuestionRequest;
use App\Http\Resources\Api\V1\AcademicQuestionResource;
use App\Models\AcademicQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AcademicQuestionController extends Controller
{
    private const WITH = ['schoolClass', 'subject'];

    /**
     * The national question bank, filtered by the criteria a teacher uses
     * to find relevant questions for a paper. Only active questions are
     * returned — this is a global bank with no TenantScope, so
     * school_class_id/subject_id/chapter_number/question_type/marks are
     * the only narrowing available.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AcademicQuestion::class);

        $questions = AcademicQuestion::query()
            ->with(self::WITH)
            ->where('is_active', true)
            ->when($request->integer('school_class_id'), fn ($query, $id) => $query->where('school_class_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('chapter_number'), fn ($query, $number) => $query->where('chapter_number', $number))
            ->when($request->string('question_type')->toString(), fn ($query, $type) => $query->where('question_type', $type))
            ->when($request->integer('marks'), fn ($query, $marks) => $query->where('marks', $marks))
            ->orderBy('chapter_number')
            ->orderBy('id')
            ->get();

        return AcademicQuestionResource::collection($questions);
    }

    public function store(StoreAcademicQuestionRequest $request): JsonResponse
    {
        $question = AcademicQuestion::create([
            'school_class_id' => $request->input('school_class_id'),
            'subject_id' => $request->input('subject_id'),
            'chapter_number' => $request->input('chapter_number'),
            'chapter_name' => $request->input('chapter_name'),
            'question_type' => $request->input('question_type'),
            'question_text' => $request->input('question_text'),
            'options' => $request->input('options'),
            'marks' => $request->input('marks'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return (new AcademicQuestionResource($question->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAcademicQuestionRequest $request, AcademicQuestion $academic_question): AcademicQuestionResource
    {
        $academic_question->update([
            'school_class_id' => $request->input('school_class_id'),
            'subject_id' => $request->input('subject_id'),
            'chapter_number' => $request->input('chapter_number'),
            'chapter_name' => $request->input('chapter_name'),
            'question_type' => $request->input('question_type'),
            'question_text' => $request->input('question_text'),
            'options' => $request->input('options'),
            'marks' => $request->input('marks'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return new AcademicQuestionResource($academic_question->load(self::WITH));
    }

    public function destroy(AcademicQuestion $academic_question): JsonResponse
    {
        $this->authorize('delete', $academic_question);

        $academic_question->delete();

        return response()->json(null, 204);
    }
}
```

- [ ] **Step 10: Register routes**

In `backend/routes/api.php`, add the import alphabetically — find:

```php
use App\Http\Controllers\Api\V1\AcademicGradeConfigureController;
use App\Http\Controllers\Api\V1\AcademicSubjectMarkConfigureController;
```

and insert a new line between them:

```php
use App\Http\Controllers\Api\V1\AcademicGradeConfigureController;
use App\Http\Controllers\Api\V1\AcademicQuestionController;
use App\Http\Controllers\Api\V1\AcademicSubjectMarkConfigureController;
```

Then, inside the `Route::middleware('auth:sanctum')->group(...)` block, find the teacher-leave-applications route block (ends with):

```php
        Route::patch('/teacher-leave-applications/{teacher_leave_application}/review', [TeacherLeaveApplicationController::class, 'review']);
```

and add immediately after it:

```php

        Route::apiResource('academic-questions', AcademicQuestionController::class)->only(['index', 'store', 'update', 'destroy']);
```

- [ ] **Step 11: Write feature tests**

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicQuestion;
use App\Models\SchoolClass;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicQuestionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private SchoolClass $schoolClass;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schoolClass = SchoolClass::create(['name' => 'Class 6', 'bn_name' => 'ষষ্ঠ শ্রেণি', 'numeric_level' => 6]);
        $this->subject = Subject::create(['name' => 'Bangla', 'bn_name' => 'বাংলা', 'code' => 'BAN-101']);
    }

    /**
     * @return array<string, mixed>
     */
    private function cqPayload(): array
    {
        return [
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'chapter_number' => 1,
            'chapter_name' => 'Prose',
            'question_type' => 'cq',
            'question_text' => 'Read the passage and answer the following...',
            'marks' => 10,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mcqPayload(): array
    {
        return [
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'chapter_number' => 1,
            'chapter_name' => 'Prose',
            'question_type' => 'mcq',
            'question_text' => 'Which of the following is correct?',
            'options' => ['Option A', 'Option B', 'Option C', 'Option D'],
            'marks' => 1,
        ];
    }

    public function test_national_admin_can_create_a_cq_question(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $this->cqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'cq')
            ->assertJsonPath('data.options', null);

        $this->assertDatabaseHas('academic_questions', ['chapter_name' => 'Prose', 'question_type' => 'cq']);
    }

    public function test_national_admin_can_create_an_mcq_question_with_four_options(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $this->mcqPayload())
            ->assertCreated()
            ->assertJsonPath('data.question_type', 'mcq')
            ->assertJsonCount(4, 'data.options');
    }

    public function test_store_rejects_mcq_without_four_options(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $payload = $this->mcqPayload();
        unset($payload['options']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');
    }

    public function test_store_rejects_cq_with_options_present(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $payload = $this->cqPayload();
        $payload['options'] = ['A', 'B', 'C', 'D'];

        $this->actingAs($nationalAdmin, 'sanctum')
            ->postJson('/api/v1/academic-questions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('options');
    }

    public function test_store_is_forbidden_without_the_create_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/academic-questions', $this->cqPayload())
            ->assertForbidden();
    }

    public function test_teacher_can_view_the_bank_filtered_by_criteria(): void
    {
        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->assertCreated();

        $otherClass = SchoolClass::create(['name' => 'Class 7', 'numeric_level' => 7]);
        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', array_merge(
            $this->cqPayload(),
            ['school_class_id' => $otherClass->id],
        ))->assertCreated();

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/academic-questions?school_class_id={$this->schoolClass->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/academic-questions')
            ->assertForbidden();
    }

    public function test_school_admin_cannot_view_the_bank(): void
    {
        // school_admin never gets academic_questions.view (see
        // RolePermissionSeeder) — it only prints already-saved question
        // papers, never browses the raw bank.
        $schoolAdmin = $this->actorWithPermissions([]);

        $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/academic-questions')
            ->assertForbidden();
    }

    public function test_national_admin_can_update_a_question(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.update']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');

        $payload = $this->cqPayload();
        $payload['marks'] = 15;

        $this->actingAs($nationalAdmin, 'sanctum')
            ->putJson("/api/v1/academic-questions/{$id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.marks', 15);
    }

    public function test_national_admin_can_delete_a_question(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.delete']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');

        $this->actingAs($nationalAdmin, 'sanctum')
            ->deleteJson("/api/v1/academic-questions/{$id}")
            ->assertNoContent();

        $this->assertSoftDeleted('academic_questions', ['id' => $id]);
    }

    public function test_delete_is_forbidden_without_the_delete_permission(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');

        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/academic-questions/{$id}")
            ->assertForbidden();
    }

    public function test_soft_deleted_questions_are_excluded_from_the_index(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create', 'academic_questions.delete']);
        $id = $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $this->cqPayload())->json('data.id');
        $this->actingAs($nationalAdmin, 'sanctum')->deleteJson("/api/v1/academic-questions/{$id}")->assertNoContent();

        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);
        $response = $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/academic-questions')->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    public function test_inactive_questions_are_excluded_from_the_index(): void
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);
        $payload = $this->cqPayload();
        $payload['is_active'] = false;
        $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', $payload)->assertCreated();

        $teacher = $this->teacherActorWithPermissions(['academic_questions.view']);
        $response = $this->actingAs($teacher, 'sanctum')->getJson('/api/v1/academic-questions')->assertOk();

        $this->assertSame([], $response->json('data'));
    }
}
```

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicQuestionPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * academic_questions.create/update/delete must map to exactly
     * national_admin — no other role may write to the national question
     * bank. .view additionally maps to teacher (to browse the bank when
     * building a paper), but never school_admin (it only prints
     * already-saved papers — see question_papers.* instead).
     */
    public function test_academic_question_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = [
            'academic_questions.view' => ['national_admin', 'teacher'],
            'academic_questions.create' => ['national_admin'],
            'academic_questions.update' => ['national_admin'],
            'academic_questions.delete' => ['national_admin'],
        ];

        foreach ($expected as $permissionName => $expectedRoles) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            sort($expectedRoles);

            $this->assertSame($expectedRoles, $roles, "Expected {$permissionName} to be mapped to exactly: ".implode(', ', $expectedRoles));
        }
    }
}
```

- [ ] **Step 12: Run the tests**

Run: `php artisan test --filter=AcademicQuestion` (from `backend/`)
Expected: all new tests PASS.

- [ ] **Step 13: Commit**

```bash
git add database/migrations/2026_11_26_090000_create_academic_questions_table.php app/Models/AcademicQuestion.php app/Policies/AcademicQuestionPolicy.php app/Http/Requests/Api/V1/StoreAcademicQuestionRequest.php app/Http/Requests/Api/V1/UpdateAcademicQuestionRequest.php app/Http/Resources/Api/V1/AcademicQuestionResource.php app/Http/Controllers/Api/V1/AcademicQuestionController.php database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php routes/api.php tests/Feature/AcademicQuestionControllerTest.php tests/Feature/AcademicQuestionPermissionMappingTest.php
git commit -m "Add the national academic question bank (academic_questions)"
```

---

### Task 2: Question Papers — the teacher's saved, built paper (backend)

**Files:**
- Create: `backend/database/migrations/2026_11_26_090100_create_question_papers_table.php`
- Create: `backend/database/migrations/2026_11_26_090200_create_question_paper_items_table.php`
- Create: `backend/app/Models/QuestionPaper.php`
- Create: `backend/app/Models/QuestionPaperItem.php`
- Create: `backend/app/Policies/QuestionPaperPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreQuestionPaperRequest.php`
- Create: `backend/app/Http/Requests/Api/V1/UpdateQuestionPaperRequest.php`
- Create: `backend/app/Http/Resources/Api/V1/QuestionPaperResource.php`
- Create: `backend/app/Http/Resources/Api/V1/QuestionPaperItemResource.php`
- Create: `backend/app/Http/Controllers/Api/V1/QuestionPaperController.php`
- Modify: `backend/routes/api.php`
- Create: `backend/tests/Feature/QuestionPaperControllerTest.php`
- Create: `backend/tests/Feature/QuestionPaperPermissionMappingTest.php`

**Interfaces:**
- Consumes: `App\Models\AcademicQuestion` (Task 1) — `question_paper_items.academic_question_id` references it; `App\Models\Concerns\{Auditable,TenantScoped}`; `App\Models\TeacherUser`, `App\Models\School`, `App\Models\District`, `App\Models\SchoolClass`, `App\Models\Subject`; `Tests\Concerns\CreatesAuthorizedActors`. Permissions `academic_questions.*`/`question_papers.*` are already seeded by Task 1's `PermissionSeeder`/`RolePermissionSeeder` edits.
- Produces: Routes `question-papers` (`index`/`show`/`store`/`update`/`destroy`) at `/api/v1/question-papers`. `QuestionPaperResource`'s `can_edit`/`can_delete` booleans and `QuestionPaperItemResource`'s nested `academic_question` shape are what Task 3's frontend `QuestionPaper`/`QuestionPaperItem` TypeScript types must mirror exactly.

- [ ] **Step 1: Create the `question_papers` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A teacher's saved, built question paper. school_id/district_id are
     * denormalized from the creating teacher at submission time (never
     * taken from the request), same convention as every other
     * TenantScoped table — TenantScope's existing actor-school_id scoping
     * already produces exactly the desired visibility (same-school
     * teachers and that school's admins see it; national_admin sees
     * everything) with no custom scope needed.
     */
    public function up(): void
    {
        Schema::create('question_papers', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('teacher_id')->constrained('teacher_users')->cascadeOnDelete();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();

            $table->unsignedInteger('time_duration_minutes')->nullable();
            $table->unsignedInteger('full_marks')->nullable();
            $table->text('instructions')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_papers');
    }
};
```

- [ ] **Step 2: Create the `question_paper_items` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ordered, drag-and-drop content of a question paper — either a
     * reference to a bank question or an inserted section heading. No
     * Auditable/SoftDeletes: items are fully replaced as a set on every
     * paper update (see QuestionPaperController::update()), so per-row
     * history isn't meaningful.
     */
    public function up(): void
    {
        Schema::create('question_paper_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('question_paper_id')->constrained()->cascadeOnDelete();
            // 'question' | 'heading' — validated at the application layer.
            $table->string('item_type');
            $table->foreignId('academic_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('heading_text')->nullable();
            $table->unsignedInteger('order_index');

            $table->timestamps();

            $table->index(['question_paper_id', 'order_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_paper_items');
    }
};
```

- [ ] **Step 3: Run the migrations**

Run: `php artisan migrate` (from `backend/`)
Expected: both tables created with no errors.

- [ ] **Step 4: Create the models**

`backend/app/Models/QuestionPaper.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuestionPaper extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'teacher_id',
        'school_id',
        'district_id',
        'title',
        'school_class_id',
        'subject_id',
        'time_duration_minutes',
        'full_marks',
        'instructions',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'teacher_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'school_class_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuestionPaperItem::class)->orderBy('order_index');
    }
}
```

`backend/app/Models/QuestionPaperItem.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionPaperItem extends Model
{
    public const TYPES = ['question', 'heading'];

    protected $fillable = [
        'question_paper_id',
        'item_type',
        'academic_question_id',
        'heading_text',
        'order_index',
    ];

    public function questionPaper(): BelongsTo
    {
        return $this->belongsTo(QuestionPaper::class);
    }

    public function academicQuestion(): BelongsTo
    {
        return $this->belongsTo(AcademicQuestion::class);
    }
}
```

- [ ] **Step 5: Create the policy**

```php
<?php

namespace App\Policies;

use App\Models\QuestionPaper;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class QuestionPaperPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('question_papers.view');
    }

    /**
     * No per-row ownership check here — TenantScope already narrows
     * which rows a teacher or school_admin can even reach to their own
     * school, and within a school every teacher may view/print any
     * colleague's paper, not only their own.
     */
    public function view(Authenticatable $user, QuestionPaper $questionPaper): bool
    {
        return $user->hasPermission('question_papers.view');
    }

    public function create(Authenticatable $user): bool
    {
        return $user instanceof TeacherUser
            && $user->school_id !== null
            && $user->hasPermission('question_papers.create');
    }

    /**
     * Unlike view(), update/delete ARE restricted to the paper's own
     * creator — a colleague can view/print but never edit, per the
     * feature's explicit requirement.
     */
    public function update(Authenticatable $user, QuestionPaper $questionPaper): bool
    {
        return $user instanceof TeacherUser
            && $questionPaper->teacher_id === $user->id
            && $user->hasPermission('question_papers.update');
    }

    public function delete(Authenticatable $user, QuestionPaper $questionPaper): bool
    {
        return $user instanceof TeacherUser
            && $questionPaper->teacher_id === $user->id
            && $user->hasPermission('question_papers.delete');
    }
}
```

- [ ] **Step 6: Create the Store and Update FormRequests**

`backend/app/Http/Requests/Api/V1/StoreQuestionPaperRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\QuestionPaper;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuestionPaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', QuestionPaper::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'time_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'full_marks' => ['nullable', 'integer', 'min:1'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['question', 'heading'])],
            'items.*.academic_question_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_questions', 'id')->where(fn ($query) => $query->where('is_active', true))->withoutTrashed(),
            ],
            'items.*.heading_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Each item must carry exactly the field its own type needs — the
     * declarative rules above can't express "required only when the SAME
     * array index's type is X", so that cross-field check runs here
     * instead.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $type = is_array($item) ? ($item['type'] ?? null) : null;

                if ($type === 'question' && empty($item['academic_question_id'])) {
                    $validator->errors()->add("items.$index.academic_question_id", 'A question item requires academic_question_id.');
                }

                if ($type === 'heading' && empty($item['heading_text'])) {
                    $validator->errors()->add("items.$index.heading_text", 'A heading item requires heading_text.');
                }
            }
        });
    }
}
```

`backend/app/Http/Requests/Api/V1/UpdateQuestionPaperRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateQuestionPaperRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('question_paper')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'school_class_id' => ['required', 'integer', Rule::exists('school_classes', 'id')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')],
            'time_duration_minutes' => ['nullable', 'integer', 'min:1'],
            'full_marks' => ['nullable', 'integer', 'min:1'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.type' => ['required', Rule::in(['question', 'heading'])],
            'items.*.academic_question_id' => [
                'nullable',
                'integer',
                Rule::exists('academic_questions', 'id')->where(fn ($query) => $query->where('is_active', true))->withoutTrashed(),
            ],
            'items.*.heading_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                $type = is_array($item) ? ($item['type'] ?? null) : null;

                if ($type === 'question' && empty($item['academic_question_id'])) {
                    $validator->errors()->add("items.$index.academic_question_id", 'A question item requires academic_question_id.');
                }

                if ($type === 'heading' && empty($item['heading_text'])) {
                    $validator->errors()->add("items.$index.heading_text", 'A heading item requires heading_text.');
                }
            }
        });
    }
}
```

- [ ] **Step 7: Create the Resources**

`backend/app/Http/Resources/Api/V1/QuestionPaperItemResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionPaperItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_type' => $this->item_type,
            'order_index' => $this->order_index,
            'heading_text' => $this->heading_text,
            'academic_question' => $this->whenLoaded('academicQuestion', fn () => $this->academicQuestion === null ? null : [
                'id' => $this->academicQuestion->id,
                'question_type' => $this->academicQuestion->question_type,
                'question_text' => $this->academicQuestion->question_text,
                'options' => $this->academicQuestion->options,
                'marks' => $this->academicQuestion->marks,
                'chapter_number' => $this->academicQuestion->chapter_number,
                'chapter_name' => $this->academicQuestion->chapter_name,
            ]),
        ];
    }
}
```

`backend/app/Http/Resources/Api/V1/QuestionPaperResource.php`:

```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionPaperResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'teacher_id' => $this->teacher_id,
            'school_id' => $this->school_id,
            'district_id' => $this->district_id,
            'title' => $this->title,
            'school_class_id' => $this->school_class_id,
            'subject_id' => $this->subject_id,
            'time_duration_minutes' => $this->time_duration_minutes,
            'full_marks' => $this->full_marks,
            'instructions' => $this->instructions,
            'created_at' => $this->created_at?->toIso8601String(),
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null : [
                'id' => $this->teacher->id,
                'name' => $this->teacher->name,
            ]),
            'school_class' => $this->whenLoaded('schoolClass', fn () => $this->schoolClass === null ? null : [
                'id' => $this->schoolClass->id,
                'name' => $this->schoolClass->name,
                'bn_name' => $this->schoolClass->bn_name,
            ]),
            'subject' => $this->whenLoaded('subject', fn () => $this->subject === null ? null : [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
                'bn_name' => $this->subject->bn_name,
            ]),
            'items' => QuestionPaperItemResource::collection($this->whenLoaded('items')),
            // Lets the frontend show Edit/Delete only where update()/
            // delete() would actually allow it — the client has no way to
            // re-derive the creator-only ownership check itself.
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
            'can_delete' => $request->user()?->can('delete', $this->resource) ?? false,
        ];
    }
}
```

- [ ] **Step 8: Create the Controller**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreQuestionPaperRequest;
use App\Http\Requests\Api\V1\UpdateQuestionPaperRequest;
use App\Http\Resources\Api\V1\QuestionPaperResource;
use App\Models\QuestionPaper;
use App\Models\QuestionPaperItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class QuestionPaperController extends Controller
{
    private const WITH = ['teacher', 'schoolClass', 'subject', 'items.academicQuestion'];

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', QuestionPaper::class);

        $papers = QuestionPaper::query()
            ->with(self::WITH)
            ->orderByDesc('created_at')
            ->get();

        return QuestionPaperResource::collection($papers);
    }

    public function show(QuestionPaper $question_paper): QuestionPaperResource
    {
        $this->authorize('view', $question_paper);

        return new QuestionPaperResource($question_paper->load(self::WITH));
    }

    /**
     * Creates the paper and its ordered items in one transaction — a
     * paper with no items (or items that failed to insert) would be a
     * broken, half-saved builder result.
     */
    public function store(StoreQuestionPaperRequest $request): JsonResponse
    {
        $teacher = $request->user();

        $paper = DB::transaction(function () use ($request, $teacher) {
            $paper = QuestionPaper::create([
                'teacher_id' => $teacher->id,
                'school_id' => $teacher->school_id,
                'district_id' => $teacher->district_id,
                'title' => $request->input('title'),
                'school_class_id' => $request->input('school_class_id'),
                'subject_id' => $request->input('subject_id'),
                'time_duration_minutes' => $request->input('time_duration_minutes'),
                'full_marks' => $request->input('full_marks'),
                'instructions' => $request->input('instructions'),
            ]);

            $this->replaceItems($paper, $request->input('items'));

            return $paper;
        });

        return (new QuestionPaperResource($paper->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Full replace semantics: the entire item set is deleted and
     * recreated from the payload — the simplest correct way to persist a
     * drag-and-drop canvas, which has no stable notion of "patch just
     * this one item" from the client's perspective.
     */
    public function update(UpdateQuestionPaperRequest $request, QuestionPaper $question_paper): QuestionPaperResource
    {
        DB::transaction(function () use ($request, $question_paper) {
            $question_paper->update([
                'title' => $request->input('title'),
                'school_class_id' => $request->input('school_class_id'),
                'subject_id' => $request->input('subject_id'),
                'time_duration_minutes' => $request->input('time_duration_minutes'),
                'full_marks' => $request->input('full_marks'),
                'instructions' => $request->input('instructions'),
            ]);

            $question_paper->items()->delete();
            $this->replaceItems($question_paper, $request->input('items'));
        });

        return new QuestionPaperResource($question_paper->load(self::WITH));
    }

    public function destroy(QuestionPaper $question_paper): JsonResponse
    {
        $this->authorize('delete', $question_paper);

        $question_paper->delete();

        return response()->json(null, 204);
    }

    /**
     * @param  array<int, array{type: string, academic_question_id?: int|null, heading_text?: string|null}>  $items
     */
    private function replaceItems(QuestionPaper $paper, array $items): void
    {
        foreach (array_values($items) as $index => $item) {
            QuestionPaperItem::create([
                'question_paper_id' => $paper->id,
                'item_type' => $item['type'],
                'academic_question_id' => $item['type'] === 'question' ? $item['academic_question_id'] : null,
                'heading_text' => $item['type'] === 'heading' ? $item['heading_text'] : null,
                'order_index' => $index,
            ]);
        }
    }
}
```

- [ ] **Step 9: Register routes**

In `backend/routes/api.php`, add the import alphabetically — find:

```php
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\RoleController;
```

and insert a new line between them:

```php
use App\Http\Controllers\Api\V1\QuestionController;
use App\Http\Controllers\Api\V1\QuestionPaperController;
use App\Http\Controllers\Api\V1\RoleController;
```

Then, inside the `Route::middleware('auth:sanctum')->group(...)` block, find the line added by Task 1:

```php
        Route::apiResource('academic-questions', AcademicQuestionController::class)->only(['index', 'store', 'update', 'destroy']);
```

and add immediately after it:

```php
        Route::apiResource('question-papers', QuestionPaperController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
```

- [ ] **Step 10: Write feature tests**

```php
<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class QuestionPaperControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private SchoolClass $schoolClass;

    private Subject $subject;

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

        $this->schoolClass = SchoolClass::create(['name' => 'Class 6', 'numeric_level' => 6]);
        $this->subject = Subject::create(['name' => 'Bangla', 'code' => 'BAN-101']);
    }

    private function createQuestion(array $overrides = []): int
    {
        $nationalAdmin = $this->actorWithPermissions(['academic_questions.create']);

        return $this->actingAs($nationalAdmin, 'sanctum')->postJson('/api/v1/academic-questions', array_merge([
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'chapter_number' => 1,
            'chapter_name' => 'Prose',
            'question_type' => 'cq',
            'question_text' => 'Read the passage...',
            'marks' => 10,
        ], $overrides))->json('data.id');
    }

    /**
     * @param  array<int, array<string, mixed>>  $itemOverrides
     * @return array<string, mixed>
     */
    private function paperPayload(array $itemOverrides = []): array
    {
        return [
            'title' => 'Mid-term Bangla Paper',
            'school_class_id' => $this->schoolClass->id,
            'subject_id' => $this->subject->id,
            'time_duration_minutes' => 150,
            'full_marks' => 70,
            'instructions' => 'Answer all questions.',
            'items' => $itemOverrides ?: [
                ['type' => 'heading', 'heading_text' => 'Prose'],
                ['type' => 'question', 'academic_question_id' => $this->createQuestion()],
            ],
        ];
    }

    public function test_teacher_can_create_a_question_paper_scoped_to_their_own_school(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload())
            ->assertCreated();

        $response->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.item_type', 'heading')
            ->assertJsonPath('data.items.1.item_type', 'question');

        $this->assertDatabaseHas('question_papers', ['teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id]);
    }

    public function test_store_rejects_a_teacher_with_no_school_assigned(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], null, null);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload())
            ->assertForbidden();
    }

    public function test_store_is_forbidden_without_the_create_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $this->paperPayload())
            ->assertForbidden();
    }

    public function test_store_rejects_a_question_item_missing_academic_question_id(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $payload = $this->paperPayload([
            ['type' => 'question'],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.academic_question_id');
    }

    public function test_store_rejects_a_heading_item_missing_heading_text(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $payload = $this->paperPayload([
            ['type' => 'heading'],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.heading_text');
    }

    public function test_store_rejects_an_inactive_academic_question(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $inactiveQuestionId = $this->createQuestion(['is_active' => false]);

        $payload = $this->paperPayload([
            ['type' => 'question', 'academic_question_id' => $inactiveQuestionId],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/v1/question-papers', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.academic_question_id');
    }

    public function test_a_colleague_at_the_same_school_can_view_but_not_edit_the_paper(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->json('data.id');

        $colleague = $this->teacherActorWithPermissions(['question_papers.view', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($colleague, 'sanctum')
            ->getJson("/api/v1/question-papers/{$paperId}")
            ->assertOk()
            ->assertJsonPath('data.can_edit', false)
            ->assertJsonPath('data.can_delete', false);

        $this->actingAs($colleague, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $this->paperPayload())
            ->assertForbidden();
    }

    public function test_creator_can_update_their_own_paper_replacing_its_items(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.update'], $this->schoolA->id, $this->schoolA->district_id);
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->json('data.id');

        $newQuestionId = $this->createQuestion(['chapter_name' => 'Poetry']);
        $payload = $this->paperPayload([
            ['type' => 'question', 'academic_question_id' => $newQuestionId],
        ]);

        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/v1/question-papers/{$paperId}", $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.academic_question.id', $newQuestionId);

        $this->assertDatabaseCount('question_paper_items', 1);
    }

    public function test_creator_can_delete_their_own_paper(): void
    {
        $teacher = $this->teacherActorWithPermissions(['question_papers.create', 'question_papers.delete'], $this->schoolA->id, $this->schoolA->district_id);
        $paperId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->json('data.id');

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/question-papers/{$paperId}")
            ->assertNoContent();

        $this->assertSoftDeleted('question_papers', ['id' => $paperId]);
    }

    public function test_school_admin_can_view_every_teachers_paper_at_their_school_but_not_edit_or_delete(): void
    {
        $teacherA = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->assertCreated();
        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->assertCreated();

        $schoolAdmin = $this->actorWithPermissions(['question_papers.view'], $this->schoolA->id, $this->schoolA->district_id);

        $response = $this->actingAs($schoolAdmin, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $firstId = $response->json('data.0.id');

        $this->actingAs($schoolAdmin, 'sanctum')
            ->deleteJson("/api/v1/question-papers/{$firstId}")
            ->assertForbidden();
    }

    public function test_school_admin_sees_only_their_own_schools_papers(): void
    {
        $teacherA = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->assertCreated();
        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->assertCreated();

        $schoolAdminA = $this->actorWithPermissions(['question_papers.view'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($schoolAdminA, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_national_admin_sees_every_schools_papers(): void
    {
        $teacherA = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolA->id, $this->schoolA->district_id);
        $teacherB = $this->teacherActorWithPermissions(['question_papers.create'], $this->schoolB->id, $this->schoolB->district_id);

        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->assertCreated();
        $this->actingAs($teacherB, 'sanctum')->postJson('/api/v1/question-papers', $this->paperPayload())->assertCreated();

        $nationalAdmin = $this->actorWithPermissions(['question_papers.view']);

        $this->actingAs($nationalAdmin, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_is_forbidden_without_the_view_permission(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/question-papers')
            ->assertForbidden();
    }
}
```

```php
<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionPaperPermissionMappingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * question_papers.view maps to national_admin, school_admin, and
     * teacher — school_admin print-only (gated to view by the product
     * decision, never update/delete), teacher additionally holds create/
     * update/delete (update/delete further narrowed to the paper's own
     * creator by QuestionPaperPolicy, not expressible at the role level).
     */
    public function test_question_paper_permissions_are_mapped_to_exactly_the_right_roles(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expected = [
            'question_papers.view' => ['national_admin', 'school_admin', 'teacher'],
            'question_papers.create' => ['national_admin', 'teacher'],
            'question_papers.update' => ['national_admin', 'teacher'],
            'question_papers.delete' => ['national_admin', 'teacher'],
        ];

        foreach ($expected as $permissionName => $expectedRoles) {
            $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', $permissionName))
                ->pluck('name')->sort()->values()->all();

            sort($expectedRoles);

            $this->assertSame($expectedRoles, $roles, "Expected {$permissionName} to be mapped to exactly: ".implode(', ', $expectedRoles));
        }
    }
}
```

- [ ] **Step 11: Run the tests**

Run: `php artisan test --filter=QuestionPaper` (from `backend/`)
Expected: all new tests PASS. Also run the full suite once (`php artisan test`) to confirm Task 1's tests still pass unaffected.

- [ ] **Step 12: Commit**

```bash
git add database/migrations/2026_11_26_090100_create_question_papers_table.php database/migrations/2026_11_26_090200_create_question_paper_items_table.php app/Models/QuestionPaper.php app/Models/QuestionPaperItem.php app/Policies/QuestionPaperPolicy.php app/Http/Requests/Api/V1/StoreQuestionPaperRequest.php app/Http/Requests/Api/V1/UpdateQuestionPaperRequest.php app/Http/Resources/Api/V1/QuestionPaperResource.php app/Http/Resources/Api/V1/QuestionPaperItemResource.php app/Http/Controllers/Api/V1/QuestionPaperController.php routes/api.php tests/Feature/QuestionPaperControllerTest.php tests/Feature/QuestionPaperPermissionMappingTest.php
git commit -m "Add question papers: teacher-built, drag-and-drop question paper storage"
```

---

### Task 3: Frontend foundations — api-client types/functions + i18n keys

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: the exact JSON shapes `AcademicQuestionResource`/`QuestionPaperResource`/`QuestionPaperItemResource` produce (Tasks 1-2) — field names here must match those PHP resources exactly (`school_class_id`, `can_edit`, `can_delete`, nested `academic_question`, etc).
- Produces: `AcademicQuestion`, `AcademicQuestionPayload`, `AcademicQuestionType`, `listAcademicQuestions`, `createAcademicQuestion`, `updateAcademicQuestion`, `deleteAcademicQuestion`; `QuestionPaper`, `QuestionPaperPayload`, `QuestionPaperItem`, `QuestionPaperItemInput`, `QuestionPaperItemType`, `listQuestionPapers`, `createQuestionPaper`, `getQuestionPaper`, `updateQuestionPaper`, `deleteQuestionPaper` — every later frontend task imports these from `@ttla/api-client`. Every i18n key listed below is consumed by name in Tasks 4-7's JSX (`t['...']`) — a typo here surfaces as a broken string in those tasks, not a build failure, so get the exact key spelling right.

- [ ] **Step 1: Add API client types and functions**

Append to the end of `frontend/packages/api-client/src/index.ts` (after the existing `reviewTeacherLeaveApplication` function):

```ts

// ---------- Academic questions (the national question bank) ----------

export type AcademicQuestionType = 'cq' | 'mcq'

export interface AcademicQuestion {
  id: number
  school_class_id: number
  subject_id: number
  chapter_number: number
  chapter_name: string
  question_type: AcademicQuestionType
  question_text: string
  options: string[] | null
  marks: number
  is_active: boolean
  created_at: string
  school_class?: { id: number; name: string; bn_name: string | null } | null
  subject?: { id: number; name: string; bn_name: string | null } | null
}

export interface AcademicQuestionPayload {
  school_class_id: number
  subject_id: number
  chapter_number: number
  chapter_name: string
  question_type: AcademicQuestionType
  question_text: string
  options?: string[] | null
  marks: number
  is_active?: boolean
}

// The national bank, filtered by the criteria a teacher uses to find
// relevant questions for a paper — unpaginated, same shape as
// listChapters()/listTeacherLeaveApplications() (a flat, small-enough
// result set given the filters already narrow it).
export function listAcademicQuestions(
  params: {
    schoolClassId?: number
    subjectId?: number
    chapterNumber?: number
    questionType?: AcademicQuestionType
    marks?: number
  } = {},
  token?: string,
): Promise<{ data: AcademicQuestion[] }> {
  const query = buildQuery({
    school_class_id: params.schoolClassId,
    subject_id: params.subjectId,
    chapter_number: params.chapterNumber,
    question_type: params.questionType,
    marks: params.marks,
  })

  return apiFetch<{ data: AcademicQuestion[] }>(`/academic-questions${query}`, {
    headers: token ? { Authorization: `Bearer ${token}` } : undefined,
  })
}

export function createAcademicQuestion(
  payload: AcademicQuestionPayload,
  token: string,
): Promise<{ data: AcademicQuestion }> {
  return apiFetch<{ data: AcademicQuestion }>('/academic-questions', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

export function updateAcademicQuestion(
  id: number,
  payload: AcademicQuestionPayload,
  token: string,
): Promise<{ data: AcademicQuestion }> {
  return apiFetch<{ data: AcademicQuestion }>(`/academic-questions/${id}`, {
    method: 'PUT',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

export function deleteAcademicQuestion(id: number, token: string): Promise<void> {
  return apiFetch<void>(`/academic-questions/${id}`, {
    method: 'DELETE',
    headers: authHeaders(token),
  })
}

// ---------- Question papers (a teacher's saved, built paper) ----------

export type QuestionPaperItemType = 'question' | 'heading'

export interface QuestionPaperItem {
  id: number
  item_type: QuestionPaperItemType
  order_index: number
  heading_text: string | null
  academic_question?: {
    id: number
    question_type: AcademicQuestionType
    question_text: string
    options: string[] | null
    marks: number
    chapter_number: number
    chapter_name: string
  } | null
}

// The shape sent to create/update — a plain input, not the richer
// QuestionPaperItem the server returns (no id/order_index needed; order
// is implied by array position).
export interface QuestionPaperItemInput {
  type: QuestionPaperItemType
  academic_question_id?: number | null
  heading_text?: string | null
}

export interface QuestionPaper {
  id: number
  teacher_id: number
  school_id: number
  district_id: number | null
  title: string
  school_class_id: number
  subject_id: number
  time_duration_minutes: number | null
  full_marks: number | null
  instructions: string | null
  created_at: string
  teacher?: { id: number; name: string } | null
  school_class?: { id: number; name: string; bn_name: string | null } | null
  subject?: { id: number; name: string; bn_name: string | null } | null
  items?: QuestionPaperItem[]
  // Set server-side from QuestionPaperPolicy — true only for the
  // paper's own creator, never derived client-side.
  can_edit: boolean
  can_delete: boolean
}

export interface QuestionPaperPayload {
  title: string
  school_class_id: number
  subject_id: number
  time_duration_minutes?: number | null
  full_marks?: number | null
  instructions?: string | null
  items: QuestionPaperItemInput[]
}

// TenantScope-filtered on the backend: a teacher sees their own school's
// papers (own + colleagues'), school_admin sees their own school's
// papers, national_admin sees every school's.
export function listQuestionPapers(token: string): Promise<{ data: QuestionPaper[] }> {
  return apiFetch<{ data: QuestionPaper[] }>('/question-papers', {
    headers: authHeaders(token),
  })
}

export function createQuestionPaper(
  payload: QuestionPaperPayload,
  token: string,
): Promise<{ data: QuestionPaper }> {
  return apiFetch<{ data: QuestionPaper }>('/question-papers', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

export function getQuestionPaper(id: number, token: string): Promise<{ data: QuestionPaper }> {
  return apiFetch<{ data: QuestionPaper }>(`/question-papers/${id}`, {
    headers: authHeaders(token),
  })
}

// Full replace semantics — payload.items always replaces the paper's
// entire item set server-side (see QuestionPaperController::update()).
export function updateQuestionPaper(
  id: number,
  payload: QuestionPaperPayload,
  token: string,
): Promise<{ data: QuestionPaper }> {
  return apiFetch<{ data: QuestionPaper }>(`/question-papers/${id}`, {
    method: 'PUT',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

export function deleteQuestionPaper(id: number, token: string): Promise<void> {
  return apiFetch<void>(`/question-papers/${id}`, {
    method: 'DELETE',
    headers: authHeaders(token),
  })
}
```

- [ ] **Step 2: Add English i18n keys**

Add the following keys to `frontend/packages/i18n/src/common/en.json` (anywhere in the flat key list — alongside the other `management.nav.*`/`teacher.nav.*` keys is the existing convention, but position doesn't affect behavior):

```json
  "management.nav.questionBank": "Question Bank",
  "management.nav.questionPapers": "Question Papers",
  "management.questionBank.title": "Question Bank",
  "management.questionBank.colClass": "Class",
  "management.questionBank.colSubject": "Subject",
  "management.questionBank.colChapter": "Chapter",
  "management.questionBank.colType": "Type",
  "management.questionBank.colMarks": "Marks",
  "management.questionBank.colActive": "Active",
  "management.questionBank.typeCq": "CQ",
  "management.questionBank.typeMcq": "MCQ",
  "management.questionBank.chapterNumber": "Chapter number",
  "management.questionBank.chapterName": "Chapter name",
  "management.questionBank.questionText": "Question text",
  "management.questionBank.option1": "Option 1",
  "management.questionBank.option2": "Option 2",
  "management.questionBank.option3": "Option 3",
  "management.questionBank.option4": "Option 4",
  "management.questionBank.marks": "Marks",
  "management.questionBank.active": "Active",
  "management.questionBank.allTypes": "All types",
  "management.questionPapers.title": "Question Papers",
  "management.questionPapers.colTitle": "Title",
  "management.questionPapers.colTeacher": "Teacher",
  "management.questionPapers.colClass": "Class",
  "management.questionPapers.colSubject": "Subject",
  "management.questionPapers.colCreated": "Created",
  "management.questionPapers.print": "Print",
  "management.questionPapers.empty": "No question papers found.",
  "management.questionPapers.loading": "Loading...",
  "teacher.nav.questionBuilder": "Question Builder",
  "teacher.nav.myQuestionPapers": "My Question Papers",
  "teacher.questionBuilder.title": "Question Builder",
  "teacher.questionBuilder.selectClass": "Select class",
  "teacher.questionBuilder.selectSubject": "Select subject",
  "teacher.questionBuilder.chapterNumber": "Chapter number",
  "teacher.questionBuilder.selectType": "Select type",
  "teacher.questionBuilder.allTypes": "All types",
  "teacher.questionBuilder.cqLabel": "CQ",
  "teacher.questionBuilder.mcqLabel": "MCQ",
  "teacher.questionBuilder.marksFilter": "Marks",
  "teacher.questionBuilder.loadQuestions": "Load questions",
  "teacher.questionBuilder.bankTitle": "Available questions",
  "teacher.questionBuilder.paperTitle": "Paper layout",
  "teacher.questionBuilder.titleLabel": "Paper title",
  "teacher.questionBuilder.timeDuration": "Time duration (minutes)",
  "teacher.questionBuilder.fullMarks": "Full marks",
  "teacher.questionBuilder.instructions": "Instructions",
  "teacher.questionBuilder.insertHeading": "Insert heading",
  "teacher.questionBuilder.headingPlaceholder": "Heading text",
  "teacher.questionBuilder.addHeading": "Add",
  "teacher.questionBuilder.cancelHeading": "Cancel",
  "teacher.questionBuilder.removeItem": "Remove",
  "teacher.questionBuilder.dragHint": "Drag questions here to build your paper",
  "teacher.questionBuilder.save": "Save paper",
  "teacher.questionBuilder.saving": "Saving...",
  "teacher.questionBuilder.saveSuccess": "Question paper saved.",
  "teacher.questionBuilder.saveError": "Could not save the question paper. Please try again.",
  "teacher.questionBuilder.empty": "No questions match these filters.",
  "teacher.questionBuilder.loading": "Loading...",
  "teacher.myQuestionPapers.title": "My Question Papers",
  "teacher.myQuestionPapers.createNew": "New question paper",
  "teacher.myQuestionPapers.colTitle": "Title",
  "teacher.myQuestionPapers.colClass": "Class",
  "teacher.myQuestionPapers.colSubject": "Subject",
  "teacher.myQuestionPapers.colCreated": "Created",
  "teacher.myQuestionPapers.colActions": "Actions",
  "teacher.myQuestionPapers.print": "Print",
  "teacher.myQuestionPapers.edit": "Edit",
  "teacher.myQuestionPapers.delete": "Delete",
  "teacher.myQuestionPapers.deleteConfirm": "Delete this question paper?",
  "teacher.myQuestionPapers.deleteSuccess": "Question paper deleted.",
  "teacher.myQuestionPapers.deleteError": "Could not delete the question paper. Please try again.",
  "teacher.myQuestionPapers.empty": "You haven't created any question papers yet.",
  "teacher.myQuestionPapers.loading": "Loading...",
```

- [ ] **Step 3: Add matching Bangla i18n keys**

Add the following keys to `frontend/packages/i18n/src/common/bn.json` — same keys, in the same relative place, as Step 2:

```json
  "management.nav.questionBank": "প্রশ্ন ব্যাংক",
  "management.nav.questionPapers": "প্রশ্নপত্র সমূহ",
  "management.questionBank.title": "প্রশ্ন ব্যাংক",
  "management.questionBank.colClass": "শ্রেণি",
  "management.questionBank.colSubject": "বিষয়",
  "management.questionBank.colChapter": "অধ্যায়",
  "management.questionBank.colType": "ধরন",
  "management.questionBank.colMarks": "নম্বর",
  "management.questionBank.colActive": "সক্রিয়",
  "management.questionBank.typeCq": "সৃজনশীল",
  "management.questionBank.typeMcq": "বহুনির্বাচনি",
  "management.questionBank.chapterNumber": "অধ্যায় নম্বর",
  "management.questionBank.chapterName": "অধ্যায়ের নাম",
  "management.questionBank.questionText": "প্রশ্নের বিবরণ",
  "management.questionBank.option1": "অপশন ১",
  "management.questionBank.option2": "অপশন ২",
  "management.questionBank.option3": "অপশন ৩",
  "management.questionBank.option4": "অপশন ৪",
  "management.questionBank.marks": "নম্বর",
  "management.questionBank.active": "সক্রিয়",
  "management.questionBank.allTypes": "সকল ধরন",
  "management.questionPapers.title": "প্রশ্নপত্র সমূহ",
  "management.questionPapers.colTitle": "শিরোনাম",
  "management.questionPapers.colTeacher": "শিক্ষক",
  "management.questionPapers.colClass": "শ্রেণি",
  "management.questionPapers.colSubject": "বিষয়",
  "management.questionPapers.colCreated": "তৈরি হয়েছে",
  "management.questionPapers.print": "প্রিন্ট করুন",
  "management.questionPapers.empty": "কোনো প্রশ্নপত্র পাওয়া যায়নি।",
  "management.questionPapers.loading": "লোড হচ্ছে...",
  "teacher.nav.questionBuilder": "প্রশ্নপত্র তৈরি করুন",
  "teacher.nav.myQuestionPapers": "আমার প্রশ্নপত্র সমূহ",
  "teacher.questionBuilder.title": "প্রশ্নপত্র তৈরি করুন",
  "teacher.questionBuilder.selectClass": "শ্রেণি নির্বাচন করুন",
  "teacher.questionBuilder.selectSubject": "বিষয় নির্বাচন করুন",
  "teacher.questionBuilder.chapterNumber": "অধ্যায় নম্বর",
  "teacher.questionBuilder.selectType": "ধরন নির্বাচন করুন",
  "teacher.questionBuilder.allTypes": "সকল ধরন",
  "teacher.questionBuilder.cqLabel": "সৃজনশীল",
  "teacher.questionBuilder.mcqLabel": "বহুনির্বাচনি",
  "teacher.questionBuilder.marksFilter": "নম্বর",
  "teacher.questionBuilder.loadQuestions": "প্রশ্ন লোড করুন",
  "teacher.questionBuilder.bankTitle": "উপলব্ধ প্রশ্ন সমূহ",
  "teacher.questionBuilder.paperTitle": "প্রশ্নপত্রের লেআউট",
  "teacher.questionBuilder.titleLabel": "প্রশ্নপত্রের শিরোনাম",
  "teacher.questionBuilder.timeDuration": "সময়কাল (মিনিট)",
  "teacher.questionBuilder.fullMarks": "পূর্ণমান",
  "teacher.questionBuilder.instructions": "নির্দেশনা",
  "teacher.questionBuilder.insertHeading": "শিরোনাম যুক্ত করুন",
  "teacher.questionBuilder.headingPlaceholder": "শিরোনামের লেখা",
  "teacher.questionBuilder.addHeading": "যুক্ত করুন",
  "teacher.questionBuilder.cancelHeading": "বাতিল করুন",
  "teacher.questionBuilder.removeItem": "সরান",
  "teacher.questionBuilder.dragHint": "প্রশ্নপত্র তৈরি করতে প্রশ্ন এখানে টেনে আনুন",
  "teacher.questionBuilder.save": "প্রশ্নপত্র সংরক্ষণ করুন",
  "teacher.questionBuilder.saving": "সংরক্ষণ হচ্ছে...",
  "teacher.questionBuilder.saveSuccess": "প্রশ্নপত্র সংরক্ষিত হয়েছে।",
  "teacher.questionBuilder.saveError": "প্রশ্নপত্র সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।",
  "teacher.questionBuilder.empty": "এই শর্তে কোনো প্রশ্ন পাওয়া যায়নি।",
  "teacher.questionBuilder.loading": "লোড হচ্ছে...",
  "teacher.myQuestionPapers.title": "আমার প্রশ্নপত্র সমূহ",
  "teacher.myQuestionPapers.createNew": "নতুন প্রশ্নপত্র",
  "teacher.myQuestionPapers.colTitle": "শিরোনাম",
  "teacher.myQuestionPapers.colClass": "শ্রেণি",
  "teacher.myQuestionPapers.colSubject": "বিষয়",
  "teacher.myQuestionPapers.colCreated": "তৈরি হয়েছে",
  "teacher.myQuestionPapers.colActions": "কার্যক্রম",
  "teacher.myQuestionPapers.print": "প্রিন্ট করুন",
  "teacher.myQuestionPapers.edit": "সম্পাদনা করুন",
  "teacher.myQuestionPapers.delete": "মুছে ফেলুন",
  "teacher.myQuestionPapers.deleteConfirm": "এই প্রশ্নপত্রটি মুছে ফেলতে চান?",
  "teacher.myQuestionPapers.deleteSuccess": "প্রশ্নপত্র মুছে ফেলা হয়েছে।",
  "teacher.myQuestionPapers.deleteError": "প্রশ্নপত্র মুছে ফেলা যায়নি। আবার চেষ্টা করুন।",
  "teacher.myQuestionPapers.empty": "আপনি এখনো কোনো প্রশ্নপত্র তৈরি করেননি।",
  "teacher.myQuestionPapers.loading": "লোড হচ্ছে...",
```

- [ ] **Step 4: Verify both i18n files still have identical key sets**

Run (from `frontend/`): a quick parity check — e.g. `node -e "const en=require('./packages/i18n/src/common/en.json'); const bn=require('./packages/i18n/src/common/bn.json'); const a=Object.keys(en).sort(); const b=Object.keys(bn).sort(); console.log(JSON.stringify(a)===JSON.stringify(b) ? 'OK' : 'MISMATCH')"`
Expected: `OK`. A mismatch here means a key was added to one file and not the other — fix before continuing.

- [ ] **Step 5: Type-check**

Run: `npm run build -w @ttla/api-client -w @ttla/i18n` (or `tsc -b` in each package directory, per the existing `build` script) from `frontend/`.
Expected: no TypeScript errors.

- [ ] **Step 6: Commit**

```bash
git add packages/api-client/src/index.ts packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add api-client types/functions and i18n strings for the Question Builder feature"
```

---

### Task 4: Management portal — "Question Bank" page (national_admin only)

**Files:**
- Create: `frontend/apps/management/src/pages/AcademicQuestionsPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`

**Interfaces:**
- Consumes: `listAcademicQuestions`/`createAcademicQuestion`/`updateAcademicQuestion`/`deleteAcademicQuestion`/`AcademicQuestion` (Task 3); `listClasses`/`listSubjects`/`SchoolClass`/`Subject` (existing); `GeoCrudTable`, `GeoField`, `toSinglePage`, `type GeoColumn` from `../components/GeoCrudTable` (existing); `SearchableSelect` from `../components/SearchableSelect` (existing); `inputClass` from `../components/styles` (existing).
- Produces: route `/dashboard/question-bank`, nav item visible to `national_admin` only.

- [ ] **Step 1: Create the page**

```tsx
import { useEffect, useState } from 'react'
import {
  createAcademicQuestion,
  deleteAcademicQuestion,
  listAcademicQuestions,
  listClasses,
  listSubjects,
  updateAcademicQuestion,
  type AcademicQuestion,
  type AcademicQuestionType,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import GeoCrudTable, { GeoField, toSinglePage, type GeoColumn } from '../components/GeoCrudTable'
import SearchableSelect from '../components/SearchableSelect'
import { inputClass } from '../components/styles'

const t = getCommonStrings('en')

function matchesSearch(chapterName: string, questionText: string, search: string): boolean {
  if (!search.trim()) return true
  const needle = search.trim().toLowerCase()
  return chapterName.toLowerCase().includes(needle) || questionText.toLowerCase().includes(needle)
}

const initialValues = {
  school_class_id: '',
  subject_id: '',
  chapter_number: '',
  chapter_name: '',
  question_type: 'cq',
  question_text: '',
  option_1: '',
  option_2: '',
  option_3: '',
  option_4: '',
  marks: '',
  is_active: 'true',
}

// Note: option_1..4 are NOT in this list — their requiredness depends on
// question_type (MCQ only), which GeoCrudTable's blunt requiredFields
// check can't express. The server validates that conditional requirement
// (StoreAcademicQuestionRequest::withValidator()) and its 'options' error
// is surfaced via the fieldErrors.options banner in the form below.
const requiredFields = ['school_class_id', 'subject_id', 'chapter_number', 'chapter_name', 'question_type', 'question_text', 'marks']

function optionsFromValues(values: Record<string, string>): string[] | null {
  if (values.question_type !== 'mcq') return null
  return [values.option_1, values.option_2, values.option_3, values.option_4]
}

export default function AcademicQuestionsPage() {
  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])

  useEffect(() => {
    listClasses({ perPage: 500 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

    listSubjects({ perPage: 200 })
      .then((result) => setSubjects(result.data))
      .catch(() => setSubjects([]))
  }, [])

  const columns: GeoColumn<AcademicQuestion>[] = [
    { key: 'class', label: t['management.questionBank.colClass'], render: (row) => row.school_class?.name ?? '—' },
    { key: 'subject', label: t['management.questionBank.colSubject'], render: (row) => row.subject?.name ?? '—' },
    {
      key: 'chapter',
      label: t['management.questionBank.colChapter'],
      render: (row) => `${row.chapter_number}. ${row.chapter_name}`,
    },
    {
      key: 'type',
      label: t['management.questionBank.colType'],
      render: (row) => (row.question_type === 'cq' ? t['management.questionBank.typeCq'] : t['management.questionBank.typeMcq']),
    },
    { key: 'marks', label: t['management.questionBank.colMarks'], render: (row) => row.marks },
    {
      key: 'active',
      label: t['management.questionBank.colActive'],
      render: (row) => (row.is_active ? '✓' : '—'),
    },
  ]

  return (
    <GeoCrudTable<AcademicQuestion>
      title={t['management.questionBank.title']}
      columns={columns}
      initialValues={initialValues}
      requiredFields={requiredFields}
      valuesFromRow={(row) => ({
        school_class_id: String(row.school_class_id),
        subject_id: String(row.subject_id),
        chapter_number: String(row.chapter_number),
        chapter_name: row.chapter_name,
        question_type: row.question_type,
        question_text: row.question_text,
        option_1: row.options?.[0] ?? '',
        option_2: row.options?.[1] ?? '',
        option_3: row.options?.[2] ?? '',
        option_4: row.options?.[3] ?? '',
        marks: String(row.marks),
        is_active: row.is_active ? 'true' : 'false',
      })}
      renderFields={(values, onChange, fieldErrors) => (
        <>
          <GeoField label={t['management.fields.class']} required error={fieldErrors.school_class_id}>
            <SearchableSelect
              value={values.school_class_id}
              onChange={(next) => onChange('school_class_id', next)}
              placeholder={t['management.fields.class']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={classes.map((schoolClass) => ({ value: String(schoolClass.id), label: schoolClass.name }))}
            />
          </GeoField>

          <GeoField label={t['management.fields.subject']} required error={fieldErrors.subject_id}>
            <SearchableSelect
              value={values.subject_id}
              onChange={(next) => onChange('subject_id', next)}
              placeholder={t['management.fields.subject']}
              searchPlaceholder={t['management.table.search']}
              noMatchesLabel={t['management.table.noMatches']}
              options={subjects.map((subject) => ({ value: String(subject.id), label: subject.name }))}
            />
          </GeoField>

          <GeoField label={t['management.questionBank.chapterNumber']} required error={fieldErrors.chapter_number}>
            <input
              type="number"
              min={1}
              className={inputClass}
              value={values.chapter_number}
              onChange={(event) => onChange('chapter_number', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.questionBank.chapterName']} required error={fieldErrors.chapter_name}>
            <input
              className={inputClass}
              value={values.chapter_name}
              onChange={(event) => onChange('chapter_name', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.questionBank.colType']} required error={fieldErrors.question_type}>
            <select
              className={inputClass}
              value={values.question_type}
              onChange={(event) => onChange('question_type', event.target.value as AcademicQuestionType)}
            >
              <option value="cq">{t['management.questionBank.typeCq']}</option>
              <option value="mcq">{t['management.questionBank.typeMcq']}</option>
            </select>
          </GeoField>

          <GeoField label={t['management.questionBank.questionText']} required error={fieldErrors.question_text}>
            <textarea
              className={`${inputClass} h-auto py-2`}
              rows={4}
              value={values.question_text}
              onChange={(event) => onChange('question_text', event.target.value)}
            />
          </GeoField>

          {values.question_type === 'mcq' && (
            <>
              <GeoField label={t['management.questionBank.option1']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_1} onChange={(event) => onChange('option_1', event.target.value)} />
              </GeoField>
              <GeoField label={t['management.questionBank.option2']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_2} onChange={(event) => onChange('option_2', event.target.value)} />
              </GeoField>
              <GeoField label={t['management.questionBank.option3']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_3} onChange={(event) => onChange('option_3', event.target.value)} />
              </GeoField>
              <GeoField label={t['management.questionBank.option4']} error={fieldErrors.options}>
                <input className={inputClass} value={values.option_4} onChange={(event) => onChange('option_4', event.target.value)} />
              </GeoField>
            </>
          )}

          <GeoField label={t['management.questionBank.marks']} required error={fieldErrors.marks}>
            <input
              type="number"
              min={1}
              className={inputClass}
              value={values.marks}
              onChange={(event) => onChange('marks', event.target.value)}
            />
          </GeoField>

          <GeoField label={t['management.questionBank.active']} error={fieldErrors.is_active}>
            <select className={inputClass} value={values.is_active} onChange={(event) => onChange('is_active', event.target.value)}>
              <option value="true">{t['management.questionBank.active']}</option>
              <option value="false">—</option>
            </select>
          </GeoField>
        </>
      )}
      fetchPage={(_page, filters) =>
        listAcademicQuestions({
          schoolClassId: filters.parentId,
          subjectId: filters.secondaryParentId,
        }).then((result) =>
          toSinglePage(
            result.data.filter((question) => matchesSearch(question.chapter_name, question.question_text, filters.search)),
          ),
        )
      }
      createRow={(values, token) =>
        createAcademicQuestion(
          {
            school_class_id: Number(values.school_class_id),
            subject_id: Number(values.subject_id),
            chapter_number: Number(values.chapter_number),
            chapter_name: values.chapter_name,
            question_type: values.question_type as AcademicQuestionType,
            question_text: values.question_text,
            options: optionsFromValues(values),
            marks: Number(values.marks),
            is_active: values.is_active === 'true',
          },
          token,
        )
      }
      updateRow={(id, values, token) =>
        updateAcademicQuestion(
          id,
          {
            school_class_id: Number(values.school_class_id),
            subject_id: Number(values.subject_id),
            chapter_number: Number(values.chapter_number),
            chapter_name: values.chapter_name,
            question_type: values.question_type as AcademicQuestionType,
            question_text: values.question_text,
            options: optionsFromValues(values),
            marks: Number(values.marks),
            is_active: values.is_active === 'true',
          },
          token,
        )
      }
      deleteRow={(id, token) => deleteAcademicQuestion(id, token)}
      createPermission="academic_questions.create"
      updatePermission="academic_questions.update"
      deletePermission="academic_questions.delete"
      parentFilter={{
        label: t['management.fields.class'],
        allLabel: t['management.fields.allClasses'],
        options: classes.map((schoolClass) => ({ id: schoolClass.id, label: schoolClass.name })),
      }}
      secondaryFilter={{
        label: t['management.fields.subject'],
        allLabel: t['management.fields.allSubjects'],
        options: subjects.map((subject) => ({ id: subject.id, label: subject.name })),
      }}
    />
  )
}
```

- [ ] **Step 2: Wire up the nav item and route**

In `frontend/apps/management/src/App.tsx`:

Add the import alphabetically — find:

```tsx
import AcademicYearsPage from './pages/AcademicYearsPage'
import AllChaptersPage from './pages/AllChaptersPage'
```

and insert between them:

```tsx
import AcademicYearsPage from './pages/AcademicYearsPage'
import AcademicQuestionsPage from './pages/AcademicQuestionsPage'
import AllChaptersPage from './pages/AllChaptersPage'
```

(Note: `AcademicQuestionsPage` sorts after `AcademicYearsPage` alphabetically by filename in most editors' auto-sort, but this codebase's existing import list is already not strictly alphabetical in every case — e.g. `TeacherLeaveApplicationsPage` precedes `TeacherRoutinePage` which precedes `TeachersOnLeavePage`, not `TeachersPage`. Place it near the other `Academic*` imports; exact position among them doesn't affect behavior.)

Add a new nav item — find the `holidaysNavItem` constant and insert a new one right after it:

```tsx
// national_admin only — the national question bank. teacher browses it
// (academic_questions.view) to build papers in the teacher portal;
// school_admin never gets access (see questionPapersNavItem instead,
// which is school_admin's own, print-only, teacher-paper view).
const questionBankNavItem: SidebarItem = {
  to: '/dashboard/question-bank',
  label: t['management.nav.questionBank'],
  icon: 'quiz',
}
```

In `DashboardLayout`'s `navItems` array, find this line:

```tsx
    ...(role === 'national_admin' ? [holidaysNavItem, schoolSetupItem] : []),
```

Change it to:

```tsx
    ...(role === 'national_admin' ? [holidaysNavItem, questionBankNavItem, schoolSetupItem] : []),
```

Add the route — find:

```tsx
              <Route path="teachers-on-leave" element={<RequireNationalAdmin />}>
                <Route index element={<TeachersOnLeavePage />} />
              </Route>
```

and insert immediately after it:

```tsx
              <Route path="question-bank" element={<RequireNationalAdmin />}>
                <Route index element={<AcademicQuestionsPage />} />
              </Route>
```

- [ ] **Step 3: Type-check and manually verify**

Run: `npm run build -w @ttla/app-management` (or the app's own `build` script) from `frontend/`.
Expected: no TypeScript errors.

Manually verify in a browser: log in as `national_admin`, confirm "Question Bank" appears in the sidebar, Add/Edit/Delete work for both CQ and MCQ questions (the four option fields appear only for MCQ), and the class/subject filters narrow the table. Then log in as `school_admin` and confirm the nav item is absent and `/dashboard/question-bank` shows the access-denied message.

- [ ] **Step 4: Commit**

```bash
git add apps/management/src/pages/AcademicQuestionsPage.tsx apps/management/src/App.tsx
git commit -m "Add the management portal's national-admin-only Question Bank page"
```

---

### Task 5: Management portal — "Question Papers" page (school_admin, print-only)

**Files:**
- Create: `frontend/apps/management/src/pages/QuestionPapersPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`

**Interfaces:**
- Consumes: `listQuestionPapers`, `type QuestionPaper` (Task 3) — the list response already eager-loads `items.academicQuestion` server-side (`QuestionPaperController::index()` uses the same `WITH` constant as `show()`), so no second fetch is needed to print from the list. `RequireSchoolAdmin` guard (already defined in `App.tsx` from the teacher-leave-applications feature).
- Produces: route `/dashboard/question-papers`, nav item visible to `school_admin` only. The `PrintableQuestionPaper` component defined here is intentionally duplicated (not shared) in Task 7's teacher-portal page, per this codebase's established per-app duplication convention (see `Pagination`/`SearchableSelect`).

- [ ] **Step 1: Create the page**

```tsx
import { useEffect, useState } from 'react'
import { listQuestionPapers, type QuestionPaper } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'

const t = getCommonStrings('en')

// Deliberately duplicated in the teacher app's MyQuestionPapersPage —
// see this plan's Global Constraints on per-app duplication of shared
// rendering, not a cross-app packages/ui component.
function PrintableQuestionPaper({ paper }: { paper: QuestionPaper }) {
  const items = paper.items ?? []
  let questionNumber = 0

  return (
    <div className="mx-auto max-w-3xl bg-white p-8 text-gray-900 print:p-0">
      <div className="text-center">
        <h1 className="text-lg font-bold">{paper.title}</h1>
        <p className="mt-1 text-sm">
          {paper.school_class?.name ?? ''} — {paper.subject?.name ?? ''}
        </p>
        <div className="mt-2 flex justify-center gap-6 text-sm">
          {paper.time_duration_minutes !== null && <span>Time: {paper.time_duration_minutes} min</span>}
          {paper.full_marks !== null && <span>Full Marks: {paper.full_marks}</span>}
        </div>
        {paper.instructions && <p className="mt-2 text-sm italic">{paper.instructions}</p>}
      </div>

      <div className="mt-6 flex flex-col gap-3">
        {items.map((item) => {
          if (item.item_type === 'heading') {
            return (
              <h2 key={item.id} className="mt-4 text-base font-semibold underline">
                {item.heading_text}
              </h2>
            )
          }

          questionNumber += 1
          const question = item.academic_question

          return (
            <div key={item.id} className="flex justify-between gap-4">
              <div className="flex-1">
                <p className="whitespace-pre-wrap">
                  {questionNumber}. {question?.question_text}
                </p>
                {question?.question_type === 'mcq' && question.options && (
                  <ol className="mt-1 ml-5 list-none">
                    {question.options.map((option, index) => (
                      // ক/খ/গ/ঘ are four consecutive Unicode code points
                      // (U+0995..U+0998) — exactly matching the 4-option
                      // MCQ shape, same convention as the sample exam
                      // papers this feature's design was based on.
                      <li key={index}>
                        {String.fromCharCode(0x0995 + index)}) {option}
                      </li>
                    ))}
                  </ol>
                )}
              </div>
              <span className="shrink-0 text-sm">{question?.marks}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export default function QuestionPapersPage() {
  const { token } = useAuth()
  const [papers, setPapers] = useState<QuestionPaper[]>([])
  const [loading, setLoading] = useState(true)
  const [printingPaper, setPrintingPaper] = useState<QuestionPaper | null>(null)

  useEffect(() => {
    if (!token) return

    listQuestionPapers(token)
      .then((result) => setPapers(result.data))
      .catch(() => setPapers([]))
      .finally(() => setLoading(false))
  }, [token])

  function handlePrint(paper: QuestionPaper) {
    setPrintingPaper(paper)
    // Let the printable view render before invoking the browser's print
    // dialog — window.print() runs synchronously against whatever is
    // already painted, same pattern as MarksheetsPage (there, the
    // printable section is always mounted; here, mounting itself is
    // conditional on which paper was clicked, so a frame must elapse
    // first).
    requestAnimationFrame(() => window.print())
  }

  return (
    <div>
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <h1 className="text-xl font-semibold text-gray-900 print:hidden">{t['management.questionPapers.title']}</h1>

      {loading && <p className="mt-4 text-sm text-gray-500 print:hidden">{t['management.questionPapers.loading']}</p>}

      {!loading && papers.length === 0 && (
        <p className="mt-4 rounded-md bg-white p-5 text-sm text-gray-500 shadow-sm print:hidden">
          {t['management.questionPapers.empty']}
        </p>
      )}

      {!loading && papers.length > 0 && !printingPaper && (
        <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm print:hidden">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colTitle']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colTeacher']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colClass']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colSubject']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.colCreated']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.questionPapers.print']}</th>
              </tr>
            </thead>
            <tbody>
              {papers.map((paper) => (
                <tr key={paper.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.title}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.teacher?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.school_class?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.subject?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{paper.created_at.slice(0, 10)}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <button
                      type="button"
                      onClick={() => handlePrint(paper)}
                      className="inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary-400"
                    >
                      <span className="material-symbols-outlined !text-[16px] leading-none">print</span>
                      {t['management.questionPapers.print']}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {printingPaper && (
        <>
          <button
            type="button"
            onClick={() => setPrintingPaper(null)}
            className="mt-4 cursor-pointer rounded-md border border-gray-200 px-4 py-2 text-sm text-gray-600 hover:border-primary-500 print:hidden"
          >
            ×
          </button>
          <PrintableQuestionPaper paper={printingPaper} />
        </>
      )}
    </div>
  )
}
```

- [ ] **Step 2: Wire up the nav item and route**

In `frontend/apps/management/src/App.tsx`, add the import alphabetically — find:

```tsx
import PublishResultsPage from './pages/PublishResultsPage'
```

and insert a new line immediately before it:

```tsx
import QuestionPapersPage from './pages/QuestionPapersPage'
import PublishResultsPage from './pages/PublishResultsPage'
```

Add a new nav item — find the `teacherLeaveApplicationsNavItem` constant and insert a new one right after it:

```tsx
// school_admin only — print-only view of every question paper built by
// any teacher at their own school (TenantScope-filtered). national_admin
// never gets this nav item: it manages the underlying question bank
// instead (questionBankNavItem), not individual teachers' papers.
const questionPapersNavItem: SidebarItem = {
  to: '/dashboard/question-papers',
  label: t['management.nav.questionPapers'],
  icon: 'description',
}
```

In `DashboardLayout`'s `navItems` array, find this line:

```tsx
    ...(role === 'school_admin' ? [teacherLeaveApplicationsNavItem] : []),
```

Change it to:

```tsx
    ...(role === 'school_admin' ? [teacherLeaveApplicationsNavItem, questionPapersNavItem] : []),
```

Add the route — find:

```tsx
              <Route path="teacher-leave-applications" element={<RequireSchoolAdmin />}>
                <Route index element={<TeacherLeaveApplicationsPage />} />
              </Route>
```

and insert immediately after it:

```tsx
              <Route path="question-papers" element={<RequireSchoolAdmin />}>
                <Route index element={<QuestionPapersPage />} />
              </Route>
```

- [ ] **Step 3: Type-check and manually verify**

Run: `npm run build -w @ttla/app-management` from `frontend/`.
Expected: no TypeScript errors.

Manually verify in a browser: log in as `school_admin`, confirm "Question Papers" appears in the sidebar, every teacher's paper at that school is listed, and clicking Print opens the browser's print dialog showing a correctly formatted paper (headings as dividers, questions numbered continuously, MCQ options as ক)/খ)/গ)/ঘ)). Confirm no Edit/Delete controls exist anywhere on this page.

- [ ] **Step 4: Commit**

```bash
git add apps/management/src/pages/QuestionPapersPage.tsx apps/management/src/App.tsx
git commit -m "Add the management portal's school-admin print-only Question Papers page"
```

---

### Task 6: Teacher portal — "Question Builder" page (drag-and-drop)

**Files:**
- Modify: `frontend/apps/teacher/package.json`
- Create: `frontend/apps/teacher/src/pages/questionBuilderCanvas.ts`
- Create: `frontend/apps/teacher/src/pages/questionBuilderCanvas.test.ts`
- Create: `frontend/apps/teacher/src/pages/QuestionBuilderPage.tsx`
- Modify: `frontend/apps/teacher/src/App.tsx`
- Modify: `frontend/apps/teacher/src/components/TeacherAppShell.tsx`

**Interfaces:**
- Consumes: `listClasses`/`listSubjects`/`listAcademicQuestions`/`createQuestionPaper`/`AcademicQuestion`/`AcademicQuestionType`/`SchoolClass`/`Subject`/`ApiError` (Task 3); `useToast`/`ToastViewport` from `../components/Toast` (existing, already used by `ClassMaterialsPage`).
- Produces: `CanvasItem` type and the pure `addQuestionToCanvas`/`addHeadingToCanvas`/`removeCanvasItem`/`reorderCanvasItems` functions (unit-tested in this task, consumed only by `QuestionBuilderPage.tsx` itself). Route `/question-builder`, nav item in `TeacherAppShell`.

- [ ] **Step 1: Add the `@dnd-kit` dependency**

No drag-and-drop library exists anywhere in this codebase yet (only native HTML5 drag/drop for file uploads in `ClassMaterialsPage`). Add `@dnd-kit/core` and `@dnd-kit/sortable` to `frontend/apps/teacher/package.json`'s `dependencies` (alphabetically, alongside the existing `@ttla/*` entries):

```json
  "dependencies": {
    "@dnd-kit/core": "^6.3.1",
    "@dnd-kit/sortable": "^10.0.0",
    "@dnd-kit/utilities": "^3.2.2",
    "@ttla/api-client": "*",
    "@ttla/auth": "*",
    "@ttla/i18n": "*",
    "@ttla/ui": "*",
    "react": "^19.3.1",
    "react-dom": "^19.3.1",
    "react-router-dom": "^7.18.4"
  }
```

Run: `npm install` from `frontend/` (the workspace root — installs into the right place for the `apps/teacher` workspace).
Expected: `@dnd-kit/core`, `@dnd-kit/sortable`, and `@dnd-kit/utilities` appear under `frontend/node_modules/.package-lock.json` (or `apps/teacher/node_modules`, depending on hoisting) with no install errors.

- [ ] **Step 2: Create the pure canvas-logic module**

Kept separate from the page component specifically so its add/remove/reorder/heading-insert logic can be unit-tested without rendering the page or simulating a real drag gesture (`@dnd-kit` has no first-class vitest/jsdom testing utilities — drag-gesture testing belongs in an e2e tool, not a unit test).

```ts
import type { AcademicQuestion } from '@ttla/api-client'

export interface CanvasItem {
  uid: string
  type: 'question' | 'heading'
  question?: AcademicQuestion
  headingText?: string
}

export function addQuestionToCanvas(items: CanvasItem[], question: AcademicQuestion): CanvasItem[] {
  return [...items, { uid: crypto.randomUUID(), type: 'question', question }]
}

export function addHeadingToCanvas(items: CanvasItem[], headingText: string): CanvasItem[] {
  const trimmed = headingText.trim()
  if (!trimmed) return items

  return [...items, { uid: crypto.randomUUID(), type: 'heading', headingText: trimmed }]
}

export function removeCanvasItem(items: CanvasItem[], uid: string): CanvasItem[] {
  return items.filter((item) => item.uid !== uid)
}

// Moves the item with id activeUid to the position currently held by
// overUid — the exact reorder semantics dnd-kit's own arrayMove()
// provides, reimplemented here (rather than imported) so this module has
// zero dependency on @dnd-kit and can be unit-tested with nothing but
// plain data.
export function reorderCanvasItems(items: CanvasItem[], activeUid: string, overUid: string): CanvasItem[] {
  if (activeUid === overUid) return items

  const oldIndex = items.findIndex((item) => item.uid === activeUid)
  const newIndex = items.findIndex((item) => item.uid === overUid)
  if (oldIndex === -1 || newIndex === -1) return items

  const copy = [...items]
  const [moved] = copy.splice(oldIndex, 1)
  copy.splice(newIndex, 0, moved)
  return copy
}
```

- [ ] **Step 3: Write the failing tests**

```ts
import { describe, expect, it } from 'vitest'
import type { AcademicQuestion } from '@ttla/api-client'
import {
  addHeadingToCanvas,
  addQuestionToCanvas,
  removeCanvasItem,
  reorderCanvasItems,
  type CanvasItem,
} from './questionBuilderCanvas'

function makeQuestion(overrides: Partial<AcademicQuestion> = {}): AcademicQuestion {
  return {
    id: 1,
    school_class_id: 1,
    subject_id: 1,
    chapter_number: 1,
    chapter_name: 'Prose',
    question_type: 'cq',
    question_text: 'Read the passage...',
    options: null,
    marks: 10,
    is_active: true,
    created_at: '2026-11-25T00:00:00Z',
    ...overrides,
  }
}

describe('addQuestionToCanvas', () => {
  it('appends a new question item carrying the given question', () => {
    const result = addQuestionToCanvas([], makeQuestion({ id: 7 }))

    expect(result).toHaveLength(1)
    expect(result[0].type).toBe('question')
    expect(result[0].question?.id).toBe(7)
    expect(result[0].uid).toBeTruthy()
  })

  it('appends after existing items without disturbing them', () => {
    const existing: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Prose' }]
    const result = addQuestionToCanvas(existing, makeQuestion())

    expect(result).toHaveLength(2)
    expect(result[0]).toBe(existing[0])
    expect(result[1].type).toBe('question')
  })
})

describe('addHeadingToCanvas', () => {
  it('appends a new heading item with the trimmed text', () => {
    const result = addHeadingToCanvas([], '  Poetry  ')

    expect(result).toHaveLength(1)
    expect(result[0]).toMatchObject({ type: 'heading', headingText: 'Poetry' })
  })

  it('is a no-op for blank or whitespace-only text', () => {
    expect(addHeadingToCanvas([], '')).toEqual([])
    expect(addHeadingToCanvas([], '   ')).toEqual([])
  })
})

describe('removeCanvasItem', () => {
  it('removes exactly the item with the matching uid', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', headingText: 'Prose' },
      { uid: 'b', type: 'question', question: makeQuestion() },
    ]

    expect(removeCanvasItem(items, 'a')).toEqual([items[1]])
  })

  it('is a no-op when the uid is not present', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Prose' }]

    expect(removeCanvasItem(items, 'missing')).toEqual(items)
  })
})

describe('reorderCanvasItems', () => {
  it('moves the active item to the position of the over item', () => {
    const items: CanvasItem[] = [
      { uid: 'a', type: 'heading', headingText: 'First' },
      { uid: 'b', type: 'heading', headingText: 'Second' },
      { uid: 'c', type: 'heading', headingText: 'Third' },
    ]

    const result = reorderCanvasItems(items, 'c', 'a')

    expect(result.map((item) => item.uid)).toEqual(['c', 'a', 'b'])
  })

  it('is a no-op when active and over are the same item', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Only' }]

    expect(reorderCanvasItems(items, 'a', 'a')).toBe(items)
  })

  it('is a no-op when either uid is not found', () => {
    const items: CanvasItem[] = [{ uid: 'a', type: 'heading', headingText: 'Only' }]

    expect(reorderCanvasItems(items, 'a', 'missing')).toBe(items)
    expect(reorderCanvasItems(items, 'missing', 'a')).toBe(items)
  })
})
```

- [ ] **Step 4: Run the tests to verify they pass against Step 2's implementation**

Run: `npx vitest run src/pages/questionBuilderCanvas.test.ts` (from `frontend/apps/teacher/`)
Expected: all tests PASS (the module from Step 2 was written before this step, so this confirms it, rather than driving it — the logic was simple enough to write directly; the test suite is what guards it from regressing later).

- [ ] **Step 5: Create the page**

```tsx
import { DndContext, useDraggable, useDroppable, type DragEndEvent } from '@dnd-kit/core'
import { SortableContext, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { useEffect, useState } from 'react'
import {
  ApiError,
  createQuestionPaper,
  listAcademicQuestions,
  listClasses,
  listSubjects,
  type AcademicQuestion,
  type AcademicQuestionType,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'
import {
  addHeadingToCanvas,
  addQuestionToCanvas,
  removeCanvasItem,
  reorderCanvasItems,
  type CanvasItem,
} from './questionBuilderCanvas'

const t = getCommonStrings('en')

const selectClass =
  'h-11 w-full rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:opacity-60'
const buttonPrimary =
  'inline-flex h-11 cursor-pointer items-center gap-1.5 rounded-md bg-primary-500 px-4 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60'
const buttonNeutral =
  'inline-flex h-11 cursor-pointer items-center rounded-md border border-gray-200 px-4 text-sm font-medium text-gray-600 transition-colors hover:border-primary-500'

function BankQuestionCard({ question }: { question: AcademicQuestion }) {
  const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable({
    id: `bank-${question.id}`,
    data: { type: 'bank-question', question },
  })

  const style = transform ? { transform: `translate3d(${transform.x}px, ${transform.y}px, 0)` } : undefined

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...listeners}
      {...attributes}
      className={`cursor-grab rounded-md border border-gray-200 bg-white p-3 text-sm shadow-sm active:cursor-grabbing ${isDragging ? 'opacity-50' : ''}`}
    >
      <p className="whitespace-pre-wrap">{question.question_text}</p>
      <p className="mt-1 text-xs text-gray-400">
        {question.question_type === 'cq' ? t['teacher.questionBuilder.cqLabel'] : t['teacher.questionBuilder.mcqLabel']} ·{' '}
        {question.marks}
      </p>
    </div>
  )
}

function CanvasItemCard({ item, index, onRemove }: { item: CanvasItem; index: number; onRemove: () => void }) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id: item.uid })
  const style = { transform: CSS.Transform.toString(transform), transition }

  if (item.type === 'heading') {
    return (
      <div
        ref={setNodeRef}
        style={style}
        {...attributes}
        {...listeners}
        className={`flex items-center justify-between rounded-md bg-gray-100 px-3 py-2 ${isDragging ? 'opacity-50' : ''}`}
      >
        <span className="text-sm font-semibold text-gray-700">{item.headingText}</span>
        <button type="button" onClick={onRemove} className="cursor-pointer text-danger-500 hover:text-danger-600">
          <span className="material-symbols-outlined !text-[18px]">close</span>
        </button>
      </div>
    )
  }

  return (
    <div
      ref={setNodeRef}
      style={style}
      {...attributes}
      {...listeners}
      className={`flex items-start justify-between gap-3 rounded-md border border-gray-200 bg-white p-3 text-sm ${isDragging ? 'opacity-50' : ''}`}
    >
      <div>
        <p className="whitespace-pre-wrap">
          {index + 1}. {item.question?.question_text}
        </p>
        <p className="mt-1 text-xs text-gray-400">
          {item.question?.marks} {t['teacher.questionBuilder.marksFilter']}
        </p>
      </div>
      <button type="button" onClick={onRemove} className="shrink-0 cursor-pointer text-danger-500 hover:text-danger-600">
        <span className="material-symbols-outlined !text-[18px]">close</span>
      </button>
    </div>
  )
}

function CanvasDropZone({ items, onRemove }: { items: CanvasItem[]; onRemove: (uid: string) => void }) {
  const { setNodeRef, isOver } = useDroppable({ id: 'canvas' })

  return (
    <div
      ref={setNodeRef}
      className={`flex min-h-[200px] flex-col gap-2 rounded-md border-2 border-dashed p-4 transition-colors ${
        isOver ? 'border-primary-500 bg-primary-50' : 'border-gray-200'
      }`}
    >
      {items.length === 0 && <p className="text-sm text-gray-400">{t['teacher.questionBuilder.dragHint']}</p>}

      <SortableContext items={items.map((item) => item.uid)} strategy={verticalListSortingStrategy}>
        {items.map((item, index) => (
          <CanvasItemCard key={item.uid} item={item} index={index} onRemove={() => onRemove(item.uid)} />
        ))}
      </SortableContext>
    </div>
  )
}

export default function QuestionBuilderPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [subjects, setSubjects] = useState<Subject[]>([])

  const [filterClassId, setFilterClassId] = useState('')
  const [filterSubjectId, setFilterSubjectId] = useState('')
  const [filterChapterNumber, setFilterChapterNumber] = useState('')
  const [filterType, setFilterType] = useState('')
  const [filterMarks, setFilterMarks] = useState('')
  const [bankQuestions, setBankQuestions] = useState<AcademicQuestion[]>([])
  const [loadingBank, setLoadingBank] = useState(false)

  const [title, setTitle] = useState('')
  const [schoolClassId, setSchoolClassId] = useState('')
  const [subjectId, setSubjectId] = useState('')
  const [timeDuration, setTimeDuration] = useState('')
  const [fullMarks, setFullMarks] = useState('')
  const [instructions, setInstructions] = useState('')
  const [items, setItems] = useState<CanvasItem[]>([])
  const [headingDraft, setHeadingDraft] = useState('')
  const [addingHeading, setAddingHeading] = useState(false)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    listClasses({ perPage: 500 }).then((result) => setClasses(result.data)).catch(() => setClasses([]))
    listSubjects({ perPage: 200 }).then((result) => setSubjects(result.data)).catch(() => setSubjects([]))
  }, [])

  function loadBank() {
    setLoadingBank(true)

    listAcademicQuestions(
      {
        schoolClassId: filterClassId ? Number(filterClassId) : undefined,
        subjectId: filterSubjectId ? Number(filterSubjectId) : undefined,
        chapterNumber: filterChapterNumber ? Number(filterChapterNumber) : undefined,
        questionType: (filterType || undefined) as AcademicQuestionType | undefined,
        marks: filterMarks ? Number(filterMarks) : undefined,
      },
      token ?? undefined,
    )
      .then((result) => setBankQuestions(result.data))
      .catch(() => setBankQuestions([]))
      .finally(() => setLoadingBank(false))
  }

  function handleDragEnd(event: DragEndEvent) {
    const { active, over } = event
    if (!over) return

    const activeData = active.data.current as { type?: string; question?: AcademicQuestion } | undefined

    if (activeData?.type === 'bank-question' && activeData.question) {
      // Dropped a bank-question card onto the canvas — either its empty
      // drop zone or one of its existing sortable items both report
      // `over`, and either way the question is appended to the end;
      // reordering happens as a separate, later drag (the branch below).
      const isOverCanvas = over.id === 'canvas' || items.some((item) => item.uid === over.id)
      if (!isOverCanvas) return

      setItems((prev) => addQuestionToCanvas(prev, activeData.question as AcademicQuestion))
      return
    }

    setItems((prev) => reorderCanvasItems(prev, String(active.id), String(over.id)))
  }

  function confirmHeading() {
    setItems((prev) => addHeadingToCanvas(prev, headingDraft))
    setHeadingDraft('')
    setAddingHeading(false)
  }

  const computedFullMarks = items.reduce(
    (sum, item) => sum + (item.type === 'question' ? (item.question?.marks ?? 0) : 0),
    0,
  )

  async function handleSave() {
    if (!token || !schoolClassId || !subjectId || items.length === 0) return

    setSaving(true)

    try {
      await createQuestionPaper(
        {
          title,
          school_class_id: Number(schoolClassId),
          subject_id: Number(subjectId),
          time_duration_minutes: timeDuration ? Number(timeDuration) : null,
          full_marks: fullMarks ? Number(fullMarks) : computedFullMarks || null,
          instructions: instructions || null,
          items: items.map((item) =>
            item.type === 'question'
              ? { type: 'question' as const, academic_question_id: item.question?.id }
              : { type: 'heading' as const, heading_text: item.headingText },
          ),
        },
        token,
      )

      showToast({ type: 'success', message: t['teacher.questionBuilder.saveSuccess'] })
      setTitle('')
      setTimeDuration('')
      setFullMarks('')
      setInstructions('')
      setItems([])
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.questionBuilder.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  return (
    <section className="flex flex-col gap-4 px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.questionBuilder.title']}</h1>

      <DndContext onDragEnd={handleDragEnd}>
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div className="flex flex-col gap-3 rounded-md bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-primary-800">{t['teacher.questionBuilder.bankTitle']}</h2>

            <div className="flex flex-wrap gap-2">
              <select className={selectClass} value={filterClassId} onChange={(event) => setFilterClassId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectClass']}</option>
                {classes.map((schoolClass) => (
                  <option key={schoolClass.id} value={schoolClass.id}>
                    {schoolClass.name}
                  </option>
                ))}
              </select>
              <select className={selectClass} value={filterSubjectId} onChange={(event) => setFilterSubjectId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectSubject']}</option>
                {subjects.map((subject) => (
                  <option key={subject.id} value={subject.id}>
                    {subject.name}
                  </option>
                ))}
              </select>
              <input
                type="number"
                min={1}
                placeholder={t['teacher.questionBuilder.chapterNumber']}
                className={`${selectClass} w-32`}
                value={filterChapterNumber}
                onChange={(event) => setFilterChapterNumber(event.target.value)}
              />
              <select className={selectClass} value={filterType} onChange={(event) => setFilterType(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.allTypes']}</option>
                <option value="cq">{t['teacher.questionBuilder.cqLabel']}</option>
                <option value="mcq">{t['teacher.questionBuilder.mcqLabel']}</option>
              </select>
              <input
                type="number"
                min={1}
                placeholder={t['teacher.questionBuilder.marksFilter']}
                className={`${selectClass} w-28`}
                value={filterMarks}
                onChange={(event) => setFilterMarks(event.target.value)}
              />
              <button type="button" onClick={loadBank} disabled={loadingBank} className={buttonPrimary}>
                {t['teacher.questionBuilder.loadQuestions']}
              </button>
            </div>

            <div className="flex flex-col gap-2">
              {loadingBank && <p className="text-sm text-gray-500">{t['teacher.questionBuilder.loading']}</p>}
              {!loadingBank && bankQuestions.length === 0 && (
                <p className="text-sm text-gray-500">{t['teacher.questionBuilder.empty']}</p>
              )}
              {bankQuestions.map((question) => (
                <BankQuestionCard key={question.id} question={question} />
              ))}
            </div>
          </div>

          <div className="flex flex-col gap-3 rounded-md bg-white p-4 shadow-sm">
            <h2 className="text-sm font-semibold text-primary-800">{t['teacher.questionBuilder.paperTitle']}</h2>

            <input
              className={selectClass}
              placeholder={t['teacher.questionBuilder.titleLabel']}
              value={title}
              onChange={(event) => setTitle(event.target.value)}
            />

            <div className="flex gap-2">
              <select className={selectClass} value={schoolClassId} onChange={(event) => setSchoolClassId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectClass']}</option>
                {classes.map((schoolClass) => (
                  <option key={schoolClass.id} value={schoolClass.id}>
                    {schoolClass.name}
                  </option>
                ))}
              </select>
              <select className={selectClass} value={subjectId} onChange={(event) => setSubjectId(event.target.value)}>
                <option value="">{t['teacher.questionBuilder.selectSubject']}</option>
                {subjects.map((subject) => (
                  <option key={subject.id} value={subject.id}>
                    {subject.name}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex gap-2">
              <input
                type="number"
                min={1}
                placeholder={t['teacher.questionBuilder.timeDuration']}
                className={selectClass}
                value={timeDuration}
                onChange={(event) => setTimeDuration(event.target.value)}
              />
              <input
                type="number"
                min={1}
                placeholder={t['teacher.questionBuilder.fullMarks']}
                className={selectClass}
                value={fullMarks}
                onChange={(event) => setFullMarks(event.target.value)}
              />
            </div>

            <textarea
              className={`${selectClass} h-auto py-2`}
              rows={2}
              placeholder={t['teacher.questionBuilder.instructions']}
              value={instructions}
              onChange={(event) => setInstructions(event.target.value)}
            />

            <CanvasDropZone items={items} onRemove={(uid) => setItems((prev) => removeCanvasItem(prev, uid))} />

            {addingHeading ? (
              <div className="flex gap-2">
                <input
                  className={selectClass}
                  placeholder={t['teacher.questionBuilder.headingPlaceholder']}
                  value={headingDraft}
                  onChange={(event) => setHeadingDraft(event.target.value)}
                />
                <button type="button" onClick={confirmHeading} className={buttonPrimary}>
                  {t['teacher.questionBuilder.addHeading']}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setAddingHeading(false)
                    setHeadingDraft('')
                  }}
                  className={buttonNeutral}
                >
                  {t['teacher.questionBuilder.cancelHeading']}
                </button>
              </div>
            ) : (
              <button type="button" onClick={() => setAddingHeading(true)} className={buttonNeutral}>
                {t['teacher.questionBuilder.insertHeading']}
              </button>
            )}

            <button
              type="button"
              onClick={handleSave}
              disabled={saving || !schoolClassId || !subjectId || items.length === 0}
              className={buttonPrimary}
            >
              {saving ? t['teacher.questionBuilder.saving'] : t['teacher.questionBuilder.save']}
            </button>
          </div>
        </div>
      </DndContext>

      <ToastViewport toast={toast} />
    </section>
  )
}
```

- [ ] **Step 6: Wire up the nav item and route**

In `frontend/apps/teacher/src/components/TeacherAppShell.tsx`, find:

```tsx
  { to: '/teacher-leave', label: t['teacher.nav.teacherLeave'], icon: 'work_off' },
```

and insert immediately after it:

```tsx
  { to: '/question-builder', label: t['teacher.nav.questionBuilder'], icon: 'quiz' },
```

In `frontend/apps/teacher/src/App.tsx`, add the import — find:

```tsx
import TeacherLeavePage from './pages/TeacherLeavePage'
```

and insert immediately after it:

```tsx
import QuestionBuilderPage from './pages/QuestionBuilderPage'
```

Then find:

```tsx
              <Route path="teacher-leave" element={<TeacherLeavePage />} />
```

and insert immediately after it:

```tsx
              <Route path="question-builder" element={<QuestionBuilderPage />} />
```

- [ ] **Step 7: Type-check**

Run: `npm run build -w @ttla/app-teacher` from `frontend/`.
Expected: no TypeScript errors.

- [ ] **Step 8: Manually verify**

Start the teacher app's dev server, log in as a teacher, open "Question Builder": pick class/subject/chapter/type/marks filters and click "Load questions" (requires at least one active bank question from Task 4 to exist), drag a bank-question card onto the paper layout, confirm it appears numbered with its marks; click "Insert heading", type text, confirm it appends as a heading divider; drag an item within the layout to reorder it; click the × on an item to remove it; fill in the paper title/class/subject and click Save, confirm the success toast and that the canvas clears.

- [ ] **Step 9: Commit**

```bash
git add package.json package-lock.json apps/teacher/package.json apps/teacher/src/pages/questionBuilderCanvas.ts apps/teacher/src/pages/questionBuilderCanvas.test.ts apps/teacher/src/pages/QuestionBuilderPage.tsx apps/teacher/src/App.tsx apps/teacher/src/components/TeacherAppShell.tsx
git commit -m "Add the teacher portal's drag-and-drop Question Builder page"
```

(Adjust the `package-lock.json`/`package.json` paths above to wherever `npm install` actually updated them — likely the `frontend/` workspace root's lockfile in addition to `apps/teacher/package.json`.)

---

### Task 7: Teacher portal — "My Question Papers" page (list, print, edit, delete)

**Files:**
- Modify: `frontend/apps/teacher/src/pages/questionBuilderCanvas.ts`
- Modify: `frontend/apps/teacher/src/pages/QuestionBuilderPage.tsx`
- Create: `frontend/apps/teacher/src/pages/MyQuestionPapersPage.tsx`
- Modify: `frontend/apps/teacher/src/App.tsx`
- Modify: `frontend/apps/teacher/src/components/TeacherAppShell.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listQuestionPapers`/`getQuestionPaper`/`updateQuestionPaper`/`deleteQuestionPaper`/`type QuestionPaper` (Task 3); Task 6's `QuestionBuilderPage.tsx` and `questionBuilderCanvas.ts`, extended here for edit mode.
- Produces: route `/my-question-papers`; route `/question-builder/:paperId` (edit mode of Task 6's builder, same component).

- [ ] **Step 1: Narrow `CanvasItem`'s question type so a loaded paper's items need no reshaping**

In `frontend/apps/teacher/src/pages/questionBuilderCanvas.ts`, find:

```ts
import type { AcademicQuestion } from '@ttla/api-client'

export interface CanvasItem {
  uid: string
  type: 'question' | 'heading'
  question?: AcademicQuestion
  headingText?: string
}

export function addQuestionToCanvas(items: CanvasItem[], question: AcademicQuestion): CanvasItem[] {
```

Replace with:

```ts
import type { AcademicQuestionType } from '@ttla/api-client'

// Deliberately narrower than the full AcademicQuestion type: a canvas
// item only ever needs enough to render itself and to reference the
// bank question by id — and this exact narrower shape is also what
// QuestionPaperItemResource's nested `academic_question` already returns
// from the backend (see QuestionPaperItemResource.php), so loading an
// existing paper for editing (added in this task) needs no reshaping,
// and a full AcademicQuestion from the bank search still satisfies this
// narrower type structurally.
export interface CanvasQuestionSummary {
  id: number
  question_type: AcademicQuestionType
  question_text: string
  options: string[] | null
  marks: number
  chapter_number: number
  chapter_name: string
}

export interface CanvasItem {
  uid: string
  type: 'question' | 'heading'
  question?: CanvasQuestionSummary
  headingText?: string
}

export function addQuestionToCanvas(items: CanvasItem[], question: CanvasQuestionSummary): CanvasItem[] {
```

`questionBuilderCanvas.test.ts` needs no change — its `makeQuestion()` fixture returns a full `AcademicQuestion`-shaped object, which still satisfies the narrower `CanvasQuestionSummary` parameter structurally (extra fields are harmless).

- [ ] **Step 2: Run the existing canvas tests to confirm the narrowing didn't break anything**

Run: `npx vitest run src/pages/questionBuilderCanvas.test.ts` (from `frontend/apps/teacher/`)
Expected: all tests still PASS.

- [ ] **Step 3: Add edit-mode support to the builder page**

In `frontend/apps/teacher/src/pages/QuestionBuilderPage.tsx`, find the import block:

```tsx
import { useEffect, useState } from 'react'
import {
  ApiError,
  createQuestionPaper,
  listAcademicQuestions,
  listClasses,
  listSubjects,
  type AcademicQuestion,
  type AcademicQuestionType,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
```

Replace with:

```tsx
import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  ApiError,
  createQuestionPaper,
  getQuestionPaper,
  listAcademicQuestions,
  listClasses,
  listSubjects,
  updateQuestionPaper,
  type AcademicQuestion,
  type AcademicQuestionType,
  type SchoolClass,
  type Subject,
} from '@ttla/api-client'
```

Find:

```tsx
export default function QuestionBuilderPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()
```

Replace with:

```tsx
export default function QuestionBuilderPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()
  const { paperId } = useParams<{ paperId?: string }>()
  const navigate = useNavigate()
  const isEditing = Boolean(paperId)
  const [loadingPaper, setLoadingPaper] = useState(isEditing)
```

Find:

```tsx
  useEffect(() => {
    listClasses({ perPage: 500 }).then((result) => setClasses(result.data)).catch(() => setClasses([]))
    listSubjects({ perPage: 200 }).then((result) => setSubjects(result.data)).catch(() => setSubjects([]))
  }, [])
```

Replace with:

```tsx
  useEffect(() => {
    listClasses({ perPage: 500 }).then((result) => setClasses(result.data)).catch(() => setClasses([]))
    listSubjects({ perPage: 200 }).then((result) => setSubjects(result.data)).catch(() => setSubjects([]))
  }, [])

  // Edit mode: populate every field (including the canvas) from the
  // existing paper. QuestionPaperItemResource's nested academic_question
  // already matches CanvasQuestionSummary exactly — no reshaping needed
  // beyond wrapping each item with a fresh client-side uid.
  useEffect(() => {
    if (!token || !paperId) return

    getQuestionPaper(Number(paperId), token)
      .then((result) => {
        const paper = result.data
        setTitle(paper.title)
        setSchoolClassId(String(paper.school_class_id))
        setSubjectId(String(paper.subject_id))
        setTimeDuration(paper.time_duration_minutes !== null ? String(paper.time_duration_minutes) : '')
        setFullMarks(paper.full_marks !== null ? String(paper.full_marks) : '')
        setInstructions(paper.instructions ?? '')
        setItems(
          (paper.items ?? []).map((item) => ({
            uid: crypto.randomUUID(),
            type: item.item_type,
            question: item.academic_question ?? undefined,
            headingText: item.heading_text ?? undefined,
          })),
        )
      })
      .catch(() => showToast({ type: 'error', message: t['teacher.questionBuilder.saveError'] }))
      .finally(() => setLoadingPaper(false))
  }, [token, paperId])
```

Find the whole `handleSave` function:

```tsx
  async function handleSave() {
    if (!token || !schoolClassId || !subjectId || items.length === 0) return

    setSaving(true)

    try {
      await createQuestionPaper(
        {
          title,
          school_class_id: Number(schoolClassId),
          subject_id: Number(subjectId),
          time_duration_minutes: timeDuration ? Number(timeDuration) : null,
          full_marks: fullMarks ? Number(fullMarks) : computedFullMarks || null,
          instructions: instructions || null,
          items: items.map((item) =>
            item.type === 'question'
              ? { type: 'question' as const, academic_question_id: item.question?.id }
              : { type: 'heading' as const, heading_text: item.headingText },
          ),
        },
        token,
      )

      showToast({ type: 'success', message: t['teacher.questionBuilder.saveSuccess'] })
      setTitle('')
      setTimeDuration('')
      setFullMarks('')
      setInstructions('')
      setItems([])
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.questionBuilder.saveError'] })
    } finally {
      setSaving(false)
    }
  }
```

Replace with:

```tsx
  async function handleSave() {
    if (!token || !schoolClassId || !subjectId || items.length === 0) return

    setSaving(true)

    const payload = {
      title,
      school_class_id: Number(schoolClassId),
      subject_id: Number(subjectId),
      time_duration_minutes: timeDuration ? Number(timeDuration) : null,
      full_marks: fullMarks ? Number(fullMarks) : computedFullMarks || null,
      instructions: instructions || null,
      items: items.map((item) =>
        item.type === 'question'
          ? { type: 'question' as const, academic_question_id: item.question?.id }
          : { type: 'heading' as const, heading_text: item.headingText },
      ),
    }

    try {
      if (isEditing && paperId) {
        await updateQuestionPaper(Number(paperId), payload, token)
        showToast({ type: 'success', message: t['teacher.questionBuilder.saveSuccess'] })
        navigate('/my-question-papers')
        return
      }

      await createQuestionPaper(payload, token)

      showToast({ type: 'success', message: t['teacher.questionBuilder.saveSuccess'] })
      setTitle('')
      setTimeDuration('')
      setFullMarks('')
      setInstructions('')
      setItems([])
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.questionBuilder.saveError'] })
    } finally {
      setSaving(false)
    }
  }
```

Find:

```tsx
  return (
    <section className="flex flex-col gap-4 px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">{t['teacher.questionBuilder.title']}</h1>

      <DndContext onDragEnd={handleDragEnd}>
```

Replace with:

```tsx
  if (loadingPaper) {
    return <p className="px-4 py-6 text-sm text-gray-500 lg:px-6">{t['teacher.questionBuilder.loading']}</p>
  }

  return (
    <section className="flex flex-col gap-4 px-4 py-6 lg:px-6">
      <h1 className="text-2xl font-bold text-primary-700">
        {isEditing ? t['teacher.questionBuilder.editTitle'] : t['teacher.questionBuilder.title']}
      </h1>

      <DndContext onDragEnd={handleDragEnd}>
```

Find the Save button:

```tsx
            <button
              type="button"
              onClick={handleSave}
              disabled={saving || !schoolClassId || !subjectId || items.length === 0}
              className={buttonPrimary}
            >
              {saving ? t['teacher.questionBuilder.saving'] : t['teacher.questionBuilder.save']}
            </button>
```

Replace with:

```tsx
            <button
              type="button"
              onClick={handleSave}
              disabled={saving || !schoolClassId || !subjectId || items.length === 0}
              className={buttonPrimary}
            >
              {saving
                ? isEditing
                  ? t['teacher.questionBuilder.updating']
                  : t['teacher.questionBuilder.saving']
                : isEditing
                  ? t['teacher.questionBuilder.update']
                  : t['teacher.questionBuilder.save']}
            </button>
```

- [ ] **Step 4: Add the three new i18n keys this edit mode needs**

Add to `frontend/packages/i18n/src/common/en.json`:

```json
  "teacher.questionBuilder.editTitle": "Edit Question Paper",
  "teacher.questionBuilder.update": "Update paper",
  "teacher.questionBuilder.updating": "Updating...",
```

Add to `frontend/packages/i18n/src/common/bn.json`:

```json
  "teacher.questionBuilder.editTitle": "প্রশ্নপত্র সম্পাদনা করুন",
  "teacher.questionBuilder.update": "প্রশ্নপত্র হালনাগাদ করুন",
  "teacher.questionBuilder.updating": "হালনাগাদ হচ্ছে...",
```

- [ ] **Step 5: Create the "My Question Papers" page**

```tsx
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { ApiError, deleteQuestionPaper, listQuestionPapers, type QuestionPaper } from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

// Deliberately duplicated in the management app's QuestionPapersPage —
// see this plan's Global Constraints on per-app duplication of shared
// rendering, not a cross-app packages/ui component.
function PrintableQuestionPaper({ paper }: { paper: QuestionPaper }) {
  const items = paper.items ?? []
  let questionNumber = 0

  return (
    <div className="mx-auto max-w-3xl bg-white p-8 text-gray-900 print:p-0">
      <div className="text-center">
        <h1 className="text-lg font-bold">{paper.title}</h1>
        <p className="mt-1 text-sm">
          {paper.school_class?.name ?? ''} — {paper.subject?.name ?? ''}
        </p>
        <div className="mt-2 flex justify-center gap-6 text-sm">
          {paper.time_duration_minutes !== null && <span>Time: {paper.time_duration_minutes} min</span>}
          {paper.full_marks !== null && <span>Full Marks: {paper.full_marks}</span>}
        </div>
        {paper.instructions && <p className="mt-2 text-sm italic">{paper.instructions}</p>}
      </div>

      <div className="mt-6 flex flex-col gap-3">
        {items.map((item) => {
          if (item.item_type === 'heading') {
            return (
              <h2 key={item.id} className="mt-4 text-base font-semibold underline">
                {item.heading_text}
              </h2>
            )
          }

          questionNumber += 1
          const question = item.academic_question

          return (
            <div key={item.id} className="flex justify-between gap-4">
              <div className="flex-1">
                <p className="whitespace-pre-wrap">
                  {questionNumber}. {question?.question_text}
                </p>
                {question?.question_type === 'mcq' && question.options && (
                  <ol className="mt-1 ml-5 list-none">
                    {question.options.map((option, index) => (
                      <li key={index}>
                        {String.fromCharCode(0x0995 + index)}) {option}
                      </li>
                    ))}
                  </ol>
                )}
              </div>
              <span className="shrink-0 text-sm">{question?.marks}</span>
            </div>
          )
        })}
      </div>
    </div>
  )
}

export default function MyQuestionPapersPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()
  const [papers, setPapers] = useState<QuestionPaper[]>([])
  const [loading, setLoading] = useState(true)
  const [printingPaper, setPrintingPaper] = useState<QuestionPaper | null>(null)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  function loadPapers() {
    if (!token) return

    setLoading(true)
    listQuestionPapers(token)
      .then((result) => setPapers(result.data))
      .catch(() => setPapers([]))
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    loadPapers()
  }, [token])

  function handlePrint(paper: QuestionPaper) {
    setPrintingPaper(paper)
    requestAnimationFrame(() => window.print())
  }

  async function handleDelete(paper: QuestionPaper) {
    if (!token || !window.confirm(t['teacher.myQuestionPapers.deleteConfirm'])) return

    setDeletingId(paper.id)

    try {
      await deleteQuestionPaper(paper.id, token)
      showToast({ type: 'success', message: t['teacher.myQuestionPapers.deleteSuccess'] })
      loadPapers()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.myQuestionPapers.deleteError'] })
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <div>
      <style>{`
        @media print {
          @page { size: A4; margin: 12mm; }
          body { background: white !important; }
        }
      `}</style>

      <div className="flex items-center justify-between print:hidden">
        <h1 className="text-xl font-semibold text-gray-900">{t['teacher.myQuestionPapers.title']}</h1>
        <Link
          to="/question-builder"
          className="inline-flex items-center gap-1.5 rounded-md bg-primary-500 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-400"
        >
          <span className="material-symbols-outlined !text-[18px] leading-none">add</span>
          {t['teacher.myQuestionPapers.createNew']}
        </Link>
      </div>

      {loading && <p className="mt-4 text-sm text-gray-500 print:hidden">{t['teacher.myQuestionPapers.loading']}</p>}

      {!loading && papers.length === 0 && (
        <p className="mt-4 rounded-md bg-white p-5 text-sm text-gray-500 shadow-sm print:hidden">
          {t['teacher.myQuestionPapers.empty']}
        </p>
      )}

      {!loading && papers.length > 0 && !printingPaper && (
        <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm print:hidden">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colTitle']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colClass']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colSubject']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colCreated']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.myQuestionPapers.colActions']}</th>
              </tr>
            </thead>
            <tbody>
              {papers.map((paper) => (
                <tr key={paper.id} className="border-t border-gray-100">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.title}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.school_class?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{paper.subject?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{paper.created_at.slice(0, 10)}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    <div className="flex items-center gap-3">
                      <button
                        type="button"
                        onClick={() => handlePrint(paper)}
                        aria-label={t['teacher.myQuestionPapers.print']}
                        title={t['teacher.myQuestionPapers.print']}
                        className="cursor-pointer text-gray-500 transition-colors hover:text-primary-500"
                      >
                        <span className="material-symbols-outlined !text-[18px]">print</span>
                      </button>
                      {paper.can_edit && (
                        <Link
                          to={`/question-builder/${paper.id}`}
                          aria-label={t['teacher.myQuestionPapers.edit']}
                          title={t['teacher.myQuestionPapers.edit']}
                          className="text-gray-500 transition-colors hover:text-primary-500"
                        >
                          <span className="material-symbols-outlined !text-[18px]">edit</span>
                        </Link>
                      )}
                      {paper.can_delete && (
                        <button
                          type="button"
                          disabled={deletingId === paper.id}
                          onClick={() => handleDelete(paper)}
                          aria-label={t['teacher.myQuestionPapers.delete']}
                          title={t['teacher.myQuestionPapers.delete']}
                          className="cursor-pointer text-danger-500 transition-colors hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                          <span className="material-symbols-outlined !text-[18px]">
                            {deletingId === paper.id ? 'hourglass_top' : 'delete'}
                          </span>
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {printingPaper && (
        <>
          <button
            type="button"
            onClick={() => setPrintingPaper(null)}
            className="mt-4 cursor-pointer rounded-md border border-gray-200 px-4 py-2 text-sm text-gray-600 hover:border-primary-500 print:hidden"
          >
            ×
          </button>
          <PrintableQuestionPaper paper={printingPaper} />
        </>
      )}

      <ToastViewport toast={toast} />
    </div>
  )
}
```

- [ ] **Step 6: Wire up the nav item and routes**

In `frontend/apps/teacher/src/components/TeacherAppShell.tsx`, find:

```tsx
  { to: '/question-builder', label: t['teacher.nav.questionBuilder'], icon: 'quiz' },
```

and insert immediately after it:

```tsx
  { to: '/my-question-papers', label: t['teacher.nav.myQuestionPapers'], icon: 'description' },
```

In `frontend/apps/teacher/src/App.tsx`, add the import — find:

```tsx
import QuestionBuilderPage from './pages/QuestionBuilderPage'
```

and insert immediately after it:

```tsx
import MyQuestionPapersPage from './pages/MyQuestionPapersPage'
```

Find:

```tsx
              <Route path="question-builder" element={<QuestionBuilderPage />} />
```

and replace with (adding the edit sub-route and the new list route):

```tsx
              <Route path="question-builder" element={<QuestionBuilderPage />} />
              <Route path="question-builder/:paperId" element={<QuestionBuilderPage />} />
              <Route path="my-question-papers" element={<MyQuestionPapersPage />} />
```

- [ ] **Step 7: Type-check**

Run: `npm run build -w @ttla/app-teacher` from `frontend/`.
Expected: no TypeScript errors.

- [ ] **Step 8: Manually verify**

As the teacher who created papers in Task 6, open "My Question Papers": confirm your own papers show Print, Edit, and Delete; log in as a colleague teacher at the same school and confirm their papers show Print only (no Edit/Delete icons); click Edit on your own paper, confirm the builder loads with the existing title/class/subject/items pre-filled, change something, save, and confirm it returns to the list with the change reflected; click Delete, confirm the confirmation dialog, and confirm the paper disappears from the list.

- [ ] **Step 9: Commit**

```bash
git add apps/teacher/src/pages/questionBuilderCanvas.ts apps/teacher/src/pages/QuestionBuilderPage.tsx apps/teacher/src/pages/MyQuestionPapersPage.tsx apps/teacher/src/App.tsx apps/teacher/src/components/TeacherAppShell.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add the teacher portal's My Question Papers page with edit and delete"
```

---

## Self-Review Notes

- **Spec coverage:** every requirement in `docs/superpowers/specs/2026-11-25-question-builder-design.md` has a task — the global bank (Tasks 1, 4), teacher builder with drag-and-drop and headings (Task 6), save/print/edit/delete and colleague/admin visibility rules (Tasks 2, 5, 7), national_admin-only bank CRUD (Tasks 1, 4), school_admin print-only (Tasks 2, 5).
- **Type consistency checked:** `AcademicQuestionResource`/`QuestionPaperResource`/`QuestionPaperItemResource` field names (Tasks 1-2) match the TypeScript types added in Task 3 exactly (`school_class_id`, `can_edit`, `can_delete`, nested `academic_question` shape) — Task 7's narrowing of `CanvasItem.question` to `CanvasQuestionSummary` was introduced specifically so the server's nested shape needs no reshaping when loading a paper for editing.
- **No placeholders:** every step above contains complete, runnable code — no "add validation" or "similar to Task N" shortcuts.
