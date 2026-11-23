# Design: Rename `class_version_id` to `version_id` Across All Tables

**Status:** Approved, ready for implementation planning

## Scope

`class_versions` is the per-school/class/version mapping table (see
`docs/superpowers/specs/2026-11-23-national-version-catalog-design.md`). Ten
other tables carry a foreign key to it named `class_version_id` (one carries
`to_class_version_id`), and since `class_versions` itself now also owns a
column named `version_id` (pointing at the separate, national `versions`
catalog), having a *different* column also called `class_version_id`
scattered across ten other tables is an inconsistent name for "this row's
class_versions.id". This spec renames it to `version_id` (and
`to_class_version_id` to `to_version_id`) everywhere, with no change to what
it points at — every renamed column still has a foreign key to
`class_versions.id`. This is a pure rename for naming consistency, not a
retargeting.

**Decision confirmed during brainstorming:** relation methods named
`classVersion()` (nine of them) and `toClassVersion()` (one) are renamed to
`version()`/`toVersion()` too — leaving the method name stale while the
column underneath changes would be a half-finished rename. This does
produce one naming collision in spirit (not in code — different classes):
`ClassVersion::version()` already means "the linked row in the national
`versions` catalog" (from the prior program), while e.g. `Shift::version()`
means "the linked row in `class_versions`". Each is correct and
unambiguous in its own model's context; a future reader needs to know
which model they're on, same as `->school` meaning different things on
different models throughout this app already.

## Exact scope

### Columns renamed (DB)

| Table | Column | New name | Nullable | On delete |
|---|---|---|---|---|
| `shifts` | `class_version_id` | `version_id` | no | cascade |
| `version_subjects` | `class_version_id` | `version_id` | no | cascade |
| `books` | `class_version_id` | `version_id` | no | cascade |
| `exams` | `class_version_id` | `version_id` | no | cascade |
| `academic_exam_marks` | `class_version_id` | `version_id` | no | cascade |
| `attendances` | `class_version_id` | `version_id` | no | cascade |
| `student_leave_applications` | `class_version_id` | `version_id` | no | cascade |
| `class_routines` | `class_version_id` | `version_id` | yes | null |
| `chapter_materials` | `class_version_id` | `version_id` | yes | null |
| `student_profiles` | `class_version_id` | `version_id` | yes | null |
| `student_class_promotions` | `to_class_version_id` | `to_version_id` | yes | null |

All 11 keep their existing foreign key to `class_versions.id` and their
existing unique/index membership (e.g. `shifts`' `unique(['class_version_id',
'name'])` becomes a unique on `['version_id', 'name']` covering the same
rows) — only the column's name changes. MySQL 8's native `RENAME COLUMN`
(which this project's Laravel version uses natively, no `doctrine/dbal`
needed) preserves the column's foreign key and index membership
automatically when the column is renamed; this must still be verified
against real MySQL, not assumed from a clean SQLite run, per this
project's own documented SQLite/MySQL migration-behavior gap.

### Relations renamed (model methods)

`classVersion(): BelongsTo` → `version(): BelongsTo` on: `Shift`,
`Book`, `Exam`, `AcademicExamMark`, `Attendance`, `StudentLeaveApplication`,
`ClassRoutine`, `ChapterMaterial`, `StudentProfile`.

`toClassVersion(): BelongsTo` → `toVersion(): BelongsTo` on
`StudentClassPromotion`.

`ClassVersion::subjects()` (`belongsToMany(Subject::class,
'version_subjects')`) must gain explicit pivot FK arguments — Eloquent's
default pivot-key convention is derived from the model's class name
(`ClassVersion` → `class_version_id`), not from any column that happens to
exist, so renaming the pivot table's column silently breaks this relation
unless the FK is now passed explicitly: `belongsToMany(Subject::class,
'version_subjects', 'version_id', 'subject_id')`.

### Everywhere else the column name appears (confirmed by direct grep, not estimated)

- **Models' `$fillable`:** `Shift`, `ClassRoutine`, `ChapterMaterial`,
  `StudentProfile`, `Exam`, `Book`, `AcademicExamMark`, `Attendance`,
  `StudentLeaveApplication`, `StudentClassPromotion`.
- **`Exam::eligibleRandomQuestionIds()`** — a raw `->where('class_version_id',
  $this->class_version_id)` query inside `Exam.php` itself.
- **FormRequests:** `StoreShiftRequest`, `UpdateShiftRequest`,
  `StoreBookRequest`, `UpdateBookRequest`, `StoreExamRequest`,
  `UpdateExamRequest`, `SetExamQuestionsRequest`,
  `SetExamRandomChaptersRequest` — validation rules, uniqueness scoping,
  and `authorize()` lookups keyed on the old name. `StoreClassRoutineRequest`
  and `StoreStudentProfileRequest` only have a comment referencing the old
  name (no code), still worth correcting for a future reader.
- **Controllers:** `ShiftController`, `SectionController`,
  `BookController`, `ExamController`, `ExamAttemptController`,
  `ClassRoutineController`, `ChapterMaterialController`,
  `AttendanceController`, `AcademicExamMarkController`,
  `AcademicExamPublicationController`, `StudentLeaveApplicationController`,
  `StudentProfileController`, `PracticeExamController`,
  `StudentPromotionController`, `SubjectTeacherAssignmentController` —
  query filters (`?class_version_id=`), attribute assembly on
  create/update, denormalization logic that reads
  `$shift->class_version_id`/`$section->shift?->class_version_id`, and
  (confirmed by a second, broader grep for `classVersion` alone, not just
  `class_version_id` — this reaches further than the column name does)
  every eager-load path segment naming the relation: `->with(['classVersion',
  ...])`, `'shift.classVersion'`, `'section.shift.classVersion'`,
  `'book.classVersion'`, and bare `$x->classVersion` chains that read
  `->schoolClass`/`->class_id` off the loaded relation without the column
  ever appearing on the same line.
- **Three `Support` classes** reached only through the relation chain, no
  `class_version_id` column of their own: `TimetableGenerator`,
  `TimetableConflictChecker` (both read `$shift->classVersion` directly),
  `MarksheetBuilder` (`whereHas('shift.classVersion', ...)`).
- **`SubjectTeacherAssignment` model** — a default eager-load scope
  (`'section.shift.classVersion.schoolClass'`), not a column.
- **Two more FormRequests** found the same way:
  `PublishAcademicExamRequest`, `RecordAcademicExamMarkRequest` — both
  read `$section?->shift?->classVersion?->class_id`.
- **Resources:** `ShiftResource`, `BookResource`, `ExamResource`,
  `ClassRoutineResource`, `ChapterMaterialResource`, `StudentProfileResource`,
  `StudentLeaveApplicationResource` — the `class_version_id` JSON key, and
  on five of these a nested `'class_version' => new
  ClassVersionResource($this->whenLoaded('classVersion'))` key (becomes
  `'version' => new ClassVersionResource($this->whenLoaded('version'))`,
  matching the renamed relation).
- **Flat (non-Resource) array responses** that pair an id with a display
  name the same way a Resource would, just built by hand: three controllers
  (`ChapterMaterialController`, `AttendanceController` ×2 call sites,
  `AcademicExamMarkController`) build `'class_version_id' => ...,
  'class_version' => $section->shift?->classVersion?->name` — both keys
  rename, matching the same id/label pairing convention as their sibling
  `shift_id`/`shift` and `academic_year_id`/`academic_year` keys in the
  same arrays.
- **`ChapterMaterialPolicy`** — two comparisons:
  `$chapterMaterial->class_version_id === $profile->class_version_id` and
  `$section->shift?->class_version_id === $chapter->book->class_version_id`.
- **`StudentDashboardBuilder`** — a `Support` class (not a Resource)
  building its own response array, five occurrences including a
  `->where('class_version_id', $classVersionId)` query twice.
- **Seeders:** `LmsContentDemoSeeder`, `ClassRoutineSeeder`,
  `StudentDemoSeeder`, `SectionBackfillSeeder`,
  `TimetableGenerationDemoSeeder`, `AcademicExamResultsDemoSeeder`.
- **~50 backend test files** across `tests/Feature` and `tests/Unit`
  (confirmed by the same grep run during brainstorming) — fixture
  construction (`Shift::create(['class_version_id' => ...])`), assertion
  paths (`assertJsonPath('data.class_version_id', ...)`), and query-string
  filters in HTTP test calls.
- **Frontend `packages/api-client/src/index.ts`** — every affected type's
  `class_version_id: number | null`/`class_version_id: number` field,
  every `class_version?: ClassVersion | null` nested field, the two flat
  `class_version: string | null` display-label fields (on
  `ChapterMaterialAssignment` and `AttendanceSection`, mirroring the
  backend's flat-array convention above), every `listX()` function's
  `classVersionId` parameter and its `class_version_id: params.classVersionId`
  query-string mapping, and every payload type's `class_version_id` field.
- **26 frontend page/lib files** across `apps/management`, `apps/teacher`,
  and `apps/student` that consume any of the above (confirmed by grep
  during brainstorming) — filters, table columns, and display labels built
  from the renamed fields.

## Rename rule (exact, for every implementer)

**Rename these tokens, everywhere they appear in this form:**
- The string `'class_version_id'` as a request input key, array key,
  column name in a query/validation rule, or query-string param →
  `'version_id'`. Same for `'to_class_version_id'` → `'to_version_id'`.
- The property access `->class_version_id` / `->to_class_version_id` →
  `->version_id` / `->to_version_id`.
- The relation method definition and every call site:
  `classVersion()`/`->classVersion`/`->classVersion()`/`whenLoaded('classVersion')`/
  `with('classVersion')`/`load('classVersion')`, including as a segment of a
  dotted eager-load path (`'shift.classVersion'`,
  `'section.shift.classVersion'`, `'book.classVersion'`,
  `'classVersion.schoolClass'`) → `version` in every one of those forms.
  Same for `toClassVersion` → `toVersion`.
- The camelCase variable name `$classVersionId`/`classVersionId` (holding
  an id, e.g. a closure parameter) → `$versionId`/`versionId`.
- The JSON/array key `'class_version'` (a nested Resource object, or a
  flat display-label string sibling of `class_version_id` built by hand)
  → `'version'`.

**Never rename these — they are a different thing with the same English
words in it:**
- `ClassVersion` the class name (`ClassVersion::class`, `use
  App\Models\ClassVersion;`, `new ClassVersionResource(...)`,
  `ClassVersionController`, `ClassVersionPolicy`,
  `Store`/`UpdateClassVersionRequest`, a type-hint `ClassVersion $x`).
- A local variable merely *typed as* `ClassVersion`, e.g. `$classVersion =
  ClassVersion::findOrFail(...)` — the variable name does not need to
  change; only what's read off it (`$classVersion->class_version_id`
  becomes `$classVersion->version_id`, but `$classVersion` itself stays
  `$classVersion`). This is deliberate, not an inconsistency: the
  variable describes its *type*, which hasn't changed.
- The `class_versions` table and the `ClassVersion` model/concept itself —
  unaffected by this rename.
- `ClassVersion::version()` / `class_versions.version_id` — already
  correctly named from the national-version-catalog program (points at
  the separate `versions` catalog); do not touch.

## Out of scope

- The `versions` table and `ClassVersion::version()` relation added by the
  national-version-catalog program — already correctly named, untouched.
- Any change to what `class_versions.id` is reachable from, or to the
  `class_versions` table's own columns — this is a rename only, the
  mapping stays exactly as it is.
- `SubjectTeacherAssignment` — confirmed by direct migration grep to have
  no `class_version_id` column at all (it's reached only via
  `section→shift→classVersion`, which already goes through the renamed
  relation chain with no column of its own to rename).

## Definition of done

- All 11 columns renamed in a single migration, verified end-to-end
  against real MySQL (create, read, write, and the affected unique
  constraints all still function under the new name) — not just a clean
  SQLite run.
- All 10 relation methods renamed; `ClassVersion::subjects()` passes
  explicit pivot FK arguments.
- Every FormRequest, Controller, Resource, Policy, and Support class
  listed above reads/writes/validates/filters by the new column and
  relation names, with no remaining reference to the old names anywhere
  in `app/`.
- All seeders updated; the full `DatabaseSeeder` chain runs cleanly.
- Full backend test suite passes against both SQLite and real MySQL.
- `api-client` and all 26 consuming frontend files use the new field
  names; frontend build is clean across all three apps.
- A final fresh grep for `class_version_id`/`classVersionId`/`classVersion`-
  as-a-relation-or-key across the whole repo turns up nothing outside of
  this spec's documented out-of-scope items.
