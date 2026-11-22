# Design: Teacher Uploads for Chapter Materials

**Status:** Approved, ready for implementation planning

**Source:** User request — the "Class Material" menu in the student
portal must be populated by teachers uploading materials for their own
assigned classes via the teacher portal, not by admin-only seeding.

## Current state (why this is a real gap, not a tweak)

`chapter_materials` (PDF files attached to a `Chapter`) already has a
full CRUD backend (`ChapterMaterialController`) and a working
student-facing read path (`ClassMaterialsPage.tsx` in
`frontend/apps/student`, scoped to the student's own section via
`mine()`). But:

- **No teacher portal page exists at all** for uploading materials —
  not a broken page, a missing one. The shared api-client has no
  `createChapterMaterial`/`updateChapterMaterial`/
  `deleteChapterMaterial` function; nothing in `frontend/apps/teacher`
  references chapter materials.
- **`ChapterMaterialPolicy::create()`/`update()`/`delete()` are flat
  permission checks** (`hasPermission('chapter_materials.create')`,
  etc.) with no class/subject/section restriction at all. Any actor
  holding the permission could create material for any chapter in the
  system; `update`/`delete` have no ownership check either.
- **`chapter_materials.create/.update/.delete` are seeded only to
  `national_admin`** (`RolePermissionSeeder`) — today, no teacher can
  reach `store()` regardless of the above.
- The one existing "restriction",
  `ChapterMaterialController::resolveUploaderScope()`, is visibility
  metadata, not authorization: it stamps a section scope only when the
  uploader is the sole `class_teacher` of exactly one section matching
  the chapter's class version, and silently leaves the material
  unscoped (visible to the whole class) otherwise. It keys off
  homeroom `class_teacher_id`, not subject assignment, so it doesn't
  check the teacher teaches the *subject* at all.

So "teachers upload their own classes' materials" isn't a bug fix —
every layer (permission grant, authorization, scope-stamping, and the
entire teacher-portal UI) has to be built.

## Decisions confirmed during brainstorming

- **Authorization is subject-assignment-only.** A teacher may upload
  material for a `(section, subject)` pair only if a
  `SubjectTeacherAssignment` row exists for `(section_id, subject_id,
  teacher_id)`. The section's homeroom `class_teacher_id` grants no
  extra authority here — deliberately narrower than the
  `AcademicExamMarkPolicy`/`AttendancePolicy` precedent, because this
  feature is specifically about subject content, not homeroom-level
  data.
- **One section per upload, no schema change.** `chapter_materials`
  keeps its single nullable `section_id` column. A teacher who teaches
  the same subject across several sections of a class uploads the same
  file once per section; there is no multi-section tagging in one
  request.
- **Ownership gates edit/delete for teachers.** A `TeacherUser` may
  only update/delete material they themselves uploaded
  (`uploaded_by_id`). Staff (`national_admin`/`school_admin`) with the
  flat permission keep full override, unchanged.
- **Admin upload behavior is untouched.** Staff actors continue to
  create unscoped-by-default material exactly as today;
  `section_id` is required only when the actor is a `TeacherUser`.

**Explicitly not in scope:**

- No change to the student-facing `ClassMaterialsPage.tsx` or its
  `mine()` endpoint — they already read the scope columns correctly;
  this project only makes those columns get set correctly by a real
  actor instead of never being exercised.
- No management-portal UI for materials. Admin-authored material still
  goes through the existing API directly (e.g. via the seeder), not a
  new screen.
- No change to the `primary`/`supplementary` material-type model, the
  single-active-primary-per-chapter rule, or the PDF-only file
  constraint — all unchanged from today.

## Backend changes

### 1. `SubjectTeacherAssignment` gets a reusable "assignments for teacher" helper

`AcademicExamMarkController::myAssignments()` already computes "every
`(section, subject)` this teacher may act on" by merging class-teacher-
implied subjects with explicit `SubjectTeacherAssignment` rows. This
project needs the explicit-assignment half of that same data (no
class-teacher half, per the decision above) for its own picker. Rather
than duplicate the merge/dedup logic, extract a small static helper:

```php
// App\Models\SubjectTeacherAssignment
/**
 * Every (section, subject) this teacher is explicitly assigned to,
 * with section/subject/class context eager-loaded for a picker.
 *
 * @return Collection<int, SubjectTeacherAssignment>
 */
public static function forTeacher(int $teacherId): Collection
{
    return static::where('teacher_id', $teacherId)
        ->with(['section.shift.classVersion.schoolClass', 'section.shift.academicYear', 'subject'])
        ->get();
}
```

`AcademicExamMarkController::myAssignments()` is refactored to call
this for its explicit-assignment half instead of re-querying inline —
behavior-preserving, just removing the duplication this project would
otherwise introduce.

### 2. `StoreChapterMaterialRequest` gains `section_id`

```php
'section_id' => [
    Rule::requiredIf(fn () => $this->user() instanceof TeacherUser),
    'nullable', 'integer', Rule::exists('sections', 'id'),
],
```

### 3. `ChapterMaterialPolicy` — real scoping on create, ownership on update/delete

`create()` now always requires a resolved `Chapter` — it's only ever
called by the controller after `chapter_id` has passed validation, not
by the FormRequest (see below, this avoids a policy method whose
meaning changes depending on whether context was supplied):

```php
public function create(Authenticatable $user, Chapter $chapter, ?int $sectionId = null): bool
{
    if (! $user->hasPermission('chapter_materials.create')) {
        return false;
    }

    if (! $user instanceof TeacherUser) {
        return true; // staff: unrestricted, unchanged
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
```

`StoreChapterMaterialRequest::authorize()` can't supply a `Chapter` (it
runs before validation resolves `chapter_id`), so it stops going
through the Policy at all and instead does the same cheap, context-
free permission pre-check Laravel's gate would otherwise shortcut to:

```php
public function authorize(): bool
{
    return $this->user()?->hasPermission('chapter_materials.create') ?? false;
}
```

This is a fast-fail only (rejects an actor with no permission at all
before file-upload validation runs) — the real, context-aware check is
the controller's explicit call below, which is what actually enforces
the assignment match.

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
```

`ChapterMaterialController::store()` loads `chapter.book` first, then
calls `$this->authorize('create', [ChapterMaterial::class, $chapter,
$request->integer('section_id') ?: null])` (replacing today's
no-context `$this->authorize('create', ChapterMaterial::class)`).
`StoreChapterMaterialRequest::authorize()` keeps its existing flat
`can('create', ChapterMaterial::class)` call as a cheap first-pass
reject for actors with no permission at all — the controller's
context-aware call is what actually enforces the assignment match.

### 4. `resolveUploaderScope()` becomes a direct lookup, not a guess

```php
private function resolveUploaderScope(mixed $actor, Chapter $chapter, ?int $sectionId): array
{
    $empty = ['school_id' => null, 'district_id' => null, 'shift_id' => null, 'class_version_id' => null, 'class_id' => null, 'section_id' => null];

    if (! $actor instanceof TeacherUser || ! $sectionId) {
        return $empty;
    }

    // Already authorized against this exact section in the Policy — a
    // direct lookup here, not a re-derivation, since the ambiguity the
    // old heuristic was guarding against ("which section did they
    // mean?") no longer exists: the request said which one.
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

### 5. Two new teacher-facing endpoints on `ChapterMaterialController`

- `GET /chapter-materials/my-assignments` → `myAssignments()`:
  `TeacherUser`-only (`abort_unless`); returns
  `SubjectTeacherAssignment::forTeacher($teacher->id)` flattened into
  `{section_id, section_name, subject_id, subject_name, class_id,
  class_name, class_version_id, class_version_name, shift_id,
  shift_name}` rows — the Section→Subject picker's data source.
- `GET /chapter-materials/my-uploads` → `myUploads()`:
  `TeacherUser`-only; `ChapterMaterial::where('uploaded_by_id',
  $teacher->id)->with('chapter.book.subject')->orderByDesc('created_at')->get()`
  — feeds the "my uploads" management list (edit/delete).

### 6. `RolePermissionSeeder`

Grant `teacher` role `chapter_materials.create`, `chapter_materials.update`,
`chapter_materials.delete` (it currently has only `.view`). No change
to any other role.

## Frontend

### api-client (`frontend/packages/api-client/src/index.ts`)

- `listMyChapterMaterialAssignments(token)` → `GET /chapter-materials/my-assignments`
- `listMyUploadedChapterMaterials(token)` → `GET /chapter-materials/my-uploads`
- `createChapterMaterial(payload: FormData, token)` → multipart `POST /chapter-materials`
- `updateChapterMaterial(id, payload: FormData, token)` → multipart
  update (same `_method` override convention already used for Book PDF
  replacement)
- `deleteChapterMaterial(id, token)` → `DELETE /chapter-materials/{id}`

### Teacher portal — new page `ClassMaterialsPage.tsx`

New flat sidebar entry in `TeacherAppShell.tsx` (same tier as Marks
Entry/Marksheets), new route in `App.tsx`. Flow, mirroring
`MarksEntryPage.tsx`'s cascading-picker pattern:

1. On mount, fetch `listMyChapterMaterialAssignments` once; derive
   unique Section and (per-section) Subject options client-side, same
   `uniqueByKey` helper already used by Marks Entry.
2. Section + Subject selected → fetch Books for that subject/class
   version (`listBooks`) → Book selected → fetch Chapters
   (`listChapters({ bookId })`).
3. Upload form: type (`primary`/`supplementary`), title (required if
   supplementary), order index (optional), PDF file. Submits
   `createChapterMaterial` with `chapter_id` + the already-known
   `section_id` from step 1.
4. "My uploads" list (via `listMyUploadedChapterMaterials`) shown below
   the form: chapter/subject/section, type, filename, status, with
   edit (replace file/title/order/status) and delete actions gated the
   same way the backend now gates them (nothing extra needed
   client-side — a teacher only ever sees their own rows here).

## Testing

- `ChapterMaterialPolicyTest` (or extend the existing controller test)
  — teacher with matching `SubjectTeacherAssignment` can create;
  teacher without one (wrong section, wrong subject, or no assignment
  at all) is denied; teacher can update/delete only their own upload,
  denied on someone else's; staff behavior unchanged.
- `ChapterMaterialControllerTest` additions — `my-assignments` and
  `my-uploads` endpoints return only the acting teacher's own data,
  403 for non-teachers.
- `SubjectTeacherAssignmentTest` (or add to an existing test file) for
  the new `forTeacher()` helper.
- Frontend: a test for `ClassMaterialsPage.tsx` covering the cascading
  picker and the create-material submit, following whatever test
  convention `MarksEntryPage.test.tsx` (or equivalent) already uses.
