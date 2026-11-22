# Teacher Chapter Material Uploads Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher upload PDF "chapter materials" for a section+subject they are actually assigned to teach, so the student portal's "Class Material" menu is populated by real teacher uploads instead of admin-only seeding.

**Architecture:** Backend authorization moves from a flat permission check (and an unenforced, homeroom-based scoping heuristic) to a real `SubjectTeacherAssignment`-matched check, with an explicit `section_id` on every teacher upload. Two new teacher-only endpoints expose "what can I upload for" and "what have I uploaded." The teacher portal gets one new page using the exact cascading-picker pattern already used by Marks Entry.

**Tech Stack:** Laravel 11 (PHP 8.5) backend, React + TypeScript (Vite) frontend, PHPUnit, Vitest.

**Spec:** `docs/superpowers/specs/2026-11-22-teacher-chapter-material-uploads-design.md`

## Global Constraints

- PHP: curly braces always, constructor property promotion, explicit return types — match the style already in every file this plan touches.
- After any PHP change, run `vendor/bin/pint --dirty --format agent` from `backend/` before considering a task done.
- Backend tests: `php artisan test --compact` (optionally `--filter=...` for the narrow set while iterating), run from `backend/`.
- Frontend: after any `.ts`/`.tsx` change, run `npx tsc --noEmit` from the relevant app/package directory (`frontend/apps/teacher`, `frontend/packages/api-client`) — this repo currently has **zero** component-level tests for `apps/*` pages (only `packages/*` get Vitest unit tests), so don't invent a new page-test file; verify page behavior by running the dev server instead.
- Never rename or restructure files/behavior not mentioned in this plan (e.g. do not touch `ChapterMaterial::uploadedBy()`'s `User::class` relation — it's a separate, pre-existing issue, out of scope).
- Commit after each task with `git add <files touched by this task>` (never a bare `git add -A`).

---

### Task 1: Extract `SubjectTeacherAssignment::forTeacher()` and reuse it in `AcademicExamMarkController`

**Files:**
- Modify: `backend/app/Models/SubjectTeacherAssignment.php`
- Modify: `backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php:46-49`
- Test: `backend/tests/Feature/AcademicExamMarkControllerTest.php` (existing tests, run only — no new test needed, this is a behavior-preserving refactor)
- Test: Create `backend/tests/Unit/SubjectTeacherAssignmentTest.php` (new, direct unit test for the helper)

**Interfaces:**
- Produces: `SubjectTeacherAssignment::forTeacher(int $teacherId): \Illuminate\Database\Eloquent\Collection<int, SubjectTeacherAssignment>` — every assignment row for that teacher, with `section.shift.classVersion.schoolClass`, `section.shift.academicYear`, and `subject` eager-loaded. Used by Task 3's new `ChapterMaterialController::myAssignments()`.

- [ ] **Step 1: Add the `forTeacher()` helper to the model**

In `backend/app/Models/SubjectTeacherAssignment.php`, add this method inside the class (after the `teacher()` relation, before the closing `}`):

```php
    /**
     * Every (section, subject) this teacher is explicitly assigned to,
     * with section/subject/class context eager-loaded for a picker.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function forTeacher(int $teacherId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('teacher_id', $teacherId)
            ->with(['section.shift.classVersion.schoolClass', 'section.shift.academicYear', 'subject'])
            ->get();
    }
```

- [ ] **Step 2: Use it from `AcademicExamMarkController::myAssignments()`**

In `backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php`, find this block (around line 46-49):

```php
        $assignmentRows = SubjectTeacherAssignment::query()
            ->with(['section.shift.classVersion.schoolClass', 'section.shift.academicYear', 'subject'])
            ->where('teacher_id', $teacher->id)
            ->get();
```

Replace it with:

```php
        $assignmentRows = SubjectTeacherAssignment::forTeacher($teacher->id);
```

- [ ] **Step 3: Write a direct unit test for the helper**

Create `backend/tests/Unit/SubjectTeacherAssignmentTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectTeacherAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_teacher_returns_only_that_teachers_own_assignments_with_relations_loaded(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Section A']);
        $subject = Subject::create(['name' => 'Mathematics']);

        $teacher = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);
        $otherTeacher = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);

        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'school_id' => $school->id, 'district_id' => $school->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $otherTeacher->id, 'school_id' => $school->id, 'district_id' => $school->district_id]);

        $rows = SubjectTeacherAssignment::forTeacher($teacher->id);

        $this->assertCount(1, $rows);
        $this->assertSame($teacher->id, $rows->first()->teacher_id);
        $this->assertTrue($rows->first()->relationLoaded('section'));
        $this->assertTrue($rows->first()->relationLoaded('subject'));
        $this->assertSame('Section A', $rows->first()->section->name);
        $this->assertSame('Mathematics', $rows->first()->subject->name);
    }
}
```

Run: `cd backend && php artisan test --filter=SubjectTeacherAssignmentTest --compact`
Expected: PASS.

- [ ] **Step 4: Run the existing regression tests for the refactored controller**

Run: `cd backend && php artisan test --filter=AcademicExamMarkControllerTest --compact`
Expected: PASS (same count as before this change — this step is purely a refactor, so `test_my_assignments_includes_every_subject_for_a_class_teachers_section` and `test_my_assignments_includes_only_the_assigned_subject_for_a_subject_teacher` must still pass unchanged).

- [ ] **Step 5: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add app/Models/SubjectTeacherAssignment.php app/Http/Controllers/Api/V1/AcademicExamMarkController.php tests/Unit/SubjectTeacherAssignmentTest.php
git commit -m "Extract SubjectTeacherAssignment::forTeacher() helper, reuse in AcademicExamMarkController"
```

---

### Task 2: Real assignment-based authorization for creating/updating/deleting chapter materials

**Files:**
- Modify: `backend/app/Http/Requests/Api/V1/StoreChapterMaterialRequest.php`
- Modify: `backend/app/Policies/ChapterMaterialPolicy.php`
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php:116-188` (`store()` and `resolveUploaderScope()`)
- Modify: `backend/tests/Feature/ChapterMaterialControllerTest.php` (rewrite 3 tests that assert the old, now-removed class-teacher heuristic; add new tests for assignment-matched authorization and upload ownership)

**Interfaces:**
- Consumes: nothing new from other tasks.
- Produces: `ChapterMaterialPolicy::create(Authenticatable $user, Chapter $chapter, ?int $sectionId = null): bool` — always called with a resolved `Chapter`; `update()`/`delete()` unchanged signature, now ownership-checked for `TeacherUser`. `ChapterMaterialController::resolveUploaderScope(mixed $actor, Chapter $chapter, ?int $sectionId): array` — new third parameter, no longer guesses.

- [ ] **Step 1: Add `section_id` to the store request, simplify its `authorize()`**

In `backend/app/Http/Requests/Api/V1/StoreChapterMaterialRequest.php`, add the import:

```php
use App\Models\TeacherUser;
```

Replace the `authorize()` method:

```php
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('chapter_materials.create') ?? false;
    }
```

Replace the `rules()` method's array to add `section_id` right after `chapter_id`:

```php
    public function rules(): array
    {
        return [
            'chapter_id' => ['required', 'integer', Rule::exists('chapters', 'id')->withoutTrashed()],
            'section_id' => [
                Rule::requiredIf(fn () => $this->user() instanceof TeacherUser),
                'nullable', 'integer', Rule::exists('sections', 'id'),
            ],
            'type' => ['required', 'string', Rule::in(['primary', 'supplementary'])],
            'title' => ['required_if:type,supplementary', 'nullable', 'string', 'max:255'],
            'order_index' => ['nullable', 'integer', 'min:0', 'max:255'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:'.config('content.max_pdf_size_kb')],
        ];
    }
```

- [ ] **Step 2: Rewrite `ChapterMaterialPolicy`**

Replace the full contents of `backend/app/Policies/ChapterMaterialPolicy.php` with:

```php
<?php

namespace App\Policies;

use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\Section;
use App\Models\StudentUser;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class ChapterMaterialPolicy
{
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('chapter_materials.view');
    }

    /**
     * Gates show() AND the downloadLink() action — a student must match
     * this material's own full scope tuple exactly, mirroring
     * ChapterMaterialController::index()'s own filter (including: an
     * unscoped material is NOT visible to a student either — it was
     * never actually verified to belong to their class, so it isn't
     * shown as a "visible to everyone" fallback the way it is for a
     * guest/non-student actor). Without this, a student could bypass
     * that listing filter by calling downloadLink() directly with a
     * material id from a different section (e.g. guessed or shared by a
     * classmate). A non-student actor (teacher/admin) with the flat
     * permission is unrestricted, same as before this scoping existed.
     */
    public function view(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        if (! $user->hasPermission('chapter_materials.view')) {
            return false;
        }

        if (! $user instanceof StudentUser) {
            return true;
        }

        $profile = $user->profile;

        if ($profile === null || $profile->section_id === null) {
            return false;
        }

        return $chapterMaterial->school_id === $profile->school_id
            && $chapterMaterial->district_id === $profile->district_id
            && $chapterMaterial->shift_id === $profile->shift_id
            && $chapterMaterial->section_id === $profile->section_id
            && $chapterMaterial->class_id === $profile->class_id
            && $chapterMaterial->class_version_id === $profile->class_version_id;
    }

    /**
     * Staff (any non-TeacherUser actor) with the flat permission is
     * unrestricted, same as before. A TeacherUser additionally needs a
     * SubjectTeacherAssignment row for (sectionId, chapter's own subject,
     * this teacher) — the section's homeroom class_teacher_id grants no
     * extra authority here; this is specifically about subject content,
     * not homeroom-level data. Always called with a resolved Chapter —
     * see ChapterMaterialController::store(), which is the only caller.
     */
    public function create(Authenticatable $user, Chapter $chapter, ?int $sectionId = null): bool
    {
        if (! $user->hasPermission('chapter_materials.create')) {
            return false;
        }

        if (! $user instanceof TeacherUser) {
            return true;
        }

        if (! $sectionId) {
            return false;
        }

        $section = Section::with('shift')->find($sectionId);

        return $section !== null
            && $section->shift->class_version_id === $chapter->book->class_version_id
            && SubjectTeacherAssignment::where('section_id', $sectionId)
                ->where('subject_id', $chapter->book->subject_id)
                ->where('teacher_id', $user->id)
                ->exists();
    }

    /**
     * Staff with the flat permission retain full override. A TeacherUser
     * may only touch material they themselves uploaded.
     */
    public function update(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        if (! $user->hasPermission('chapter_materials.update')) {
            return false;
        }

        return ! $user instanceof TeacherUser || $chapterMaterial->uploaded_by_id === $user->id;
    }

    public function delete(Authenticatable $user, ChapterMaterial $chapterMaterial): bool
    {
        if (! $user->hasPermission('chapter_materials.delete')) {
            return false;
        }

        return ! $user instanceof TeacherUser || $chapterMaterial->uploaded_by_id === $user->id;
    }
}
```

- [ ] **Step 3: Update `ChapterMaterialController::store()` and `resolveUploaderScope()`**

In `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`, replace the `store()` method:

```php
    public function store(StoreChapterMaterialRequest $request): JsonResponse
    {
        $chapter = Chapter::with('book')->findOrFail($request->integer('chapter_id'));
        $sectionId = $request->integer('section_id') ?: null;

        $this->authorize('create', [ChapterMaterial::class, $chapter, $sectionId]);

        $disk = config('content.pdf_disk');
        $file = $request->file('pdf');
        $path = $file->storeAs("chapter-materials/{$chapter->id}", Str::uuid().'.pdf', $disk);

        $material = ChapterMaterial::create([
            'chapter_id' => $chapter->id,
            ...$this->resolveUploaderScope($request->user(), $chapter, $sectionId),
            'type' => $request->input('type'),
            'title' => $request->input('title'),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'order_index' => $request->input('order_index'),
        ]);

        return (new ChapterMaterialResource($material->load('chapter')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
```

Then replace the `resolveUploaderScope()` method (and its docblock):

```php
    /**
     * [school_id, district_id, shift_id, class_version_id, class_id,
     * section_id] — all null unless the uploader is a TeacherUser who
     * supplied a section_id (already authorized against a matching
     * SubjectTeacherAssignment in ChapterMaterialPolicy::create() before
     * this runs). A school_admin/national_admin upload leaves the
     * material unscoped, same as before this scoping existed.
     *
     * @return array{school_id: ?int, district_id: ?int, shift_id: ?int, class_version_id: ?int, class_id: ?int, section_id: ?int}
     */
    private function resolveUploaderScope(mixed $actor, Chapter $chapter, ?int $sectionId): array
    {
        $empty = ['school_id' => null, 'district_id' => null, 'shift_id' => null, 'class_version_id' => null, 'class_id' => null, 'section_id' => null];

        if (! $actor instanceof TeacherUser || $sectionId === null) {
            return $empty;
        }

        $section = Section::with('shift.classVersion')->findOrFail($sectionId);
        $shift = $section->shift;

        return [
            'school_id' => $section->school_id,
            'district_id' => $section->district_id,
            'shift_id' => $shift->id,
            'class_version_id' => $shift->class_version_id,
            'class_id' => $shift->classVersion->class_id,
            'section_id' => $section->id,
        ];
    }
```

(`$chapter` parameter is now unused inside `resolveUploaderScope()` itself, but keep it — it documents the caller's contract and avoids a signature that silently works for any chapter. The `@phpstan-ignore` is not needed; PHP won't warn about an unused parameter.)

- [ ] **Step 4: Rewrite the 3 tests that relied on the old class-teacher heuristic**

In `backend/tests/Feature/ChapterMaterialControllerTest.php`, replace `test_store_scopes_the_material_to_the_uploading_teachers_own_matching_section` with:

```php
    public function test_store_scopes_the_material_to_the_teachers_assigned_section(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'pdf' => $file])
            ->assertCreated()
            ->assertJsonPath('data.section_id', $section->id)
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('data.class_version_id', $classVersionId)
            ->assertJsonPath('data.school_id', $this->schoolA->id)
            ->assertJsonPath('data.district_id', $this->schoolA->district_id);
    }
```

Replace `test_store_leaves_scope_null_when_the_teacher_has_no_matching_section` with three tests:

```php
    public function test_store_requires_section_id_for_a_teacher_actor(): void
    {
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'type' => 'primary', 'pdf' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('section_id');
    }

    public function test_store_is_forbidden_when_the_teacher_has_no_subject_assignment_for_the_section(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'pdf' => $file])
            ->assertForbidden();
    }

    public function test_store_is_forbidden_when_the_assignment_is_for_a_different_subject(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $otherSubject = Subject::create(['name' => 'English']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $otherSubject->id, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'pdf' => $file])
            ->assertForbidden();
    }
```

Replace `test_a_student_outside_the_materials_section_cannot_see_or_download_it`'s upload setup (keep everything from `$outsideStudent = ...` onward unchanged) with:

```php
    public function test_a_student_outside_the_materials_section_cannot_see_or_download_it(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $classId = $this->chapterA->book->classVersion->class_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $ownSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $otherSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);
        SubjectTeacherAssignment::create(['section_id' => $ownSection->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $materialId = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $ownSection->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        $outsideStudent = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create(['user_id' => $outsideStudent->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'section_id' => $otherSection->id, 'shift_id' => $shift->id, 'class_version_id' => $classVersionId, 'class_id' => $classId]);

        $this->actingAs($outsideStudent, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($outsideStudent, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$materialId}/download-link")
            ->assertForbidden();

        $insideStudent = $this->studentActorWithPermissions(['chapter_materials.view'], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create(['user_id' => $insideStudent->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'section_id' => $ownSection->id, 'shift_id' => $shift->id, 'class_version_id' => $classVersionId, 'class_id' => $classId]);

        $this->actingAs($insideStudent, 'sanctum')
            ->getJson("/api/v1/chapter-materials?chapter_id={$this->chapterA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($insideStudent, 'sanctum')
            ->postJson("/api/v1/chapter-materials/{$materialId}/download-link")
            ->assertOk();
    }
```

Replace `test_mine_returns_only_materials_matching_the_students_own_full_scope_tuple`'s upload setup similarly (keep the rest of the method body — the legacy-material creation and both student assertions — unchanged):

```php
    public function test_mine_returns_only_materials_matching_the_students_own_full_scope_tuple(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $classId = $this->chapterA->book->classVersion->class_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $ownSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        $otherSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section B']);
        SubjectTeacherAssignment::create(['section_id' => $ownSection->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');
        $scopedMaterialId = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $ownSection->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        // A legacy/unscoped material (section_id null) must NOT appear via
        // /mine, same as index() — neither one falls back to "visible to
        // everyone" for a logged-in student.
        ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'supplementary',
            'title' => 'Legacy Material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 2,
        ]);

        $studentInSection = $this->studentActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create([
            'user_id' => $studentInSection->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'section_id' => $ownSection->id,
            'shift_id' => $shift->id,
            'class_version_id' => $classVersionId,
            'class_id' => $classId,
        ]);

        $this->actingAs($studentInSection, 'sanctum')
            ->getJson('/api/v1/chapter-materials/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $scopedMaterialId);

        $studentInOtherSection = $this->studentActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        StudentProfile::create([
            'user_id' => $studentInOtherSection->id,
            'school_id' => $this->schoolA->id,
            'district_id' => $this->schoolA->district_id,
            'section_id' => $otherSection->id,
            'shift_id' => $shift->id,
            'class_version_id' => $classVersionId,
            'class_id' => $classId,
        ]);

        $this->actingAs($studentInOtherSection, 'sanctum')
            ->getJson('/api/v1/chapter-materials/mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
```

Add `use App\Models\SubjectTeacherAssignment;` to this test file's imports (alongside the existing `use App\Models\Subject;`, already present).

- [ ] **Step 5: Add ownership tests for update/delete**

Add these two new test methods to the same file:

```php
    public function test_teacher_can_update_their_own_uploaded_material(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create', 'chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$id}", ['order_index' => 3])
            ->assertOk();
    }

    public function test_teacher_cannot_update_another_teachers_uploaded_material(): void
    {
        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'primary',
            'title' => 'Someone elses material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
            'uploaded_by_id' => $otherTeacher->id,
        ]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.update'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->patchJson("/api/v1/chapter-materials/{$material->id}", ['title' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_teacher_cannot_delete_another_teachers_uploaded_material(): void
    {
        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $material = ChapterMaterial::create([
            'chapter_id' => $this->chapterA->id,
            'type' => 'primary',
            'title' => 'Someone elses material',
            'original_filename' => 'notes.pdf',
            'stored_path' => 'demo/notes.pdf',
            'disk' => 'local',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'order_index' => 1,
            'uploaded_by_id' => $otherTeacher->id,
        ]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.delete'], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->deleteJson("/api/v1/chapter-materials/{$material->id}")
            ->assertForbidden();
    }
```

- [ ] **Step 6: Run the full test file, then the full suite**

Run: `cd backend && php artisan test --filter=ChapterMaterialControllerTest --compact`
Expected: PASS, all tests (old unaffected ones + rewritten ones + 5 new ones).

Run: `cd backend && php artisan test --compact`
Expected: PASS — this confirms nothing else in the suite depended on the old heuristic or the old `ChapterMaterialPolicy::create()` signature.

- [ ] **Step 7: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/Api/V1/StoreChapterMaterialRequest.php app/Policies/ChapterMaterialPolicy.php app/Http/Controllers/Api/V1/ChapterMaterialController.php tests/Feature/ChapterMaterialControllerTest.php
git commit -m "Authorize chapter material uploads by subject-teacher assignment, not an unenforced class-teacher heuristic"
```

---

### Task 3: Add `my-assignments` and `my-uploads` endpoints

**Files:**
- Modify: `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php` (add two public methods + one private helper, add two imports)
- Modify: `backend/routes/api.php:153-154`
- Test: `backend/tests/Feature/ChapterMaterialControllerTest.php` (add 4 new tests)

**Interfaces:**
- Consumes: `SubjectTeacherAssignment::forTeacher()` from Task 1.
- Produces: `GET /api/v1/chapter-materials/my-assignments` → `{data: [{section_id, section_name, subject_id, subject_name, class_id, class_name, class_version_id, class_version, shift_id, shift, academic_year_id, academic_year}]}`. `GET /api/v1/chapter-materials/my-uploads` → `{data: [ChapterMaterialResource, ...]}`. Both used by Task 5's api-client functions.

- [ ] **Step 1: Add the two imports**

In `backend/app/Http/Controllers/Api/V1/ChapterMaterialController.php`, add to the existing `use` block:

```php
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
```

- [ ] **Step 2: Add `myAssignments()`, `myUploads()`, and the `assignmentRow()` helper**

Add these three methods to `ChapterMaterialController` (after `mine()`, before `store()`):

```php
    /**
     * Every (section, subject) this teacher is explicitly assigned to —
     * the Section→Subject picker's data source for the materials upload
     * page. Unlike AcademicExamMarkController::myAssignments(), there is
     * no class-teacher-implied half here: uploading chapter material is
     * authorized by subject assignment only (see
     * ChapterMaterialPolicy::create()).
     */
    public function myAssignments(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $rows = SubjectTeacherAssignment::forTeacher($teacher->id)
            ->filter(fn (SubjectTeacherAssignment $assignment) => $assignment->section !== null && $assignment->subject !== null)
            ->map(fn (SubjectTeacherAssignment $assignment) => $this->assignmentRow($assignment->section, $assignment->subject))
            ->unique(fn (array $row) => $row['section_id'].'-'.$row['subject_id'])
            ->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Every material this teacher has personally uploaded, across every
     * chapter/section — feeds the "my uploads" list on the materials
     * page (edit/delete gated the same way the backend already gates
     * them, via ChapterMaterialPolicy::update()/delete()).
     */
    public function myUploads(Request $request): AnonymousResourceCollection
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $materials = ChapterMaterial::where('uploaded_by_id', $teacher->id)
            ->with('chapter.book.subject')
            ->orderByDesc('created_at')
            ->get();

        return ChapterMaterialResource::collection($materials);
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentRow(Section $section, Subject $subject): array
    {
        return [
            'section_id' => $section->id,
            'section_name' => $section->bn_name ?? $section->name,
            'subject_id' => $subject->id,
            'subject_name' => $subject->bn_name ?? $subject->name,
            'class_id' => $section->shift?->classVersion?->class_id,
            'class_name' => $section->shift?->classVersion?->schoolClass?->name,
            'class_version_id' => $section->shift?->class_version_id,
            'class_version' => $section->shift?->classVersion?->name,
            'shift_id' => $section->shift_id,
            'shift' => $section->shift?->name,
            'academic_year_id' => $section->shift?->academic_year_id,
            'academic_year' => $section->shift?->academicYear?->name,
        ];
    }
```

- [ ] **Step 3: Register the two routes**

In `backend/routes/api.php`, find this line (around line 154):

```php
        Route::post('/chapter-materials/{chapter_material}/download-link', [ChapterMaterialController::class, 'downloadLink']);
```

Add immediately after it:

```php
        Route::get('/chapter-materials/my-assignments', [ChapterMaterialController::class, 'myAssignments']);
        Route::get('/chapter-materials/my-uploads', [ChapterMaterialController::class, 'myUploads']);
```

- [ ] **Step 4: Add tests**

Add these 4 tests to `backend/tests/Feature/ChapterMaterialControllerTest.php`:

```php
    public function test_my_assignments_returns_only_this_teachers_subject_assignments(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $otherTeacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.section_id', $section->id)
            ->assertJsonPath('data.0.subject_id', $subjectId);
    }

    public function test_my_assignments_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-assignments')
            ->assertForbidden();
    }

    public function test_my_uploads_returns_only_this_teachers_own_materials(): void
    {
        $classVersionId = $this->chapterA->book->class_version_id;
        $subjectId = $this->chapterA->book->subject_id;
        $shift = Shift::create(['class_version_id' => $classVersionId, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $teacher = $this->teacherActorWithPermissions(['chapter_materials.create'], $this->schoolA->id, $this->schoolA->district_id);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Section A']);
        SubjectTeacherAssignment::create(['section_id' => $section->id, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $file = UploadedFile::fake()->create('chapter1.pdf', 100, 'application/pdf');

        $id = $this->actingAs($teacher, 'sanctum')
            ->post('/api/v1/chapter-materials', ['chapter_id' => $this->chapterA->id, 'section_id' => $section->id, 'type' => 'primary', 'pdf' => $file])
            ->json('data.id');

        $otherTeacher = $this->teacherActorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-uploads')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($teacher, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-uploads')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_my_uploads_is_forbidden_for_a_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([], $this->schoolA->id, $this->schoolA->district_id);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/chapter-materials/my-uploads')
            ->assertForbidden();
    }
```

- [ ] **Step 5: Run the tests**

Run: `cd backend && php artisan test --filter=ChapterMaterialControllerTest --compact`
Expected: PASS, all tests including the 4 new ones.

- [ ] **Step 6: Format and commit**

```bash
cd backend
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/ChapterMaterialController.php routes/api.php tests/Feature/ChapterMaterialControllerTest.php
git commit -m "Add chapter-materials/my-assignments and my-uploads endpoints for the teacher portal"
```

---

### Task 4: Grant teachers the create/update/delete permissions

**Files:**
- Modify: `backend/database/seeders/RolePermissionSeeder.php:69`
- Modify: `backend/tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php`

**Interfaces:**
- Consumes: Task 2's policy (must already be assignment-scoped before this task runs — do not reorder this before Task 2).
- Produces: nothing new consumed by later tasks; this is the step that actually makes the feature reachable end-to-end.

- [ ] **Step 1: Add the three permissions to the `teacher` role**

In `backend/database/seeders/RolePermissionSeeder.php`, find this line inside the `'teacher' => [...]` array (around line 69):

```php
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'books.view', 'chapters.view', 'academic_years.view',
```

Replace it with:

```php
                'classes.view', 'subjects.view', 'chapter_videos.view', 'chapter_materials.view', 'chapter_materials.create', 'chapter_materials.update', 'chapter_materials.delete', 'books.view', 'chapters.view', 'academic_years.view',
```

- [ ] **Step 2: Update the permission-mapping regression test**

Replace the full contents of `backend/tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php` with:

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

    /**
     * chapter_materials.create is now also granted to `teacher`, since
     * teachers upload their own class's materials — scoped at the
     * Policy layer (SubjectTeacherAssignment match), not by withholding
     * the permission. national_admin keeps it via $allPermissions.
     * Granting it to any OTHER role here would be a real authorization
     * regression, not just redundancy.
     */
    public function test_chapter_materials_create_is_mapped_to_national_admin_and_teacher(): void
    {
        $this->seed(DatabaseSeeder::class);

        $roles = Role::whereHas('permissions', fn ($q) => $q->where('name', 'chapter_materials.create'))
            ->pluck('name')->sort()->values()->all();

        $this->assertSame(['national_admin', 'teacher'], $roles);
    }
}
```

- [ ] **Step 3: Run the tests**

Run: `cd backend && php artisan test --filter=ChapterMaterialAuthoringPermissionMappingTest --compact`
Expected: PASS.

Run: `cd backend && php artisan test --compact`
Expected: PASS — full suite, confirming the new permission grant doesn't let a teacher reach anything unscoped (Task 2's policy is what prevents that).

- [ ] **Step 4: Commit**

```bash
cd backend
git add database/seeders/RolePermissionSeeder.php tests/Feature/ChapterMaterialAuthoringPermissionMappingTest.php
git commit -m "Grant teacher role permission to create/update/delete chapter materials"
```

---

### Task 5: api-client functions for teacher-uploaded materials

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts` (update the `ChapterMaterial` interface's comment, add 1 new interface, 5 new functions)
- Modify: `frontend/packages/api-client/src/index.test.ts` (add tests for the 5 new functions)

**Interfaces:**
- Consumes: Task 3's two new endpoints, Task 2's `section_id` field on create.
- Produces: `listMyChapterMaterialAssignments(token): Promise<{data: ChapterMaterialAssignment[]}>`, `listMyUploadedChapterMaterials(token): Promise<{data: ChapterMaterial[]}>`, `createChapterMaterial(formData, token): Promise<{data: ChapterMaterial}>`, `updateChapterMaterial(id, formData, token): Promise<{data: ChapterMaterial}>`, `deleteChapterMaterial(id, token): Promise<void>` — all consumed by Task 6's new page.

- [ ] **Step 1: Correct the stale comment on `ChapterMaterial`'s scope fields**

In `frontend/packages/api-client/src/index.ts`, find (around line 820-831):

```ts
export interface ChapterMaterial {
  id: number
  chapter_id: number
  // Set only when the uploader was the class_teacher of a section
  // matching the chapter's own class_version — null means this material
  // is visible to the whole chapter, not restricted to one section.
  school_id: number | null
```

Replace the comment with:

```ts
export interface ChapterMaterial {
  id: number
  chapter_id: number
  // Set when the uploader is a teacher assigned (via
  // SubjectTeacherAssignment) to teach this chapter's subject in this
  // section — null means this material is visible to the whole class
  // version, not restricted to one section (e.g. staff-authored).
  school_id: number | null
```

- [ ] **Step 2: Add the new type and functions**

In the same file, find the end of the "Chapter materials" section — right after `getChapterMaterialDownloadLink` (around line 871-874, just before the `// ---------- Chapter videos ----------` or next section comment — it is actually located right before `// ---------- Academic years ----------` since chapter materials come after chapter videos in this file; locate it precisely by searching for `export function getChapterMaterialDownloadLink`). Add immediately after that function's closing `}`:

```ts

export interface ChapterMaterialAssignment {
  section_id: number
  section_name: string
  subject_id: number
  subject_name: string
  class_id: number | null
  class_name: string | null
  class_version_id: number | null
  class_version: string | null
  shift_id: number | null
  shift: string | null
  academic_year_id: number | null
  academic_year: string | null
}

// Every (section, subject) the logged-in teacher is explicitly assigned
// to teach — the Section→Subject picker's data source on the teacher
// portal's Class Materials page (see ChapterMaterialController::
// myAssignments()).
export function listMyChapterMaterialAssignments(token: string): Promise<{ data: ChapterMaterialAssignment[] }> {
  return apiFetch<{ data: ChapterMaterialAssignment[] }>('/chapter-materials/my-assignments', {
    headers: authHeaders(token),
  })
}

// Every material the logged-in teacher has personally uploaded, across
// every chapter/section — feeds the "my uploads" list on the same page.
export function listMyUploadedChapterMaterials(token: string): Promise<{ data: ChapterMaterial[] }> {
  return apiFetch<{ data: ChapterMaterial[] }>('/chapter-materials/my-uploads', {
    headers: authHeaders(token),
  })
}

export function createChapterMaterial(formData: FormData, token: string): Promise<{ data: ChapterMaterial }> {
  return apiFetch('/chapter-materials', {
    method: 'POST',
    headers: multipartHeaders(token),
    body: formData,
  })
}

export function updateChapterMaterial(id: number, formData: FormData, token: string): Promise<{ data: ChapterMaterial }> {
  // Laravel can't parse a PATCH request with a multipart body — same
  // _method spoof as updateSchool() above.
  formData.append('_method', 'PATCH')

  return apiFetch(`/chapter-materials/${id}`, {
    method: 'POST',
    headers: multipartHeaders(token),
    body: formData,
  })
}

export function deleteChapterMaterial(id: number, token: string): Promise<void> {
  return deleteGeo('chapter-materials', id, token)
}
```

- [ ] **Step 3: Add tests**

In `frontend/packages/api-client/src/index.test.ts`, find the `describe('chapter materials', ...)` block's closing `})` (the one right after the `getChapterMaterialDownloadLink` test). Add this new `describe` block immediately after it:

```ts
describe('chapter materials upload (teacher)', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it("lists the current teacher's own subject assignments with a required token", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listMyChapterMaterialAssignments('tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/my-assignments`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })

  it("lists the current teacher's own uploaded materials with a required token", async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: [] }),
    })
    vi.stubGlobal('fetch', mockFetch)

    await listMyUploadedChapterMaterials('tok')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/my-uploads`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({ headers: { Authorization: 'Bearer tok' } })
  })

  it('creates a chapter material with a multipart body and no Content-Type header', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      status: 201,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const formData = new FormData()
    formData.append('chapter_id', '1')
    formData.append('section_id', '2')
    formData.append('type', 'primary')

    await createChapterMaterial(formData, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials`)
    const init = mockFetch.mock.calls[0][1] as RequestInit
    expect(init.method).toBe('POST')
    expect(init.body).toBe(formData)
    expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined()
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer secret-token')
  })

  it('updates a chapter material by POSTing with a _method=PATCH spoof field', async () => {
    const mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { id: 1 } }),
    })
    vi.stubGlobal('fetch', mockFetch)

    const formData = new FormData()
    formData.append('title', 'Updated title')

    await updateChapterMaterial(1, formData, 'secret-token')

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/1`)
    const init = mockFetch.mock.calls[0][1] as RequestInit
    expect(init.method).toBe('POST')
    expect(formData.get('_method')).toBe('PATCH')
  })

  it('deletes a chapter material with DELETE and resolves on 204', async () => {
    const mockFetch = vi.fn().mockResolvedValue({ ok: true, status: 204 })
    vi.stubGlobal('fetch', mockFetch)

    await expect(deleteChapterMaterial(1, 'secret-token')).resolves.toBeUndefined()

    expect(mockFetch.mock.calls[0][0]).toBe(`${import.meta.env.VITE_API_BASE_URL}/chapter-materials/1`)
    expect(mockFetch.mock.calls[0][1]).toMatchObject({
      method: 'DELETE',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    })
  })
})
```

Add the 5 new function/type names to this test file's existing `import { ... } from './index'` block at the top of the file (alongside the other `chapterMaterial*` imports already there): `listMyChapterMaterialAssignments`, `listMyUploadedChapterMaterials`, `createChapterMaterial`, `updateChapterMaterial`, `deleteChapterMaterial`.

- [ ] **Step 4: Run the tests**

Run: `cd frontend && npx vitest run packages/api-client`
Expected: PASS, all tests including the 5 new ones.

- [ ] **Step 5: Typecheck**

Run: `cd frontend/packages/api-client && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
cd frontend
git add packages/api-client/src/index.ts packages/api-client/src/index.test.ts
git commit -m "Add api-client functions for teacher chapter-material uploads"
```

---

### Task 6: Teacher portal "Class Materials" page

**Files:**
- Create: `frontend/apps/teacher/src/pages/ClassMaterialsPage.tsx`
- Modify: `frontend/apps/teacher/src/components/TeacherAppShell.tsx`
- Modify: `frontend/apps/teacher/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: all 5 functions + the `ChapterMaterialAssignment` type from Task 5, plus the existing `listBooks`, `listChapters`, `Book`, `Chapter`, `ChapterMaterial` from the api-client, and the existing `ApiError`, `useAuth`, `getCommonStrings`, `useToast`/`ToastViewport` from `../components/Toast`.
- Produces: nothing consumed by other tasks — this is the final, user-facing task.

- [ ] **Step 1: Add the i18n keys**

In `frontend/packages/i18n/src/common/en.json`, find the line `"teacher.nav.marksheets": "Marksheets",` (or the nearest `teacher.nav.*` entry) and add a new nav key right after the other `teacher.nav.*` entries near the top of the teacher section:

```json
  "teacher.nav.classMaterials": "Class Materials",
```

Then, near the end of the `teacher.marksheets.*` block (after `"teacher.marksheets.noMarksRecorded": "..."`), add a new block:

```json

  "teacher.classMaterials.loading": "Loading…",
  "teacher.classMaterials.selectSection": "Select section",
  "teacher.classMaterials.selectSubject": "Select subject",
  "teacher.classMaterials.selectBook": "Select book",
  "teacher.classMaterials.selectChapter": "Select chapter",
  "teacher.classMaterials.typePrimary": "Primary",
  "teacher.classMaterials.typeSupplementary": "Supplementary",
  "teacher.classMaterials.titlePlaceholder": "e.g. Extra practice sheet",
  "teacher.classMaterials.orderIndex": "Order",
  "teacher.classMaterials.upload": "Upload",
  "teacher.classMaterials.uploading": "Uploading…",
  "teacher.classMaterials.uploadSuccess": "Material uploaded.",
  "teacher.classMaterials.uploadError": "Could not upload material. Please try again.",
  "teacher.classMaterials.myUploads": "My uploads",
  "teacher.classMaterials.empty": "You haven't uploaded any material yet.",
  "teacher.classMaterials.colChapter": "Chapter",
  "teacher.classMaterials.colSubject": "Subject",
  "teacher.classMaterials.colSection": "Section",
  "teacher.classMaterials.colType": "Type",
  "teacher.classMaterials.colFile": "File",
  "teacher.classMaterials.colStatus": "Status",
  "teacher.classMaterials.colActions": "Actions",
  "teacher.classMaterials.statusActive": "Active",
  "teacher.classMaterials.statusArchived": "Archived",
  "teacher.classMaterials.edit": "Edit",
  "teacher.classMaterials.delete": "Delete",
  "teacher.classMaterials.deleting": "Deleting…",
  "teacher.classMaterials.deleteConfirm": "Delete this material?",
  "teacher.classMaterials.deleteSuccess": "Material deleted.",
  "teacher.classMaterials.deleteError": "Could not delete material. Please try again.",
  "teacher.classMaterials.replaceFileNote": "Leave empty to keep the current file.",
  "teacher.classMaterials.save": "Save",
  "teacher.classMaterials.saving": "Saving…",
  "teacher.classMaterials.saveSuccess": "Material updated.",
  "teacher.classMaterials.saveError": "Could not save changes. Please try again.",
  "teacher.classMaterials.cancel": "Cancel",
```

In `frontend/packages/i18n/src/common/bn.json`, add the matching nav key next to the other `teacher.nav.*` entries:

```json
  "teacher.nav.classMaterials": "ক্লাস উপকরণ",
```

And the matching block (same position, right after the `teacher.marksheets.*` block):

```json

  "teacher.classMaterials.loading": "লোড হচ্ছে…",
  "teacher.classMaterials.selectSection": "শাখা নির্বাচন করুন",
  "teacher.classMaterials.selectSubject": "বিষয় নির্বাচন করুন",
  "teacher.classMaterials.selectBook": "বই নির্বাচন করুন",
  "teacher.classMaterials.selectChapter": "অধ্যায় নির্বাচন করুন",
  "teacher.classMaterials.typePrimary": "মূল",
  "teacher.classMaterials.typeSupplementary": "সহায়ক",
  "teacher.classMaterials.titlePlaceholder": "যেমন অতিরিক্ত অনুশীলনী",
  "teacher.classMaterials.orderIndex": "ক্রম",
  "teacher.classMaterials.upload": "আপলোড করুন",
  "teacher.classMaterials.uploading": "আপলোড হচ্ছে…",
  "teacher.classMaterials.uploadSuccess": "উপকরণ আপলোড করা হয়েছে।",
  "teacher.classMaterials.uploadError": "উপকরণ আপলোড করা যায়নি। আবার চেষ্টা করুন।",
  "teacher.classMaterials.myUploads": "আমার আপলোডগুলো",
  "teacher.classMaterials.empty": "আপনি এখনো কোনো উপকরণ আপলোড করেননি।",
  "teacher.classMaterials.colChapter": "অধ্যায়",
  "teacher.classMaterials.colSubject": "বিষয়",
  "teacher.classMaterials.colSection": "শাখা",
  "teacher.classMaterials.colType": "ধরন",
  "teacher.classMaterials.colFile": "ফাইল",
  "teacher.classMaterials.colStatus": "অবস্থা",
  "teacher.classMaterials.colActions": "কার্যক্রম",
  "teacher.classMaterials.statusActive": "সক্রিয়",
  "teacher.classMaterials.statusArchived": "আর্কাইভ করা হয়েছে",
  "teacher.classMaterials.edit": "সম্পাদনা",
  "teacher.classMaterials.delete": "মুছুন",
  "teacher.classMaterials.deleting": "মুছে ফেলা হচ্ছে…",
  "teacher.classMaterials.deleteConfirm": "এই উপকরণটি মুছে ফেলবেন?",
  "teacher.classMaterials.deleteSuccess": "উপকরণ মুছে ফেলা হয়েছে।",
  "teacher.classMaterials.deleteError": "উপকরণ মুছে ফেলা যায়নি। আবার চেষ্টা করুন।",
  "teacher.classMaterials.replaceFileNote": "বর্তমান ফাইল রাখতে খালি রাখুন।",
  "teacher.classMaterials.save": "সংরক্ষণ করুন",
  "teacher.classMaterials.saving": "সংরক্ষণ হচ্ছে…",
  "teacher.classMaterials.saveSuccess": "উপকরণ আপডেট করা হয়েছে।",
  "teacher.classMaterials.saveError": "পরিবর্তন সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।",
  "teacher.classMaterials.cancel": "বাতিল",
```

- [ ] **Step 2: Create the page component**

Create `frontend/apps/teacher/src/pages/ClassMaterialsPage.tsx`:

```tsx
import { useEffect, useMemo, useState, type FormEvent } from 'react'
import {
  ApiError,
  createChapterMaterial,
  deleteChapterMaterial,
  listBooks,
  listChapters,
  listMyChapterMaterialAssignments,
  listMyUploadedChapterMaterials,
  updateChapterMaterial,
  type Book,
  type Chapter,
  type ChapterMaterial,
  type ChapterMaterialAssignment,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

/**
 * First row carrying each distinct, non-null key — same technique
 * MarksEntryPage uses to turn a flat assignment list into cascading
 * filter options without a dedicated endpoint per level.
 */
function uniqueByKey<T, K>(items: T[], keyFn: (item: T) => K | null): T[] {
  const seen = new Set<K>()
  const result: T[] = []

  for (const item of items) {
    const key = keyFn(item)
    if (key === null || seen.has(key)) continue
    seen.add(key)
    result.push(item)
  }

  return result
}

type MaterialType = 'primary' | 'supplementary'

export default function ClassMaterialsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [assignments, setAssignments] = useState<ChapterMaterialAssignment[]>([])
  const [myUploads, setMyUploads] = useState<ChapterMaterial[]>([])
  const [loadingFilters, setLoadingFilters] = useState(true)

  const [sectionId, setSectionId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [books, setBooks] = useState<Book[]>([])
  const [bookId, setBookId] = useState<number | null>(null)
  const [chapters, setChapters] = useState<Chapter[]>([])
  const [chapterId, setChapterId] = useState<number | null>(null)

  const [type, setType] = useState<MaterialType>('primary')
  const [title, setTitle] = useState('')
  const [orderIndex, setOrderIndex] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [uploading, setUploading] = useState(false)

  const [editingId, setEditingId] = useState<number | null>(null)
  const [editTitle, setEditTitle] = useState('')
  const [editOrderIndex, setEditOrderIndex] = useState('')
  const [editStatus, setEditStatus] = useState('active')
  const [editFile, setEditFile] = useState<File | null>(null)
  const [savingEdit, setSavingEdit] = useState(false)
  const [deletingId, setDeletingId] = useState<number | null>(null)

  function refreshMyUploads() {
    if (!token) return
    listMyUploadedChapterMaterials(token)
      .then((result) => setMyUploads(result.data))
      .catch(() => setMyUploads([]))
  }

  useEffect(() => {
    if (!token) return

    Promise.all([listMyChapterMaterialAssignments(token), listMyUploadedChapterMaterials(token)])
      .then(([assignmentResult, uploadsResult]) => {
        setAssignments(assignmentResult.data)
        setMyUploads(uploadsResult.data)
      })
      .catch(() => {
        setAssignments([])
        setMyUploads([])
      })
      .finally(() => setLoadingFilters(false))
  }, [token])

  const sections = useMemo(() => uniqueByKey(assignments, (row) => row.section_id), [assignments])
  const subjectsForSection = useMemo(
    () => assignments.filter((row) => row.section_id === sectionId),
    [assignments, sectionId],
  )
  const selectedAssignment = useMemo(
    () => assignments.find((row) => row.section_id === sectionId && row.subject_id === subjectId) ?? null,
    [assignments, sectionId, subjectId],
  )

  useEffect(() => {
    if (!selectedAssignment || selectedAssignment.class_version_id === null) {
      setBooks([])
      setBookId(null)
      return
    }

    let active = true
    listBooks({ classVersionId: selectedAssignment.class_version_id, subjectId: selectedAssignment.subject_id })
      .then((result) => {
        if (active) setBooks(result.data)
      })
      .catch(() => {
        if (active) setBooks([])
      })

    return () => {
      active = false
    }
  }, [selectedAssignment])

  useEffect(() => {
    if (bookId === null) {
      setChapters([])
      setChapterId(null)
      return
    }

    let active = true
    listChapters({ bookId })
      .then((result) => {
        if (active) setChapters(result.data)
      })
      .catch(() => {
        if (active) setChapters([])
      })

    return () => {
      active = false
    }
  }, [bookId])

  function resetUploadForm() {
    setType('primary')
    setTitle('')
    setOrderIndex('')
    setFile(null)
  }

  async function handleUpload(event: FormEvent) {
    event.preventDefault()
    if (!token || sectionId === null || chapterId === null || !file) return

    const formData = new FormData()
    formData.append('chapter_id', String(chapterId))
    formData.append('section_id', String(sectionId))
    formData.append('type', type)
    if (type === 'supplementary') formData.append('title', title)
    if (orderIndex.trim() !== '') formData.append('order_index', orderIndex)
    formData.append('pdf', file)

    setUploading(true)

    try {
      await createChapterMaterial(formData, token)
      showToast({ type: 'success', message: t['teacher.classMaterials.uploadSuccess'] })
      resetUploadForm()
      refreshMyUploads()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.uploadError'] })
    } finally {
      setUploading(false)
    }
  }

  function startEdit(material: ChapterMaterial) {
    setEditingId(material.id)
    setEditTitle(material.title ?? '')
    setEditOrderIndex(material.order_index === null ? '' : String(material.order_index))
    setEditStatus(material.status)
    setEditFile(null)
  }

  function cancelEdit() {
    setEditingId(null)
    setEditFile(null)
  }

  async function handleSaveEdit(materialId: number) {
    if (!token) return

    const formData = new FormData()
    formData.append('title', editTitle)
    if (editOrderIndex.trim() !== '') formData.append('order_index', editOrderIndex)
    formData.append('status', editStatus)
    if (editFile) formData.append('pdf', editFile)

    setSavingEdit(true)

    try {
      await updateChapterMaterial(materialId, formData, token)
      showToast({ type: 'success', message: t['teacher.classMaterials.saveSuccess'] })
      setEditingId(null)
      refreshMyUploads()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.saveError'] })
    } finally {
      setSavingEdit(false)
    }
  }

  async function handleDelete(materialId: number) {
    if (!token) return

    setDeletingId(materialId)

    try {
      await deleteChapterMaterial(materialId, token)
      showToast({ type: 'success', message: t['teacher.classMaterials.deleteSuccess'] })
      refreshMyUploads()
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.classMaterials.deleteError'] })
    } finally {
      setDeletingId(null)
    }
  }

  function sectionLabel(row: ChapterMaterialAssignment) {
    return [row.class_name, row.class_version, row.shift, row.section_name].filter(Boolean).join(' - ')
  }

  function materialSectionLabel(material: ChapterMaterial) {
    const row = assignments.find((assignment) => assignment.section_id === material.section_id)
    return row ? sectionLabel(row) : '—'
  }

  return (
    <div>
      <h1 className="text-xl font-semibold text-gray-900">{t['teacher.nav.classMaterials']}</h1>

      {loadingFilters && <p className="mt-4 text-sm text-gray-500">{t['teacher.classMaterials.loading']}</p>}

      {!loadingFilters && (
        <form className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm" onSubmit={handleUpload}>
          <select
            className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={sectionId ?? ''}
            onChange={(event) => {
              setSectionId(event.target.value ? Number(event.target.value) : null)
              setSubjectId(null)
              setBookId(null)
              setChapterId(null)
            }}
          >
            <option value="">{t['teacher.classMaterials.selectSection']}</option>
            {sections.map((row) => (
              <option key={row.section_id} value={row.section_id}>
                {sectionLabel(row)}
              </option>
            ))}
          </select>

          <select
            className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
            value={subjectId ?? ''}
            disabled={sectionId === null}
            onChange={(event) => {
              setSubjectId(event.target.value ? Number(event.target.value) : null)
              setBookId(null)
              setChapterId(null)
            }}
          >
            <option value="">{t['teacher.classMaterials.selectSubject']}</option>
            {subjectsForSection.map((row) => (
              <option key={row.subject_id} value={row.subject_id}>
                {row.subject_name}
              </option>
            ))}
          </select>

          <select
            className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
            value={bookId ?? ''}
            disabled={subjectId === null}
            onChange={(event) => {
              setBookId(event.target.value ? Number(event.target.value) : null)
              setChapterId(null)
            }}
          >
            <option value="">{t['teacher.classMaterials.selectBook']}</option>
            {books.map((book) => (
              <option key={book.id} value={book.id}>
                {book.bn_name ?? book.name}
              </option>
            ))}
          </select>

          <select
            className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
            value={chapterId ?? ''}
            disabled={bookId === null}
            onChange={(event) => setChapterId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">{t['teacher.classMaterials.selectChapter']}</option>
            {chapters.map((chapter) => (
              <option key={chapter.id} value={chapter.id}>
                {chapter.bn_name ?? chapter.name}
              </option>
            ))}
          </select>

          <select
            className="h-11 min-w-[9rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={type}
            onChange={(event) => setType(event.target.value as MaterialType)}
          >
            <option value="primary">{t['teacher.classMaterials.typePrimary']}</option>
            <option value="supplementary">{t['teacher.classMaterials.typeSupplementary']}</option>
          </select>

          {type === 'supplementary' && (
            <input
              type="text"
              placeholder={t['teacher.classMaterials.titlePlaceholder']}
              className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
              value={title}
              onChange={(event) => setTitle(event.target.value)}
            />
          )}

          <input
            type="number"
            min={0}
            max={255}
            placeholder={t['teacher.classMaterials.orderIndex']}
            className="h-11 w-24 rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
            value={orderIndex}
            onChange={(event) => setOrderIndex(event.target.value)}
          />

          <input
            type="file"
            accept="application/pdf"
            className="h-11 text-sm text-gray-700"
            onChange={(event) => setFile(event.target.files?.[0] ?? null)}
          />

          <button
            type="submit"
            disabled={uploading || sectionId === null || chapterId === null || !file}
            className="h-11 cursor-pointer rounded-md bg-primary-500 px-4 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {uploading ? t['teacher.classMaterials.uploading'] : t['teacher.classMaterials.upload']}
          </button>
        </form>
      )}

      <h2 className="mt-8 text-base font-semibold text-gray-800">{t['teacher.classMaterials.myUploads']}</h2>

      {myUploads.length === 0 ? (
        <p className="mt-3 text-sm text-gray-500">{t['teacher.classMaterials.empty']}</p>
      ) : (
        <div className="mt-3 overflow-x-auto rounded-md bg-white shadow-sm">
          <table className="w-full min-w-[48rem] text-sm">
            <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
              <tr>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colChapter']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colSubject']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colSection']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colType']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colFile']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colStatus']}</th>
                <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.classMaterials.colActions']}</th>
              </tr>
            </thead>
            <tbody>
              {myUploads.map((material) => (
                <tr key={material.id} className="border-t border-gray-100 align-top">
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.chapter?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.chapter?.book?.subject?.name ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{materialSectionLabel(material)}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">
                    {material.type === 'primary' ? t['teacher.classMaterials.typePrimary'] : t['teacher.classMaterials.typeSupplementary']}
                  </td>
                  <td className="px-5 py-3.5 whitespace-nowrap text-gray-700">{material.original_filename ?? '—'}</td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    {editingId === material.id ? (
                      <select
                        className="h-9 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500"
                        value={editStatus}
                        onChange={(event) => setEditStatus(event.target.value)}
                      >
                        <option value="active">{t['teacher.classMaterials.statusActive']}</option>
                        <option value="archived">{t['teacher.classMaterials.statusArchived']}</option>
                      </select>
                    ) : material.status === 'active' ? (
                      t['teacher.classMaterials.statusActive']
                    ) : (
                      t['teacher.classMaterials.statusArchived']
                    )}
                  </td>
                  <td className="px-5 py-3.5 whitespace-nowrap">
                    {editingId === material.id ? (
                      <div className="flex flex-col gap-2">
                        {material.type === 'supplementary' && (
                          <input
                            type="text"
                            className="h-9 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500"
                            value={editTitle}
                            onChange={(event) => setEditTitle(event.target.value)}
                          />
                        )}
                        <input
                          type="number"
                          min={0}
                          max={255}
                          placeholder={t['teacher.classMaterials.orderIndex']}
                          className="h-9 w-24 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500"
                          value={editOrderIndex}
                          onChange={(event) => setEditOrderIndex(event.target.value)}
                        />
                        <input
                          type="file"
                          accept="application/pdf"
                          className="text-xs text-gray-600"
                          onChange={(event) => setEditFile(event.target.files?.[0] ?? null)}
                        />
                        <p className="text-xs text-gray-400">{t['teacher.classMaterials.replaceFileNote']}</p>
                        <div className="flex gap-2">
                          <button
                            type="button"
                            disabled={savingEdit}
                            onClick={() => handleSaveEdit(material.id)}
                            className="cursor-pointer rounded-md bg-primary-500 px-3 py-1.5 text-xs font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
                          >
                            {savingEdit ? t['teacher.classMaterials.saving'] : t['teacher.classMaterials.save']}
                          </button>
                          <button
                            type="button"
                            onClick={cancelEdit}
                            className="cursor-pointer rounded-md border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-600 transition-colors hover:border-primary-500"
                          >
                            {t['teacher.classMaterials.cancel']}
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div className="flex gap-3">
                        <button
                          type="button"
                          onClick={() => startEdit(material)}
                          className="cursor-pointer text-xs font-medium text-primary-500 hover:text-primary-400"
                        >
                          {t['teacher.classMaterials.edit']}
                        </button>
                        <button
                          type="button"
                          disabled={deletingId === material.id}
                          onClick={() => {
                            if (window.confirm(t['teacher.classMaterials.deleteConfirm'])) handleDelete(material.id)
                          }}
                          className="cursor-pointer text-xs font-medium text-danger-500 hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                          {deletingId === material.id ? t['teacher.classMaterials.deleting'] : t['teacher.classMaterials.delete']}
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <ToastViewport toast={toast} />
    </div>
  )
}
```

- [ ] **Step 3: Wire the nav entry**

In `frontend/apps/teacher/src/components/TeacherAppShell.tsx`, find the `navItems` array entry for Marksheets:

```ts
  { to: '/marksheets', label: t['teacher.nav.marksheets'], icon: 'description' },
```

Add immediately after it:

```ts
  { to: '/class-materials', label: t['teacher.nav.classMaterials'], icon: 'folder_open' },
```

- [ ] **Step 4: Wire the route**

In `frontend/apps/teacher/src/App.tsx`, add the import:

```tsx
import ClassMaterialsPage from './pages/ClassMaterialsPage'
```

And add the route next to the Marksheets route:

```tsx
              <Route path="class-materials" element={<ClassMaterialsPage />} />
```

- [ ] **Step 5: Typecheck**

Run: `cd frontend/apps/teacher && npx tsc --noEmit`
Expected: no errors. If `material.chapter?.book?.subject?.name` or any other nested-optional field reports a type error, fix by matching the exact field names already declared in the `Chapter`/`Book`/`Subject` interfaces in `frontend/packages/api-client/src/index.ts` — do not add `any`/`as` casts to silence it.

- [ ] **Step 6: Run the full frontend test suite**

Run: `cd frontend && npx vitest run`
Expected: PASS (same 3 test files as before this plan, plus Task 5's additions — this task adds no new test file, matching this repo's existing convention of not unit-testing `apps/*` page components).

- [ ] **Step 7: Manually verify the end-to-end flow in a browser**

Start both the backend and the teacher app (`composer run dev` or equivalent from `backend/`, and `npm run dev --workspace=apps/teacher` from `frontend/`). Using a teacher account that has a `SubjectTeacherAssignment` row (create one via the management portal's Teachers/Subject-Teacher-Assignment pages if none exists, or via `php artisan tinker`), log in to the teacher portal, open "Class Materials," pick Section → Subject → Book → Chapter, upload a PDF, and confirm:
- the upload succeeds and appears in "My uploads" below the form;
- the Subject/Chapter columns in "My uploads" render real names (if they show "—" instead, `ChapterResource`'s `book`/`subject` nesting may not be loaded that deep — note this for a follow-up, since it's a read-only display gap, not a functional blocker);
- logging in as a student in that exact section (via the student portal's "Class Material" page) shows the uploaded file;
- logging in as a student in a *different* section does NOT see it.

- [ ] **Step 8: Commit**

```bash
cd frontend
git add apps/teacher/src/pages/ClassMaterialsPage.tsx apps/teacher/src/components/TeacherAppShell.tsx apps/teacher/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add teacher portal Class Materials page for uploading chapter materials"
```
