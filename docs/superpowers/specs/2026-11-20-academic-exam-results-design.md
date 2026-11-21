# Design: Academic Exam Results, Marksheets & Promotion

**Status:** Approved, ready for implementation planning

**Source:** User request — a system to store school academic exam
results and generate marksheets, with three or more exam terms (First
Terminal, Second Terminal, Annual/Final), nationwide grade-point
configuration, teacher mark entry, and promotion to the next class for
passing students.

## Scope of this project

Backend (migrations, models, policies, controllers, Support classes for
computation) plus all three frontends (management, teacher, student) for:

1. National config: exam terms, grade scale, per-(class, subject) full
   marks for CQ/MCQ/Practical.
2. Mark entry by subject teachers (own assignment) or a section's class
   teacher (any subject in their section), scoped per section/subject/
   exam term/academic year.
3. Publish gate: school_admin/national_admin publishes a (section, exam
   term, academic year) before students can see it.
4. Marksheet computation: per-subject grade/grade-point, overall GPA,
   overall Pass/Fail, section-wise and class-wise position — all
   computed live, never stored (same convention as `Exam::totalMarks()`).
5. Bulk promotion: for one section's Annual-term result, promote passing
   students into an already-existing target class/section for the next
   academic year, with per-student override and an audit log.

**Explicitly not in scope**, decided during brainstorming:

- No guardian-facing UI (`parent_guardian` role has no portal in this
  codebase yet — same gap left by attendance and leave applications).
- No new Shift/Section/ClassVersion creation as part of promotion — the
  target structure for the new academic year must already exist via the
  existing System Setup pages.
- No weighted/combined result across exam terms — each term stands
  alone; only the term flagged `is_annual` feeds promotion.
- No caching/materialization of GPA, grade, or position — always
  computed from current config at read time.

## Decisions confirmed during brainstorming

- **Four config/data concepts, six tables.** `academic_exam_terms`,
  `academic_grade_configures`, and `academic_subject_mark_configures`
  are all national (no `school_id`, same tier as `academic_years`/
  `holidays`) — confirmed explicitly for each during brainstorming, not
  assumed. `academic_exam_marks` is the one TenantScoped, transactional
  table. Two more tables are implementation-necessary consequences of
  the workflow the user approved, not separate top-level asks:
  `academic_exam_publications` (the publish gate) and
  `student_class_promotions` (the promotion audit log, mirroring the
  existing `student_section_transfers`).
- **Marks are split into components, not one total.** CQ/MCQ/Practical,
  each nullable per subject (a subject with no practical leaves that
  component's full marks — and every student's obtained value for it —
  null, never zero, so it's never counted toward pass/fail or the
  denominator).
- **Full marks are per (class, subject), nationwide, not per exam
  term.** The same `academic_subject_mark_configures` row is read by
  every exam term and every school.
- **Pass rule: flat 33% of full marks, per entered component.** No extra
  per-subject configurable threshold. A component with null full marks
  is skipped, never treated as a fail.
- **Grade/GPA: simple average, not board-exam style zeroing.** Each
  subject's grade point comes from its own percentage against
  `academic_grade_configures`; overall GPA is the plain average of every
  subject's grade point, computed and shown regardless of pass/fail.
  Overall **Result** (Pass/Fail) is a separate field — Pass only if every
  subject passed — and is what promotion eligibility actually reads.
- **Promotion is decided by exactly one term: the one flagged
  `is_annual`.** Enforced at the application layer: creating/updating an
  exam term rejects a second `is_annual = true` row.
- **Entry → publish is two separate actors.** Subject teachers (or a
  section's class teacher, for any subject in their own section) enter
  and freely re-save marks. A school_admin/national_admin's "publish"
  action is additive (inserts into `academic_exam_publications`) and
  gates only *visibility* to students — it does not lock the underlying
  `academic_exam_marks` rows from further correction.
- **Position is dual: section-wise and class-wise, both live-computed.**
  Class-wide ranking only includes students from sections that are
  *also* published for that same term/year — an unpublished sibling
  section is excluded from everyone else's class-wide comparison rather
  than silently counted with stale/missing data.
- **Promotion is bulk-per-section with per-student override.** Defaults
  "promote" for Pass, "retain" for Fail; admin can flip any student
  before confirming. Confirming mutates the student's existing profile
  row in place (`class_id`/`section_id`/`shift_id`/`class_version_id`),
  exactly like `StudentProfileController::transferSection()` already
  does for section moves — no new StudentProfile row, no
  `academic_year_id` added to `student_profiles` (it has never carried
  one; "current class" has always meant "whatever's on the row right
  now," consistent with how every other per-year concept in this app is
  denormalized onto the transactional row instead). `roll_number` carries
  over unchanged; adjusting it uses the existing Edit Student page.
  Retained students are left completely untouched by this action.

## Data model

All new tables follow this codebase's existing conventions precisely:
`Auditable` (polymorphic `created_by_id`/`created_by_type`/
`updated_by_id`/`updated_by_type`, no DB-level FK — same reasoning as
every table added after the user/teacher/guardian split), `SoftDeletes`,
and `TenantScoped` only where the table is genuinely per-school data.

### `academic_exam_terms` (national — no TenantScoped)

```php
Schema::create('academic_exam_terms', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->string('created_by_type')->nullable();
    $table->unsignedBigInteger('updated_by_id')->nullable();
    $table->string('updated_by_type')->nullable();

    $table->string('name');
    $table->string('bn_name')->nullable();
    $table->unsignedSmallInteger('sort_order')->default(0);
    // Exactly one active row may have this true — enforced in
    // StoreAcademicExamTermRequest/UpdateAcademicExamTermRequest, not a
    // DB constraint (a transient "no annual term yet" state during setup
    // is fine; two simultaneous ones is the only state actually forbidden).
    $table->boolean('is_annual')->default(false);
    $table->boolean('is_active')->default(true);

    $table->timestamps();
    $table->softDeletes();
});
```

`App\Models\AcademicExamTerm`: `Auditable, SoftDeletes` (no
`TenantScoped`). Fillable: `name`, `bn_name`, `sort_order`, `is_annual`,
`is_active`.

### `academic_grade_configures` (national)

```php
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
```

`App\Models\AcademicGradeConfigure`:

```php
/**
 * The grade whose [min_percent, max_percent] range contains $percent,
 * among active rows — the single lookup every grade/GPA computation in
 * this feature uses (MarksheetBuilder, promotion eligibility display).
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
```

### `academic_subject_mark_configures` (national)

```php
Schema::create('academic_subject_mark_configures', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->string('created_by_type')->nullable();
    $table->unsignedBigInteger('updated_by_id')->nullable();
    $table->string('updated_by_type')->nullable();

    $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
    $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
    // At least one of these three must be set — validated in the
    // FormRequest, not the DB (a class/subject pair with all three null
    // would silently divide by zero in every downstream percentage calc).
    $table->unsignedSmallInteger('cq_full_marks')->nullable();
    $table->unsignedSmallInteger('mcq_full_marks')->nullable();
    $table->unsignedSmallInteger('practical_full_marks')->nullable();

    $table->timestamps();
    $table->softDeletes();

    $table->unique(['class_id', 'subject_id']);
});
```

`App\Models\AcademicSubjectMarkConfigure`: fillable `class_id`,
`subject_id`, `cq_full_marks`, `mcq_full_marks`, `practical_full_marks`.
A `totalFullMarks(): int` accessor sums the non-null components.

### `academic_exam_marks` (TenantScoped — the one per-school table)

```php
Schema::create('academic_exam_marks', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->string('created_by_type')->nullable();
    $table->unsignedBigInteger('updated_by_id')->nullable();
    $table->string('updated_by_type')->nullable();

    $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
    $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
    $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
    // NOT nullable — deliberately diverges from student_leave_applications'
    // own academic_year_id (nullable there, since nothing depends on it
    // being set). Here it's one quarter of this table's own unique index;
    // a nullable FK in a unique constraint lets MySQL/SQLite insert any
    // number of "duplicate" rows that all happen to have a null year
    // (NULLs never compare equal in a unique index), silently defeating
    // the one-mark-per-student-per-subject-per-term-per-year guarantee
    // this table exists to enforce. Resolved the same way as every other
    // denormalized academic_year_id in this app — AcademicYear::where
    // ('is_current', true) — just required here instead of optional.
    $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

    // Denormalized from the student's own profile at entry time — same
    // convention as student_leave_applications/attendances, and what
    // lets a roster query ("every mark for section X, subject Y, term Z")
    // avoid joining through student_profiles every time.
    $table->foreignId('school_id')->constrained()->cascadeOnDelete();
    $table->foreignId('district_id')->constrained()->cascadeOnDelete();
    $table->foreignId('class_id')->constrained('school_classes')->cascadeOnDelete();
    $table->foreignId('section_id')->constrained()->cascadeOnDelete();
    $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
    $table->foreignId('class_version_id')->constrained()->cascadeOnDelete();

    $table->unsignedSmallInteger('cq_marks_obtained')->nullable();
    $table->unsignedSmallInteger('mcq_marks_obtained')->nullable();
    $table->unsignedSmallInteger('practical_marks_obtained')->nullable();

    // Deliberately teacher_users, not users — same reasoning as
    // student_leave_applications.reviewed_by_id (the pre-existing
    // users-table gap from before the role split has no reason to repeat).
    $table->foreignId('entered_by_id')->nullable()->constrained('teacher_users')->nullOnDelete();

    $table->timestamps();
    $table->softDeletes();

    $table->unique(['student_profile_id', 'subject_id', 'academic_exam_term_id', 'academic_year_id'], 'academic_exam_marks_unique');
    $table->index(['section_id', 'subject_id', 'academic_exam_term_id']);
});
```

`App\Models\AcademicExamMark`: `Auditable, SoftDeletes, TenantScoped`.
Fillable: every column above except the audit columns. Relations:
`studentProfile`, `subject`, `academicExamTerm`, `academicYear`,
`section`, `shift`, `classVersion`, `schoolClass`, `enteredBy`.

### `academic_exam_publications`

```php
Schema::create('academic_exam_publications', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('created_by_id')->nullable();
    $table->string('created_by_type')->nullable();
    $table->unsignedBigInteger('updated_by_id')->nullable();
    $table->string('updated_by_type')->nullable();

    $table->foreignId('section_id')->constrained()->cascadeOnDelete();
    $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
    $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
    $table->foreignId('school_id')->constrained()->cascadeOnDelete();
    $table->foreignId('district_id')->constrained()->cascadeOnDelete();
    // Deliberately nullable + no FK type restriction beyond this: the
    // publisher is always a school_admin/national_admin, i.e. App\Models\User
    // — captured via the polymorphic created_by_id/created_by_type instead
    // of a second pointer; no extra column needed here.

    $table->timestamps();
    $table->softDeletes();

    $table->unique(['section_id', 'academic_exam_term_id', 'academic_year_id'], 'academic_exam_publications_unique');
});
```

`App\Models\AcademicExamPublication`: `Auditable, SoftDeletes,
TenantScoped`. A static helper:

```php
public static function isPublished(int $sectionId, int $termId, int $yearId): bool
{
    return static::where('section_id', $sectionId)
        ->where('academic_exam_term_id', $termId)
        ->where('academic_year_id', $yearId)
        ->exists();
}
```

### `student_class_promotions` (audit log, mirrors `student_section_transfers`)

```php
Schema::create('student_class_promotions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
    $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
    $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

    $table->foreignId('from_class_id')->constrained('school_classes')->cascadeOnDelete();
    $table->foreignId('from_section_id')->constrained('sections')->cascadeOnDelete();
    // Null to_* means "retained" — a row is written for every student in
    // the batch, promoted or not, so the audit trail shows the full
    // decision, not just the ones who moved.
    $table->foreignId('to_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
    $table->foreignId('to_section_id')->nullable()->constrained('sections')->nullOnDelete();
    $table->foreignId('to_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
    $table->foreignId('to_class_version_id')->nullable()->constrained('class_versions')->nullOnDelete();

    $table->string('result'); // 'pass' | 'fail' — the computed Result at promotion time
    $table->decimal('gpa', 3, 2)->nullable();
    // Same pattern as StudentSectionTransfer.transferred_by — App\Models\User.
    $table->foreignId('promoted_by_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('promoted_at');

    $table->timestamps();
});
```

`App\Models\StudentClassPromotion`: plain model (no Auditable/
TenantScoped/SoftDeletes — matches `StudentSectionTransfer`'s own
minimal shape, an append-only log).

## Computation (`App\Support\MarksheetBuilder`)

A pure support class, precedent `StudentDashboardBuilder`/
`TimetablePeriodGrid`/`TimetableConflictChecker` — no HTTP concerns, one
entry point consumed by every controller that needs a marksheet (student
`mine()`, teacher/admin lookup, the promotion review screen's per-student
Result/GPA).

```php
/**
 * @return array{
 *   student_profile_id: int,
 *   subjects: list<array{subject_id:int, subject_name:string,
 *     cq:?int, cq_full:?int, mcq:?int, mcq_full:?int,
 *     practical:?int, practical_full:?int,
 *     total_obtained:int, total_full:int, percent:float,
 *     grade_name:?string, grade_point:?float, passed:bool}>,
 *   gpa: float,
 *   result: 'pass'|'fail',
 *   section_position: ?int,
 *   section_total_students: int,
 *   class_position: ?int,
 *   class_total_students: int,
 * }
 */
public function build(StudentProfile $profile, AcademicExamTerm $term, int $academicYearId): array
```

Internals:

- Loads every `academic_exam_marks` row for `(student_profile_id,
  academic_exam_term_id, academic_year_id)`, joined against
  `academic_subject_mark_configures` for `(class_id, subject_id)` to get
  full marks. A subject with marks entered but no config row is skipped
  from the computation (treated as not yet gradeable) rather than
  throwing — entry-time validation (see below) is what actually prevents
  this state from being reachable in practice.
- Per subject: `total_obtained` = sum of non-null obtained components;
  `total_full` = sum of non-null full-marks components; `percent =
  total_obtained / total_full * 100`; grade via
  `AcademicGradeConfigure::forPercent()`; `passed` = every non-null
  component's `obtained >= full * 0.33`, compared as a raw float with
  no rounding of the 0.33 threshold itself (e.g. 70 full marks requires
  `obtained >= 23.1`, so 23 fails and 24 passes). A not-yet-entered-but-
  configured component's obtained value defaults to 0 for this check.
  There is no entry-time validation to stay consistent with here —
  Phase 2 never implemented a 33%-threshold check anywhere else (an
  earlier spec draft note suggested one existed on the entry-time
  `RecordAcademicExamMarkRequest`; it doesn't — that FormRequest only
  validates config-existence and per-component max-value limits), so
  this threshold is purely a `MarksheetBuilder`-level decision with
  nothing upstream to match against.
- `gpa` = average of every subject's `grade_point` (0.00 if `grade_point`
  is null for a subject, e.g. a percent outside every configured range —
  treated as a data problem to flag, not silently excluded from the
  average).
- `result` = `'pass'` iff every subject's `passed` is true.
- `section_position`/`class_position`: computed by running the same
  `build()` for every other eligible student (section-mates for
  `section_position`; every student across the class's sections that are
  *also* published for this term/year, for `class_position`) and ranking
  by `(gpa desc, total_obtained_across_subjects desc)`. Null when the
  caller's own section/class isn't published yet (no fair comparison
  available). This is O(n) `build()` calls per marksheet view — acceptable
  at this app's scale (same acceptance already stated for
  `Exam::totalMarks()`-style live computation); flagged, not solved.

## Workflows → endpoints

Mirrors `StudentLeaveApplicationController`'s shape (store/mine/index +
one named action) rather than `apiResource`, since "enter marks for a
whole roster at once" and "publish a whole section" are the two
operations that actually matter, not single-row CRUD.

```
GET  /api/v1/academic-exam-terms                (public, like holidays)
POST/PUT/DELETE .../{term}                      (national_admin only)

GET  /api/v1/academic-grade-configures           (public)
POST/PUT/DELETE .../{id}                         (national_admin only)

GET  /api/v1/academic-subject-mark-configures    (public; filterable by class_id)
POST/PUT/DELETE .../{id}                         (national_admin only)

GET  /api/v1/academic-exam-marks/roster          (?section_id=&subject_id=&academic_exam_term_id=&academic_year_id=
                                                    -> full roster + existing marks, can_record flag)
POST /api/v1/academic-exam-marks/record          (bulk upsert, same section_id/subject_id/term/year + records[].
                                                    Rejected outright, 422, if (class_id, subject_id) has no
                                                    academic_subject_mark_configures row yet — this is the
                                                    "entry-time validation" MarksheetBuilder's own doc comment
                                                    refers to: it's what keeps "marks exist but no config" from
                                                    being reachable in the first place, so MarksheetBuilder only
                                                    has to skip that case defensively, never actually hit it.)

GET  /api/v1/academic-exam-marks/marksheet       (?student_profile_id=&academic_exam_term_id=&academic_year_id=
                                                    -> MarksheetBuilder::build() output; policy-gated per viewer)
GET  /api/v1/academic-exam-marks/mine             (student's own; 404/empty unless published)

GET  /api/v1/academic-exam-publications/status   (?section_id=&academic_exam_term_id=&academic_year_id=
                                                    -> per-subject entered-vs-roster-size counts + is_published)
POST /api/v1/academic-exam-publications          (publish; school_admin/national_admin)

GET  /api/v1/student-promotions/preview          (?section_id=&academic_exam_term_id=&academic_year_id=
                                                    -> roster + computed result/gpa + suggested target class)
POST /api/v1/student-promotions                   (confirm; writes profile mutation + log rows, bulk)
```

`AttendanceController`'s `rosterRows()`/`record()` shape (holiday-style
block list aside — there's no "holiday" equivalent here) is the direct
template for `/academic-exam-marks/roster` and `/record`.

## Permissions & policies

New permissions (`PermissionSeeder`), following the existing
`.view`/`.create`/`.update`/`.delete` convention:

- `academic_exam_terms.*`, `academic_grade_configures.*`,
  `academic_subject_mark_configures.*` — mutate: `national_admin` only
  (`RolePermissionSeeder`, same tier as `holidays.*`); `.view` granted
  broadly alongside other national reference-data permissions.
- `academic_exam_marks.view` / `.update` — reach the endpoints;
  `AcademicExamMarkPolicy::recordSection(Authenticatable $user, Section
  $section, int $subjectId)` gates the specific section+subject:
  `TeacherUser` → true if `class_teacher_id === $user->id` **or** a
  `subject_teacher_assignments` row exists for `(section, subject,
  teacher)`; any other actor → `hasPermission('academic_exam_marks.update')`.
  A parallel `viewSection()` is permission-only-or-assignment, no
  publish-state check (same split as `AttendancePolicy`).
- `academic_exam_marks.publish` — school_admin/national_admin only.
- `student_promotions.create` — school_admin/national_admin only (new,
  granularity precedent: `sections.override_capacity`).

Marksheet viewing: student via `mine()` (self, requires
`AcademicExamPublication::isPublished()`); teacher via a new, broader
`AcademicExamMarkPolicy::viewMarksheet()` method — not the per-subject
`viewSection()` from entry. `viewMarksheet()` grants whole-section
visibility: any relationship to the section at all (class teacher, or
a `SubjectTeacherAssignment` for any single subject) grants access to
every subject's marks in that student's full marksheet, since a report
card spans every subject and per-subject gating doesn't make sense for
it — a teacher assigned only to English, say, can still see the Math
row on the same student's marksheet; staff via `academic_exam_marks.view`,
TenantScope-limited, no publish-state requirement (so admins can audit
before publishing).

## Frontend

**Management** (`GeoCrudTable`, national_admin-gated, under System
Setup — same shape as Holiday Calendar): Exam Terms, Grade
Configuration, Subject Mark Configuration. Plus two bespoke pages:
**Publish Results** (school/class/section/term picker → per-subject
entered-count table → Publish button) and **Promote Students** (section/
year/annual-term picker → roster with Result/GPA + promote/retain
override per row → target class/section pickers → Confirm).

**Teacher** (new sidebar submenu, same shape as the existing Attendance
submenu): **Marks Entry** (section + subject + exam term cascading
filters, scoped to what the actor can record — reusing the
`useSectionCascadeFilter`-style pattern already built for attendance;
roster with CQ/MCQ/Practical inputs per student; Save/Update, same
toast-feedback pattern as Take Attendance) and **Marksheets** (lookup
within allowed scope, printable).

**Student**: **Results** page — list of published exam terms, each
opening a printable marksheet (subject table, GPA, Result, section/class
position) using the same `print:hidden` + A4 print convention already
used for Class Routine and the Attendance Report.

## Testing

Backend feature tests, one file per controller area (matching this
codebase's one-test-class-per-controller convention):
`AcademicExamTermControllerTest`, `AcademicGradeConfigureControllerTest`,
`AcademicSubjectMarkConfigureControllerTest`,
`AcademicExamMarkControllerTest` (roster/record scoping — class teacher
vs subject-teacher vs unrelated teacher, same shape as
`AttendanceControllerTest`), `AcademicExamPublicationControllerTest`,
`StudentPromotionControllerTest` (pass defaults to promote, fail
defaults to retain, override flips either, retained students untouched,
audit rows written for the whole batch including retained ones). A
dedicated `MarksheetBuilderTest` (unit-level) for the grade/GPA/pass/
position math itself, independent of HTTP — same precedent as a pure
Support class getting its own direct test rather than only being
exercised through a controller test.
