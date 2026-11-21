# Academic Exam Results — Phase 2: Mark Entry & Publish Workflow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let subject teachers (and a section's class teacher) enter exam marks for their roster, and let a school_admin/national_admin publish a (section, exam term, academic year) once entry is done — without yet building marksheet computation or promotion (later phases).

**Architecture:** Two independent TenantScoped resources, each following the existing `AttendanceController`/`AttendancePolicy`/`RecordAttendanceRequest` pattern precisely: a roster-based bulk read/write endpoint pair for marks (`academic_exam_marks`), and a tiny additive publish-gate resource (`academic_exam_publications`) that never touches the marks table. Mark entry's authorization differs from attendance in one deliberate way — a subject teacher may both *view and record* their own assigned subject (not view-only, as attendance's subject-teacher is), per the spec's explicit "subject teacher enters their own; class teacher enters any subject in their section" rule.

**Tech Stack:** Laravel 13 / PHP 8.5 (backend), React + TypeScript + Vite (teacher + management frontends), MySQL in production / SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-11-20-academic-exam-results-design.md` — this plan implements the `academic_exam_marks` and `academic_exam_publications` rows of "Data model", the mark-entry/publish rows of "Workflows → endpoints", the `academic_exam_marks.*`/`academic_exam_marks.publish` rows of "Permissions & policies", and the Teacher "Marks Entry" + Management "Publish Results" rows of "Frontend". Everything else (`MarksheetBuilder`, marksheet viewing, promotion) is out of scope — later phases.

## Global Constraints

- Both new tables are **per-school** — `Auditable` + `SoftDeletes` + `TenantScoped` (unlike Phase 1's three national tables).
- `academic_exam_marks.academic_year_id` is **NOT NULL** — it is one quarter of the table's own unique index, and a nullable FK inside a unique constraint would let MySQL/SQLite insert unlimited "duplicate" rows that all happen to have a null year.
- **Teacher-side authorization is symmetric between view and record**, unlike `AttendancePolicy` (where a subject-assigned teacher can view but never record): a `TeacherUser` may both view AND record for `(section, subject)` if they are the section's `class_teacher_id`, **or** hold a `subject_teacher_assignments` row for that exact `(section, subject)` pair. A class teacher may touch *any* subject configured for their section's class; a subject teacher may touch *only* their own assigned subject — never a different one in the same section. For a non-teacher actor, viewing requires `academic_exam_marks.view` and recording requires `academic_exam_marks.update`.
- **`academic_exam_marks.view`/`.update` are granted only to `school_admin` and `teacher`** (plus `national_admin` automatically via its catalog-wide grant) — **not** broadly to every role the way `attendances.view` is. This is a deliberate departure from the `attendances.*` precedent: the spec frames this permission as a *staff audit* capability over raw, possibly-unpublished marks ("so admins can audit before publishing"), not a universally viewable record like attendance. Granting it to `student`/`parent_guardian`/etc. would let those roles read a section's ungraded marks directly, bypassing the publish gate entirely.
- **`academic_exam_marks.publish` is granted only to `school_admin`** (plus `national_admin` automatically) — never to `teacher`.
- **Entry-time validation**: recording marks for a `(class_id, subject_id)` pair with no existing `academic_subject_mark_configures` row is rejected outright with a 422 — this is the "entry-time validation" the Phase 1 spec's `MarksheetBuilder` doc comment already refers to, and it must land in this phase so that state is never reachable once `MarksheetBuilder` exists.
- **Per-component validation**: a non-null obtained value for a component whose configured full marks is null is rejected (422); an obtained value exceeding its component's configured full marks is rejected (422).
- **Publishing is additive only** — it inserts into `academic_exam_publications`; it never locks, modifies, or deletes any `academic_exam_marks` row. Publishing an already-published `(section, term, year)` is a no-op (still 201), not an error.
- After any backend PHP change: `vendor/bin/pint --dirty --format agent` must report `{"result":"passed"}`, and `php artisan test --compact` must stay fully green (not just the new test file).
- After any frontend change: `npx tsc -b --noEmit` (run from the specific app directory touched) must produce no output, and `npm run build` (same directory) must succeed.
- Every new/changed i18n key must exist in **both** `packages/i18n/src/common/en.json` and `packages/i18n/src/common/bn.json` with a real Bengali translation (not a placeholder, not copied English) — verify with:
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
- Never commit without the relevant checks above passing for whatever you touched in that commit.

---

### Task 1: `academic_exam_marks` backend (migration, model, policy, request, controller, routes, tests)

**Files:**
- Create (via `php artisan make:migration create_academic_exam_marks_table --no-interaction`, then edit): `backend/database/migrations/<timestamp>_create_academic_exam_marks_table.php`
- Create: `backend/app/Models/AcademicExamMark.php`
- Create: `backend/app/Policies/AcademicExamMarkPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/RecordAcademicExamMarkRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php`
- Create: `backend/tests/Feature/AcademicExamMarkControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `App\Models\AcademicExamMark` (`Auditable, SoftDeletes, TenantScoped`). Routes (all `auth:sanctum`): `GET /api/v1/academic-exam-marks/my-assignments`, `GET /api/v1/academic-exam-marks/roster`, `POST /api/v1/academic-exam-marks/record`.
- Consumes: `App\Models\Section` (`class_teacher_id`, `shift` relation), `App\Models\SubjectTeacherAssignment` (`section_id`, `subject_id`, `teacher_id`), `App\Models\AcademicSubjectMarkConfigure` (full marks per `class_id`/`subject_id`, from Phase 1 — already committed), `App\Models\StudentProfile` (`section_id`, `roll_number`), `App\Models\TeacherUser` — all existing, no changes needed to any of them.

- [ ] **Step 1: Generate and write the migration**

```bash
cd backend && php artisan make:migration create_academic_exam_marks_table --no-interaction
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
     * Per-school, transactional — the one TenantScoped table in this
     * feature. academic_year_id is NOT nullable: it is one quarter of
     * this table's own unique index, and a nullable FK inside a unique
     * constraint would let MySQL/SQLite insert unlimited "duplicate" rows
     * that all happen to have a null year (NULLs never compare equal in
     * a unique index) — silently defeating the one-mark-per-student-per-
     * subject-per-term-per-year guarantee this table exists to enforce.
     */
    public function up(): void
    {
        Schema::create('academic_exam_marks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();

            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

            // Denormalized from the student's own profile at entry time —
            // same convention as attendances/student_leave_applications,
            // and what lets a roster query ("every mark for section X,
            // subject Y, term Z") avoid joining through student_profiles.
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
            // student_leave_applications.reviewed_by_id.
            $table->foreignId('entered_by_id')->nullable()->constrained('teacher_users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['student_profile_id', 'subject_id', 'academic_exam_term_id', 'academic_year_id'], 'academic_exam_marks_unique');
            $table->index(['section_id', 'subject_id', 'academic_exam_term_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_exam_marks');
    }
};
```

- [ ] **Step 2: Create the model**

`backend/app/Models/AcademicExamMark.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicExamMark extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'student_profile_id',
        'subject_id',
        'academic_exam_term_id',
        'academic_year_id',
        'school_id',
        'district_id',
        'class_id',
        'section_id',
        'shift_id',
        'class_version_id',
        'cq_marks_obtained',
        'mcq_marks_obtained',
        'practical_marks_obtained',
        'entered_by_id',
    ];

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicExamTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicExamTerm::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(TeacherUser::class, 'entered_by_id');
    }
}
```

- [ ] **Step 3: Migrate and confirm the table exists**

```bash
cd backend && php artisan migrate --no-interaction
```

Expected: `academic_exam_marks ... DONE` in the output, no errors.

- [ ] **Step 4: Write the failing feature test**

`backend/tests/Feature/AcademicExamMarkControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicExamTerm;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicExamMarkControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private Subject $subject;

    private AcademicExamTerm $term;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $this->term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
    }

    private function studentInSection(?string $rollNumber = '1'): StudentUser
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'roll_number' => $rollNumber,
        ]);

        return $student;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayload(int $studentProfileId, array $overrides = []): array
    {
        return [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [
                [
                    'student_profile_id' => $studentProfileId,
                    'cq_marks_obtained' => 60,
                    'mcq_marks_obtained' => 25,
                    'practical_marks_obtained' => null,
                    ...$overrides,
                ],
            ],
        ];
    }

    public function test_the_class_teacher_can_record_marks_for_any_configured_subject_in_their_section(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertOk()
            ->assertJsonPath('data.students.0.cq_marks_obtained', 60);

        $this->assertDatabaseHas('academic_exam_marks', [
            'student_profile_id' => $profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'cq_marks_obtained' => 60,
            'mcq_marks_obtained' => 25,
            'entered_by_id' => $classTeacher->id,
        ]);
    }

    public function test_a_subject_teacher_can_record_marks_for_their_own_assigned_subject(): void
    {
        $subjectTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $subjectTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($subjectTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertOk();

        $this->assertDatabaseHas('academic_exam_marks', ['student_profile_id' => $profile->id, 'entered_by_id' => $subjectTeacher->id]);
    }

    public function test_a_teacher_with_no_relation_to_the_section_and_subject_cannot_record(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($unrelatedTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertForbidden();
    }

    public function test_a_subject_teacher_cannot_record_a_different_subject_in_the_same_section(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $mathTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $mathTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($mathTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', [
            'section_id' => $this->section->id,
            'subject_id' => $otherSubject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [['student_profile_id' => $profile->id, 'cq_marks_obtained' => 80]],
        ])->assertForbidden();
    }

    public function test_recording_again_updates_the_existing_row_instead_of_duplicating(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['cq_marks_obtained' => 50]))->assertOk();
        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['cq_marks_obtained' => 65]))->assertOk();

        $this->assertDatabaseCount('academic_exam_marks', 1);
        $this->assertDatabaseHas('academic_exam_marks', ['student_profile_id' => $profile->id, 'cq_marks_obtained' => 65]);
    }

    public function test_record_is_rejected_when_no_subject_mark_configure_exists(): void
    {
        $unconfigured = Subject::create(['name' => 'Art']);

        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', [
            'section_id' => $this->section->id,
            'subject_id' => $unconfigured->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [['student_profile_id' => $profile->id, 'cq_marks_obtained' => 50]],
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_record_rejects_obtained_marks_exceeding_full_marks(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['cq_marks_obtained' => 90]))
            ->assertUnprocessable()->assertJsonValidationErrors('records.0.cq_marks_obtained');
    }

    public function test_record_rejects_a_component_not_configured_for_the_subject(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        // practical_full_marks is null for this subject's config.
        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['practical_marks_obtained' => 10]))
            ->assertUnprocessable()->assertJsonValidationErrors('records.0.practical_marks_obtained');
    }

    public function test_my_assignments_includes_every_subject_for_a_class_teachers_section(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $response = $this->actingAs($classTeacher, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/my-assignments')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $subjectIds = collect($response->json('data'))->pluck('subject_id')->sort()->values()->all();
        $this->assertSame([$this->subject->id, $otherSubject->id], $subjectIds);
    }

    public function test_my_assignments_includes_only_the_assigned_subject_for_a_subject_teacher(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $subjectTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $subjectTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->actingAs($subjectTeacher, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/my-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', $this->subject->id);
    }

    public function test_a_school_admin_can_view_and_record_without_a_direct_assignment(): void
    {
        $student = $this->studentInSection();
        $profile = $student->profile;

        $schoolAdmin = $this->actorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertOk();
    }
}
```

- [ ] **Step 5: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=AcademicExamMarkControllerTest
```

Expected: FAIL — route/model/policy/request/controller don't exist yet.

- [ ] **Step 6: Write the policy**

`backend/app/Policies/AcademicExamMarkPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Models\Section;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use Illuminate\Contracts\Auth\Authenticatable;

class AcademicExamMarkPolicy
{
    /**
     * Whether $user may view marks for $section/$subjectId — the
     * section's own class teacher, a teacher holding a
     * SubjectTeacherAssignment for this exact (section, subject), or a
     * staff actor with academic_exam_marks.view (TenantScope-limited via
     * $section's own lookup). No publish-state check — staff may audit
     * entered-but-unpublished marks.
     */
    public function viewSection(Authenticatable $user, Section $section, int $subjectId): bool
    {
        return $this->canTouch($user, $section, $subjectId, 'academic_exam_marks.view');
    }

    /**
     * Whether $user may enter/update marks for $section/$subjectId —
     * identical teacher-side condition to viewSection() (unlike
     * AttendancePolicy, a subject teacher may both view AND record their
     * own subject, per the explicit "class teacher enters any subject;
     * subject teacher enters their own" rule), differing only in which
     * permission a staff actor needs.
     */
    public function recordSection(Authenticatable $user, Section $section, int $subjectId): bool
    {
        return $this->canTouch($user, $section, $subjectId, 'academic_exam_marks.update');
    }

    private function canTouch(Authenticatable $user, Section $section, int $subjectId, string $staffPermission): bool
    {
        if ($user instanceof TeacherUser) {
            return $section->class_teacher_id === $user->id
                || SubjectTeacherAssignment::where('section_id', $section->id)
                    ->where('subject_id', $subjectId)
                    ->where('teacher_id', $user->id)
                    ->exists();
        }

        return $user->hasPermission($staffPermission);
    }
}
```

- [ ] **Step 7: Write the form request**

`backend/app/Http/Requests/Api/V1/RecordAcademicExamMarkRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamMark;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RecordAcademicExamMarkRequest extends FormRequest
{
    /**
     * section_id/subject_id are resolved here (not route-bound — this is
     * a bulk write, not a single-model route), same pattern as
     * RecordAttendanceRequest.
     */
    public function authorize(): bool
    {
        $section = Section::find($this->integer('section_id'));

        return $section !== null
            && ($this->user()?->can('recordSection', [AcademicExamMark::class, $section, $this->integer('subject_id')]) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'academic_exam_term_id' => ['required', 'integer', 'exists:academic_exam_terms,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_profile_id' => ['required', 'integer'],
            'records.*.cq_marks_obtained' => ['nullable', 'integer', 'min:0'],
            'records.*.mcq_marks_obtained' => ['nullable', 'integer', 'min:0'],
            'records.*.practical_marks_obtained' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Rejected outright if (class_id, subject_id) has no
     * academic_subject_mark_configures row — the "entry-time validation"
     * MarksheetBuilder's own doc comment relies on (added in a later
     * phase), so that "marks exist but no config" state is never
     * reachable. Each component's obtained value is then checked against
     * that same config's full marks: null full marks + a non-null
     * obtained value is rejected (nothing configured to grade against); a
     * non-null full marks value caps the obtained value at that ceiling.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $section = Section::find($this->integer('section_id'));
            $classId = $section?->shift?->classVersion?->class_id;

            $configure = AcademicSubjectMarkConfigure::where('class_id', $classId)
                ->where('subject_id', $this->integer('subject_id'))
                ->first();

            if ($configure === null) {
                $validator->errors()->add('subject_id', 'No full marks are configured for this class and subject yet.');

                return;
            }

            $components = [
                'cq_marks_obtained' => $configure->cq_full_marks,
                'mcq_marks_obtained' => $configure->mcq_full_marks,
                'practical_marks_obtained' => $configure->practical_full_marks,
            ];

            foreach ($this->array('records') as $index => $record) {
                foreach ($components as $field => $fullMarks) {
                    $obtained = $record[$field] ?? null;

                    if ($obtained === null) {
                        continue;
                    }

                    if ($fullMarks === null) {
                        $validator->errors()->add("records.{$index}.{$field}", 'This component is not configured for this subject.');
                    } elseif ($obtained > $fullMarks) {
                        $validator->errors()->add("records.{$index}.{$field}", "Obtained marks cannot exceed the full marks ({$fullMarks}).");
                    }
                }
            }
        });
    }
}
```

- [ ] **Step 8: Write the controller**

`backend/app/Http/Controllers/Api/V1/AcademicExamMarkController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordAcademicExamMarkRequest;
use App\Models\AcademicExamMark;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicExamMarkController extends Controller
{
    /**
     * Every (section, subject) combination this teacher may touch for
     * mark entry — the class teacher of a section gets one row per
     * subject configured for that section's class; a subject teacher
     * gets one row per their own SubjectTeacherAssignment. Deduplicated
     * by (section_id, subject_id) so a teacher who is both the class
     * teacher AND separately assigned the same subject sees it once.
     * Mirrors AttendanceController::mySections(), extended with the
     * subject dimension mark entry needs that attendance doesn't.
     */
    public function myAssignments(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $classTeacherSections = Section::query()
            ->with(['shift.classVersion.schoolClass', 'shift.academicYear'])
            ->where('class_teacher_id', $teacher->id)
            ->get();

        $assignmentRows = SubjectTeacherAssignment::query()
            ->with(['section.shift.classVersion.schoolClass', 'section.shift.academicYear', 'subject'])
            ->where('teacher_id', $teacher->id)
            ->get();

        $rows = [];

        foreach ($classTeacherSections as $section) {
            $classId = $section->shift?->classVersion?->class_id;
            $subjects = AcademicSubjectMarkConfigure::where('class_id', $classId)->with('subject')->get();

            foreach ($subjects as $configure) {
                if ($configure->subject === null) {
                    continue;
                }

                $rows[$section->id.'-'.$configure->subject_id] = $this->assignmentRow($section, $configure->subject);
            }
        }

        foreach ($assignmentRows as $assignment) {
            $section = $assignment->section;

            if ($section === null || $assignment->subject === null) {
                continue;
            }

            $rows[$section->id.'-'.$assignment->subject_id] ??= $this->assignmentRow($section, $assignment->subject);
        }

        return response()->json(['data' => array_values($rows)]);
    }

    /**
     * The full roster for a (section, subject, exam term, academic year)
     * — every student in the section, each with whatever marks already
     * exist for this exact combination, plus the subject's configured
     * full marks so the caller can render limits without a second call.
     */
    public function roster(Request $request): JsonResponse
    {
        $section = Section::with('classTeacher')->findOrFail($request->integer('section_id'));
        $subjectId = $request->integer('subject_id');

        $this->authorize('viewSection', [AcademicExamMark::class, $section, $subjectId]);

        return response()->json([
            'data' => $this->rosterPayload($request, $section, $subjectId, canRecord: $request->user()->can('recordSection', [AcademicExamMark::class, $section, $subjectId])),
        ]);
    }

    /**
     * Bulk upsert marks for a (section, subject, exam term, academic
     * year) — one row per student. Re-saving simply updates the existing
     * row (matched on the table's own unique index), never duplicates —
     * same approach as AttendanceController::record().
     */
    public function record(RecordAcademicExamMarkRequest $request): JsonResponse
    {
        $section = Section::with('classTeacher')->findOrFail($request->integer('section_id'));
        $subjectId = $request->integer('subject_id');
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');

        $shift = $section->shift;
        $validStudentIds = StudentProfile::where('section_id', $section->id)->pluck('id');
        $actor = $request->user();

        foreach ($request->array('records') as $record) {
            $studentProfileId = (int) ($record['student_profile_id'] ?? 0);

            if (! $validStudentIds->contains($studentProfileId)) {
                continue;
            }

            $attributes = [
                'school_id' => $section->school_id,
                'district_id' => $section->district_id,
                'class_id' => $shift?->classVersion?->class_id,
                'section_id' => $section->id,
                'shift_id' => $section->shift_id,
                'class_version_id' => $shift?->class_version_id,
                'cq_marks_obtained' => $record['cq_marks_obtained'] ?? null,
                'mcq_marks_obtained' => $record['mcq_marks_obtained'] ?? null,
                'practical_marks_obtained' => $record['practical_marks_obtained'] ?? null,
                'entered_by_id' => $actor instanceof TeacherUser ? $actor->id : null,
            ];

            $existing = AcademicExamMark::where('student_profile_id', $studentProfileId)
                ->where('subject_id', $subjectId)
                ->where('academic_exam_term_id', $termId)
                ->where('academic_year_id', $yearId)
                ->first();

            if ($existing !== null) {
                $existing->update($attributes);
            } else {
                AcademicExamMark::create([
                    ...$attributes,
                    'student_profile_id' => $studentProfileId,
                    'subject_id' => $subjectId,
                    'academic_exam_term_id' => $termId,
                    'academic_year_id' => $yearId,
                ]);
            }
        }

        return response()->json([
            'data' => $this->rosterPayload($request, $section, $subjectId, canRecord: true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterPayload(Request $request, Section $section, int $subjectId, bool $canRecord): array
    {
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');
        $classId = $section->shift?->classVersion?->class_id;

        $configure = AcademicSubjectMarkConfigure::where('class_id', $classId)->where('subject_id', $subjectId)->first();

        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        $existing = AcademicExamMark::where('section_id', $section->id)
            ->where('subject_id', $subjectId)
            ->where('academic_exam_term_id', $termId)
            ->where('academic_year_id', $yearId)
            ->get()
            ->keyBy('student_profile_id');

        return [
            'section_id' => $section->id,
            'subject_id' => $subjectId,
            'academic_exam_term_id' => $termId,
            'academic_year_id' => $yearId,
            'can_record' => $canRecord,
            'cq_full_marks' => $configure?->cq_full_marks,
            'mcq_full_marks' => $configure?->mcq_full_marks,
            'practical_full_marks' => $configure?->practical_full_marks,
            'students' => $students->map(function (StudentProfile $profile) use ($existing) {
                $mark = $existing->get($profile->id);

                return [
                    'student_profile_id' => $profile->id,
                    'name' => $profile->user?->name,
                    'roll_number' => $profile->roll_number,
                    'cq_marks_obtained' => $mark?->cq_marks_obtained,
                    'mcq_marks_obtained' => $mark?->mcq_marks_obtained,
                    'practical_marks_obtained' => $mark?->practical_marks_obtained,
                ];
            })->values()->all(),
        ];
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
}
```

- [ ] **Step 9: Register the routes**

In `backend/routes/api.php`, add the imports near the other controller `use` statements (alphabetical):

```php
use App\Http\Controllers\Api\V1\AcademicExamMarkController;
```

Add to the `auth:sanctum` block, right after the `/attendances/mine` line:

```php
Route::get('/academic-exam-marks/my-assignments', [AcademicExamMarkController::class, 'myAssignments']);
Route::get('/academic-exam-marks/roster', [AcademicExamMarkController::class, 'roster']);
Route::post('/academic-exam-marks/record', [AcademicExamMarkController::class, 'record']);
```

- [ ] **Step 10: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=AcademicExamMarkControllerTest
```

Expected: all 11 tests green.

- [ ] **Step 11: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: Pint `{"result":"passed"}`; full suite fully green (no regressions).

- [ ] **Step 12: Commit**

```bash
cd backend && git add app/Models/AcademicExamMark.php app/Policies/AcademicExamMarkPolicy.php app/Http/Requests/Api/V1/RecordAcademicExamMarkRequest.php app/Http/Controllers/Api/V1/AcademicExamMarkController.php database/migrations tests/Feature/AcademicExamMarkControllerTest.php routes/api.php
git commit -m "Add academic_exam_marks: roster-based mark entry for teachers"
```

---

### Task 2: `academic_exam_publications` backend (migration, model, policy, request, controller, routes, tests)

**Files:**
- Create (via `php artisan make:migration create_academic_exam_publications_table --no-interaction`, then edit): `backend/database/migrations/<timestamp>_create_academic_exam_publications_table.php`
- Create: `backend/app/Models/AcademicExamPublication.php`
- Create: `backend/app/Policies/AcademicExamPublicationPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/PublishAcademicExamRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/AcademicExamPublicationController.php`
- Create: `backend/tests/Feature/AcademicExamPublicationControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `App\Models\AcademicExamPublication` (`Auditable, SoftDeletes, TenantScoped`) with static `AcademicExamPublication::isPublished(int $sectionId, int $termId, int $yearId): bool` — **this exact signature is relied on by `MarksheetBuilder` and the student-facing marksheet gate in a later phase; do not rename it.** Routes (all `auth:sanctum`): `GET /api/v1/academic-exam-publications/status`, `POST /api/v1/academic-exam-publications`.
- Consumes: `App\Models\Section`, `App\Models\AcademicSubjectMarkConfigure`, `App\Models\AcademicExamMark` (from Task 1, same plan), `App\Models\StudentProfile` — no changes needed to any of them.

- [ ] **Step 1: Generate and write the migration**

```bash
cd backend && php artisan make:migration create_academic_exam_publications_table --no-interaction
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
     * A row's mere existence means "published" — this table is never
     * updated, only created and (via SoftDeletes) soft-deleted; it never
     * touches academic_exam_marks. The publisher is captured via the
     * polymorphic created_by_id/created_by_type (always App\Models\User
     * — a school_admin/national_admin, never a teacher), so no extra
     * "published_by" column is needed.
     */
    public function up(): void
    {
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

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['section_id', 'academic_exam_term_id', 'academic_year_id'], 'academic_exam_publications_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_exam_publications');
    }
};
```

- [ ] **Step 2: Create the model**

`backend/app/Models/AcademicExamPublication.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AcademicExamPublication extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'section_id',
        'academic_exam_term_id',
        'academic_year_id',
        'school_id',
        'district_id',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function academicExamTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicExamTerm::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Whether (section, term, year) has already been published — the
     * single check every publish-state gate in this feature uses. Relied
     * on by MarksheetBuilder and the student marksheet gate in a later
     * phase; keep this exact method name and signature.
     */
    public static function isPublished(int $sectionId, int $termId, int $yearId): bool
    {
        return static::where('section_id', $sectionId)
            ->where('academic_exam_term_id', $termId)
            ->where('academic_year_id', $yearId)
            ->exists();
    }
}
```

- [ ] **Step 3: Migrate**

```bash
cd backend && php artisan migrate --no-interaction
```

Expected: `academic_exam_publications ... DONE`.

- [ ] **Step 4: Write the failing feature test**

`backend/tests/Feature/AcademicExamPublicationControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicExamTerm;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicExamPublicationControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private Subject $subject;

    private AcademicExamTerm $term;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $this->term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
    }

    private function studentInSection(?string $rollNumber = '1'): StudentUser
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'roll_number' => $rollNumber,
        ]);

        return $student;
    }

    public function test_status_reports_entered_count_against_roster_size(): void
    {
        $student = $this->studentInSection();
        $profile = $student->profile;

        AcademicExamMark::create([
            'student_profile_id' => $profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => 60,
        ]);

        $admin = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/academic-exam-publications/status?section_id={$this->section->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.roster_size', 1)
            ->assertJsonPath('data.subjects.0.entered_count', 1)
            ->assertJsonPath('data.is_published', false);
    }

    public function test_status_requires_view_permission(): void
    {
        $viewer = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/academic-exam-publications/status?section_id={$this->section->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }

    public function test_publishing_requires_the_publish_permission(): void
    {
        $viewer = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertForbidden();
    }

    public function test_a_teacher_cannot_publish_even_as_the_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertForbidden();
    }

    public function test_publishing_creates_a_publication_row(): void
    {
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertCreated()->assertJsonPath('data.is_published', true);

        $this->assertDatabaseHas('academic_exam_publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);
    }

    public function test_publishing_again_is_a_no_op_not_an_error(): void
    {
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);

        $payload = ['section_id' => $this->section->id, 'academic_exam_term_id' => $this->term->id, 'academic_year_id' => $this->year->id];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', $payload)->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', $payload)->assertCreated();

        $this->assertDatabaseCount('academic_exam_publications', 1);
    }

    public function test_is_published_helper_reflects_publication_state(): void
    {
        $this->assertFalse(AcademicExamPublication::isPublished($this->section->id, $this->term->id, $this->year->id));

        AcademicExamPublication::create([
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->assertTrue(AcademicExamPublication::isPublished($this->section->id, $this->term->id, $this->year->id));
    }
}
```

- [ ] **Step 5: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=AcademicExamPublicationControllerTest
```

Expected: FAIL (route/model/policy/controller don't exist yet).

- [ ] **Step 6: Write the policy**

`backend/app/Policies/AcademicExamPublicationPolicy.php`:

```php
<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

class AcademicExamPublicationPolicy
{
    /**
     * Viewing publish status is the same academic_exam_marks.view
     * permission that gates marks auditing — no separate permission for
     * "can see whether a section is published yet".
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_marks.view');
    }

    /**
     * Publishing is school_admin/national_admin only — never a teacher,
     * regardless of their section/subject relationship.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('academic_exam_marks.publish');
    }
}
```

- [ ] **Step 7: Write the form request**

`backend/app/Http/Requests/Api/V1/PublishAcademicExamRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\AcademicExamPublication;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PublishAcademicExamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AcademicExamPublication::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_exam_term_id' => ['required', 'integer', 'exists:academic_exam_terms,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
        ];
    }
}
```

- [ ] **Step 8: Write the controller**

`backend/app/Http/Controllers/Api/V1/AcademicExamPublicationController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublishAcademicExamRequest;
use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use App\Models\StudentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AcademicExamPublicationController extends Controller
{
    /**
     * Per-subject entered-vs-roster-size counts for a (section, term,
     * year), plus whether it's already published — the data behind the
     * management portal's Publish Results screen, so an admin can see at
     * a glance which subjects still need marks before publishing.
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicExamPublication::class);

        $section = Section::findOrFail($request->integer('section_id'));
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');
        $classId = $section->shift?->classVersion?->class_id;

        $rosterSize = StudentProfile::where('section_id', $section->id)->count();

        $subjects = AcademicSubjectMarkConfigure::where('class_id', $classId)->with('subject')->get();

        $subjectRows = $subjects->map(function (AcademicSubjectMarkConfigure $configure) use ($section, $termId, $yearId) {
            $enteredCount = AcademicExamMark::where('section_id', $section->id)
                ->where('subject_id', $configure->subject_id)
                ->where('academic_exam_term_id', $termId)
                ->where('academic_year_id', $yearId)
                ->count();

            return [
                'subject_id' => $configure->subject_id,
                'subject_name' => $configure->subject?->bn_name ?? $configure->subject?->name,
                'entered_count' => $enteredCount,
            ];
        })->values();

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'academic_exam_term_id' => $termId,
                'academic_year_id' => $yearId,
                'roster_size' => $rosterSize,
                'subjects' => $subjectRows,
                'is_published' => AcademicExamPublication::isPublished($section->id, $termId, $yearId),
            ],
        ]);
    }

    /**
     * Publish a (section, term, year) — additive only: inserts into
     * academic_exam_publications, never touches academic_exam_marks.
     * Already-published is a no-op (not an error), matching the table's
     * own unique index semantics instead of fighting them.
     */
    public function store(PublishAcademicExamRequest $request): JsonResponse
    {
        $section = Section::findOrFail($request->integer('section_id'));
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');

        if (! AcademicExamPublication::isPublished($section->id, $termId, $yearId)) {
            AcademicExamPublication::create([
                'section_id' => $section->id,
                'academic_exam_term_id' => $termId,
                'academic_year_id' => $yearId,
                'school_id' => $section->school_id,
                'district_id' => $section->district_id,
            ]);
        }

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'academic_exam_term_id' => $termId,
                'academic_year_id' => $yearId,
                'is_published' => true,
            ],
        ], Response::HTTP_CREATED);
    }
}
```

- [ ] **Step 9: Register the routes**

In `backend/routes/api.php`, add the import:

```php
use App\Http\Controllers\Api\V1\AcademicExamPublicationController;
```

Add to the `auth:sanctum` block, right after the three `/academic-exam-marks/*` lines added in Task 1:

```php
Route::get('/academic-exam-publications/status', [AcademicExamPublicationController::class, 'status']);
Route::post('/academic-exam-publications', [AcademicExamPublicationController::class, 'store']);
```

- [ ] **Step 10: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=AcademicExamPublicationControllerTest
```

Expected: all 7 tests green.

- [ ] **Step 11: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: both green, no regressions.

- [ ] **Step 12: Commit**

```bash
cd backend && git add app/Models/AcademicExamPublication.php app/Policies/AcademicExamPublicationPolicy.php app/Http/Requests/Api/V1/PublishAcademicExamRequest.php app/Http/Controllers/Api/V1/AcademicExamPublicationController.php database/migrations tests/Feature/AcademicExamPublicationControllerTest.php routes/api.php
git commit -m "Add academic_exam_publications: additive publish gate for exam results"
```

---

### Task 3: Permissions and role grants

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`

**Interfaces:**
- Produces: 3 new permission rows (`academic_exam_marks.view`, `academic_exam_marks.update`, `academic_exam_marks.publish`), with `.view`/`.update` granted to `school_admin` and `teacher` only, and `.publish` granted to `school_admin` only (plus `national_admin` automatically via its catalog-wide grant).
- Consumes: Task 1/2's permission names exactly as used in their Policy classes (`academic_exam_marks.view`/`.update`/`.publish`) — must match verbatim.

- [ ] **Step 1: Add the 3 permissions**

In `backend/database/seeders/PermissionSeeder.php`, add after the `academic_subject_mark_configures.*` block added in Phase 1:

```php
// Unlike attendances.*, these are deliberately NOT granted broadly in
// RolePermissionSeeder — .view gates raw, possibly-unpublished marks
// (a staff audit capability per the design spec), not a universally
// viewable record like attendance. Only school_admin/teacher get
// .view/.update; only school_admin gets .publish (national_admin gets
// everything automatically).
['name' => 'academic_exam_marks.view', 'label' => 'View exam marks'],
['name' => 'academic_exam_marks.update', 'label' => 'Record or update exam marks'],
['name' => 'academic_exam_marks.publish', 'label' => 'Publish exam results to students'],
```

- [ ] **Step 2: Grant to `school_admin` and `teacher` only**

In `backend/database/seeders/RolePermissionSeeder.php`, find the `school_admin` array's `'attendances.view', 'attendances.update',` line and add right after it:

```php
'attendances.view', 'attendances.update',
'academic_exam_marks.view', 'academic_exam_marks.update', 'academic_exam_marks.publish',
```

Find the `teacher` array's `'attendances.view', 'attendances.update',` line (the second occurrence, in the `teacher` block's own comment-preceded pair) and add right after it:

```php
'attendances.view', 'attendances.update',
'academic_exam_marks.view', 'academic_exam_marks.update',
```

Do **not** add these to `district_officer`, `student`, `trainer`, `office_staff`, `parent_guardian`, `it_admin`, or `auditor` — deliberately, per this plan's Global Constraints. `national_admin` needs no change (already gets `$allPermissions`).

- [ ] **Step 3: Re-seed and verify**

```bash
cd backend && php artisan db:seed --class=PermissionSeeder --no-interaction && php artisan db:seed --class=RolePermissionSeeder --no-interaction
```

Expected: both seeders run with no errors.

```bash
php artisan tinker --execute '
$teacher = App\Models\Role::where("name", "teacher")->first();
echo $teacher->permissions()->where("name", "like", "academic_exam_marks.%")->pluck("name")->implode(", ") . PHP_EOL;
$schoolAdmin = App\Models\Role::where("name", "school_admin")->first();
echo $schoolAdmin->permissions()->where("name", "like", "academic_exam_marks.%")->pluck("name")->implode(", ") . PHP_EOL;
$districtOfficer = App\Models\Role::where("name", "district_officer")->first();
echo $districtOfficer->permissions()->where("name", "like", "academic_exam_marks.%")->count() . PHP_EOL;
'
```

Expected: teacher line lists exactly `.view, .update`; school_admin line lists `.view, .update, .publish`; district_officer count is `0`.

- [ ] **Step 4: Run the full backend suite**

```bash
cd backend && php artisan test --compact && vendor/bin/pint --dirty --format agent
```

Expected: both green — this task touches only seeders, which no feature test depends on (tests create their own permissions via `CreatesAuthorizedActors`), so this should be a pure no-op for the test suite.

- [ ] **Step 5: Commit**

```bash
cd backend && git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php
git commit -m "Grant academic_exam_marks permissions to school_admin and teacher only"
```

---

### Task 4: `api-client` additions

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`

**Interfaces:**
- Produces: TypeScript types `AcademicExamMarkAssignment`, `AcademicExamMarkRosterRow`, `AcademicExamMarkRoster`, `AcademicExamPublicationSubjectRow`, `AcademicExamPublicationStatus`, and functions `listMyAcademicExamMarkAssignments`, `getAcademicExamMarkRoster`, `recordAcademicExamMarks`, `getAcademicExamPublicationStatus`, `publishAcademicExamResults` — **these exact names are what Tasks 5-6's pages import.**
- Consumes: the existing `apiFetch`/`buildQuery`/`authHeaders` helpers already used by `listMyAttendanceSections`/`getAttendanceRoster`/`recordAttendance` (same file, already committed).

- [ ] **Step 1: Add the two sections**

Find the end of the file (after the last export added by Phase 1) in `frontend/packages/api-client/src/index.ts` and append:

```typescript
// ---------- Academic exam marks (teacher mark entry) ----------

export interface AcademicExamMarkAssignment {
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

export function listMyAcademicExamMarkAssignments(token: string): Promise<{ data: AcademicExamMarkAssignment[] }> {
  return apiFetch<{ data: AcademicExamMarkAssignment[] }>('/academic-exam-marks/my-assignments', {
    headers: authHeaders(token),
  })
}

export interface AcademicExamMarkRosterRow {
  student_profile_id: number
  name: string | null
  roll_number: string | null
  cq_marks_obtained: number | null
  mcq_marks_obtained: number | null
  practical_marks_obtained: number | null
}

export interface AcademicExamMarkRoster {
  section_id: number
  subject_id: number
  academic_exam_term_id: number
  academic_year_id: number
  can_record: boolean
  cq_full_marks: number | null
  mcq_full_marks: number | null
  practical_full_marks: number | null
  students: AcademicExamMarkRosterRow[]
}

export function getAcademicExamMarkRoster(
  params: { sectionId: number; subjectId: number; academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamMarkRoster }> {
  const query = buildQuery({
    section_id: params.sectionId,
    subject_id: params.subjectId,
    academic_exam_term_id: params.academicExamTermId,
    academic_year_id: params.academicYearId,
  })

  return apiFetch<{ data: AcademicExamMarkRoster }>(`/academic-exam-marks/roster${query}`, {
    headers: authHeaders(token),
  })
}

// Bulk-records/updates marks for a (section, subject, exam term,
// academic year) — only a teacher who may touch that section/subject
// (or a staff override) may call this; a (class, subject) pair with no
// configured full marks is rejected outright (422).
export function recordAcademicExamMarks(
  payload: {
    section_id: number
    subject_id: number
    academic_exam_term_id: number
    academic_year_id: number
    records: {
      student_profile_id: number
      cq_marks_obtained: number | null
      mcq_marks_obtained: number | null
      practical_marks_obtained: number | null
    }[]
  },
  token: string,
): Promise<{ data: AcademicExamMarkRoster }> {
  return apiFetch<{ data: AcademicExamMarkRoster }>('/academic-exam-marks/record', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
}

// ---------- Academic exam publications (publish gate) ----------

export interface AcademicExamPublicationSubjectRow {
  subject_id: number
  subject_name: string | null
  entered_count: number
}

export interface AcademicExamPublicationStatus {
  section_id: number
  academic_exam_term_id: number
  academic_year_id: number
  roster_size: number
  subjects: AcademicExamPublicationSubjectRow[]
  is_published: boolean
}

export function getAcademicExamPublicationStatus(
  params: { sectionId: number; academicExamTermId: number; academicYearId: number },
  token: string,
): Promise<{ data: AcademicExamPublicationStatus }> {
  const query = buildQuery({
    section_id: params.sectionId,
    academic_exam_term_id: params.academicExamTermId,
    academic_year_id: params.academicYearId,
  })

  return apiFetch<{ data: AcademicExamPublicationStatus }>(`/academic-exam-publications/status${query}`, {
    headers: authHeaders(token),
  })
}

export interface AcademicExamPublicationResult {
  section_id: number
  academic_exam_term_id: number
  academic_year_id: number
  is_published: boolean
}

// Publishing an already-published (section, term, year) is a no-op on
// the backend (still 201), not an error.
export function publishAcademicExamResults(
  payload: { section_id: number; academic_exam_term_id: number; academic_year_id: number },
  token: string,
): Promise<{ data: AcademicExamPublicationResult }> {
  return apiFetch<{ data: AcademicExamPublicationResult }>('/academic-exam-publications', {
    method: 'POST',
    headers: authHeaders(token),
    body: JSON.stringify(payload),
  })
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
git commit -m "Add api-client types/functions for academic exam mark entry and publishing"
```

---

### Task 5: Teacher — Marks Entry page

**Files:**
- Create: `frontend/apps/teacher/src/pages/MarksEntryPage.tsx`
- Modify: `frontend/apps/teacher/src/App.tsx`
- Modify: `frontend/apps/teacher/src/components/TeacherAppShell.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `listMyAcademicExamMarkAssignments`/`getAcademicExamMarkRoster`/`recordAcademicExamMarks` and their types from Task 4; the existing `listAcademicExamTerms`/`listAcademicYears` (public, national, Phase 1 and pre-existing respectively — call with no params, since both are genuinely national with no school scoping); the teacher app's existing `useToast`/`ToastViewport` from `../components/Toast` (already used by `AttendancePage.tsx`).
- Produces: route `/marks-entry`, a flat top-level nav item (teacher app has no sub-tabs for this feature, unlike Attendance's take/list/report split).

- [ ] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add:

```json
"teacher.nav.marksEntry": "Marks Entry",
"teacher.marksEntry.selectSection": "Select section",
"teacher.marksEntry.selectSubject": "Select subject",
"teacher.marksEntry.selectTerm": "Select exam term",
"teacher.marksEntry.selectYear": "Select academic year",
"teacher.marksEntry.loading": "Loading…",
"teacher.marksEntry.pickAllFilters": "Pick a section, subject, exam term, and academic year to load the roster.",
"teacher.marksEntry.empty": "No students found in this section.",
"teacher.marksEntry.viewOnlyNotice": "You can view this roster, but you don't have permission to record marks here.",
"teacher.marksEntry.colRoll": "Roll",
"teacher.marksEntry.colStudent": "Student",
"teacher.marksEntry.colCq": "CQ",
"teacher.marksEntry.colMcq": "MCQ",
"teacher.marksEntry.colPractical": "Practical",
"teacher.marksEntry.save": "Save Marks",
"teacher.marksEntry.saving": "Saving…",
"teacher.marksEntry.saveSuccess": "Marks saved.",
"teacher.marksEntry.saveError": "Could not save marks. Please try again."
```

In `frontend/packages/i18n/src/common/bn.json`, mirrored with real Bengali translations:

```json
"teacher.nav.marksEntry": "নম্বর এন্ট্রি",
"teacher.marksEntry.selectSection": "শাখা নির্বাচন করুন",
"teacher.marksEntry.selectSubject": "বিষয় নির্বাচন করুন",
"teacher.marksEntry.selectTerm": "পরীক্ষার মেয়াদ নির্বাচন করুন",
"teacher.marksEntry.selectYear": "শিক্ষাবর্ষ নির্বাচন করুন",
"teacher.marksEntry.loading": "লোড হচ্ছে…",
"teacher.marksEntry.pickAllFilters": "রোস্টার লোড করতে একটি শাখা, বিষয়, পরীক্ষার মেয়াদ এবং শিক্ষাবর্ষ নির্বাচন করুন।",
"teacher.marksEntry.empty": "এই শাখায় কোনো শিক্ষার্থী পাওয়া যায়নি।",
"teacher.marksEntry.viewOnlyNotice": "আপনি এই রোস্টার দেখতে পারবেন, কিন্তু এখানে নম্বর এন্ট্রি করার অনুমতি আপনার নেই।",
"teacher.marksEntry.colRoll": "রোল",
"teacher.marksEntry.colStudent": "শিক্ষার্থী",
"teacher.marksEntry.colCq": "সিকিউ",
"teacher.marksEntry.colMcq": "এমসিকিউ",
"teacher.marksEntry.colPractical": "ব্যবহারিক",
"teacher.marksEntry.save": "নম্বর সংরক্ষণ করুন",
"teacher.marksEntry.saving": "সংরক্ষণ হচ্ছে…",
"teacher.marksEntry.saveSuccess": "নম্বর সংরক্ষণ করা হয়েছে।",
"teacher.marksEntry.saveError": "নম্বর সংরক্ষণ করা যায়নি। আবার চেষ্টা করুন।"
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

`frontend/apps/teacher/src/pages/MarksEntryPage.tsx`:

```tsx
import { useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  getAcademicExamMarkRoster,
  listAcademicExamTerms,
  listAcademicYears,
  listMyAcademicExamMarkAssignments,
  recordAcademicExamMarks,
  type AcademicExamMarkAssignment,
  type AcademicExamMarkRoster,
  type AcademicExamTerm,
  type AcademicYear,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

/**
 * First row carrying each distinct, non-null key — same technique
 * AttendancePage uses to turn a flat assignment list into cascading
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

type PendingMarks = Record<number, { cq: string; mcq: string; practical: string }>

function toNullableInt(value: string): number | null {
  return value.trim() === '' ? null : Number(value)
}

export default function MarksEntryPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [assignments, setAssignments] = useState<AcademicExamMarkAssignment[]>([])
  const [years, setYears] = useState<AcademicYear[]>([])
  const [terms, setTerms] = useState<AcademicExamTerm[]>([])
  const [loadingFilters, setLoadingFilters] = useState(true)

  const [sectionId, setSectionId] = useState<number | null>(null)
  const [subjectId, setSubjectId] = useState<number | null>(null)
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)
  const [academicExamTermId, setAcademicExamTermId] = useState<number | null>(null)

  const [loadingRoster, setLoadingRoster] = useState(false)
  const [roster, setRoster] = useState<AcademicExamMarkRoster | null>(null)
  const [pending, setPending] = useState<PendingMarks>({})
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!token) return

    Promise.all([listMyAcademicExamMarkAssignments(token), listAcademicYears(), listAcademicExamTerms()])
      .then(([assignmentResult, yearResult, termResult]) => {
        setAssignments(assignmentResult.data)
        setYears(yearResult.data)
        setTerms(termResult.data)
      })
      .catch(() => {
        setAssignments([])
        setYears([])
        setTerms([])
      })
      .finally(() => setLoadingFilters(false))
  }, [token])

  const sections = useMemo(() => uniqueByKey(assignments, (row) => row.section_id), [assignments])
  const subjectsForSection = useMemo(() => assignments.filter((row) => row.section_id === sectionId), [assignments, sectionId])

  useEffect(() => {
    if (!token || sectionId === null || subjectId === null || academicYearId === null || academicExamTermId === null) {
      setRoster(null)
      return
    }

    let active = true
    setLoadingRoster(true)

    getAcademicExamMarkRoster({ sectionId, subjectId, academicExamTermId, academicYearId }, token)
      .then((result) => {
        if (!active) return
        setRoster(result.data)
        setPending(
          Object.fromEntries(
            result.data.students.map((row) => [
              row.student_profile_id,
              {
                cq: row.cq_marks_obtained === null ? '' : String(row.cq_marks_obtained),
                mcq: row.mcq_marks_obtained === null ? '' : String(row.mcq_marks_obtained),
                practical: row.practical_marks_obtained === null ? '' : String(row.practical_marks_obtained),
              },
            ]),
          ),
        )
      })
      .catch(() => {
        if (active) setRoster(null)
      })
      .finally(() => {
        if (active) setLoadingRoster(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, subjectId, academicExamTermId, academicYearId])

  async function handleSave() {
    if (!token || !roster || sectionId === null || subjectId === null || academicYearId === null || academicExamTermId === null) return

    const records = Object.entries(pending).map(([studentProfileId, marks]) => ({
      student_profile_id: Number(studentProfileId),
      cq_marks_obtained: toNullableInt(marks.cq),
      mcq_marks_obtained: toNullableInt(marks.mcq),
      practical_marks_obtained: toNullableInt(marks.practical),
    }))

    setSaving(true)

    try {
      const result = await recordAcademicExamMarks(
        { section_id: sectionId, subject_id: subjectId, academic_exam_term_id: academicExamTermId, academic_year_id: academicYearId, records },
        token,
      )
      setRoster(result.data)
      showToast({ type: 'success', message: t['teacher.marksEntry.saveSuccess'] })
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['teacher.marksEntry.saveError'] })
    } finally {
      setSaving(false)
    }
  }

  function updateMark(studentProfileId: number, component: 'cq' | 'mcq' | 'practical', value: string) {
    setPending((previous) => ({
      ...previous,
      [studentProfileId]: { ...(previous[studentProfileId] ?? { cq: '', mcq: '', practical: '' }), [component]: value },
    }))
  }

  return (
    <div>
      <h1 className="text-xl font-semibold text-gray-900">{t['teacher.nav.marksEntry']}</h1>

      <div className="mt-4 flex flex-wrap items-center gap-3 rounded-md bg-white p-4 shadow-sm">
        <select
          className="h-11 min-w-[12rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={sectionId ?? ''}
          onChange={(event) => {
            setSectionId(event.target.value ? Number(event.target.value) : null)
            setSubjectId(null)
          }}
        >
          <option value="">{t['teacher.marksEntry.selectSection']}</option>
          {sections.map((row) => (
            <option key={row.section_id} value={row.section_id}>
              {[row.class_name, row.class_version, row.shift, row.section_name].filter(Boolean).join(' - ')}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
          value={subjectId ?? ''}
          disabled={sectionId === null}
          onChange={(event) => setSubjectId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksEntry.selectSubject']}</option>
          {subjectsForSection.map((row) => (
            <option key={row.subject_id} value={row.subject_id}>
              {row.subject_name}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={academicExamTermId ?? ''}
          onChange={(event) => setAcademicExamTermId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksEntry.selectTerm']}</option>
          {terms.map((term) => (
            <option key={term.id} value={term.id}>
              {term.bn_name ?? term.name}
            </option>
          ))}
        </select>

        <select
          className="h-11 min-w-[10rem] rounded-md border border-gray-200 bg-white px-3 text-sm text-gray-800 outline-none transition-colors focus:border-primary-500"
          value={academicYearId ?? ''}
          onChange={(event) => setAcademicYearId(event.target.value ? Number(event.target.value) : null)}
        >
          <option value="">{t['teacher.marksEntry.selectYear']}</option>
          {years.map((year) => (
            <option key={year.id} value={year.id}>
              {year.name}
            </option>
          ))}
        </select>
      </div>

      {loadingFilters && <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.loading']}</p>}

      {!loadingFilters && (sectionId === null || subjectId === null || academicExamTermId === null || academicYearId === null) && (
        <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.pickAllFilters']}</p>
      )}

      {loadingRoster && <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.loading']}</p>}

      {!loadingRoster && roster && roster.students.length === 0 && (
        <p className="mt-6 text-sm text-gray-500">{t['teacher.marksEntry.empty']}</p>
      )}

      {!loadingRoster && roster && roster.students.length > 0 && (
        <>
          {!roster.can_record && <p className="mt-4 text-sm text-gray-500">{t['teacher.marksEntry.viewOnlyNotice']}</p>}

          <div className="mt-4 max-h-[26rem] overflow-y-auto overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[40rem] text-sm">
              <thead className="sticky top-0 z-10 bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.marksEntry.colRoll']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['teacher.marksEntry.colStudent']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">
                    {t['teacher.marksEntry.colCq']} {roster.cq_full_marks !== null ? `(/${roster.cq_full_marks})` : ''}
                  </th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">
                    {t['teacher.marksEntry.colMcq']} {roster.mcq_full_marks !== null ? `(/${roster.mcq_full_marks})` : ''}
                  </th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">
                    {t['teacher.marksEntry.colPractical']} {roster.practical_full_marks !== null ? `(/${roster.practical_full_marks})` : ''}
                  </th>
                </tr>
              </thead>
              <tbody>
                {roster.students.map((row) => {
                  const marks = pending[row.student_profile_id] ?? { cq: '', mcq: '', practical: '' }

                  return (
                    <tr key={row.student_profile_id} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{row.roll_number ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <input
                          type="number"
                          min={0}
                          max={roster.cq_full_marks ?? undefined}
                          disabled={!roster.can_record || roster.cq_full_marks === null}
                          value={marks.cq}
                          onChange={(event) => updateMark(row.student_profile_id, 'cq', event.target.value)}
                          className="h-9 w-20 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50"
                        />
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <input
                          type="number"
                          min={0}
                          max={roster.mcq_full_marks ?? undefined}
                          disabled={!roster.can_record || roster.mcq_full_marks === null}
                          value={marks.mcq}
                          onChange={(event) => updateMark(row.student_profile_id, 'mcq', event.target.value)}
                          className="h-9 w-20 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50"
                        />
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <input
                          type="number"
                          min={0}
                          max={roster.practical_full_marks ?? undefined}
                          disabled={!roster.can_record || roster.practical_full_marks === null}
                          value={marks.practical}
                          onChange={(event) => updateMark(row.student_profile_id, 'practical', event.target.value)}
                          className="h-9 w-20 rounded-md border border-gray-200 px-2 text-sm outline-none focus:border-primary-500 disabled:cursor-not-allowed disabled:bg-gray-50"
                        />
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          {roster.can_record && (
            <div className="mt-4">
              <button
                type="button"
                onClick={handleSave}
                disabled={saving}
                className="cursor-pointer rounded-md bg-primary-500 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-primary-400 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {saving ? t['teacher.marksEntry.saving'] : t['teacher.marksEntry.save']}
              </button>
            </div>
          )}
        </>
      )}

      <ToastViewport toast={toast} />
    </div>
  )
}
```

- [ ] **Step 3: Wire the route and nav item**

In `frontend/apps/teacher/src/App.tsx`, add the import next to the other page imports:

```tsx
import MarksEntryPage from './pages/MarksEntryPage'
```

Add the route after the `holidays` route:

```tsx
<Route path="marks-entry" element={<MarksEntryPage />} />
```

In `frontend/apps/teacher/src/components/TeacherAppShell.tsx`, add the nav item to `navItems`, right after the Attendance submenu block and before `leave-applications`:

```tsx
{ to: '/marks-entry', label: t['teacher.nav.marksEntry'], icon: 'edit_note' },
```

- [ ] **Step 4: Typecheck and build**

```bash
cd frontend/apps/teacher && npx tsc -b --noEmit && npm run build
```

Expected: no typecheck output; build succeeds.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add apps/teacher/src/pages/MarksEntryPage.tsx apps/teacher/src/App.tsx apps/teacher/src/components/TeacherAppShell.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add teacher Marks Entry page"
```

---

### Task 6: Management — Publish Results page

**Files:**
- Create: `frontend/apps/management/src/pages/PublishResultsPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `getAcademicExamPublicationStatus`/`publishAcademicExamResults` and their types from Task 4; the existing `listSchools`/`listSections`/`listAcademicYears` (used by `AttendanceReportPage.tsx`) and `listAcademicExamTerms` (Phase 1, public); the existing `SearchableSelect`/`inputClass`/`buttonPrimary` components and the management app's own `useToast`/`ToastViewport`.
- Produces: route `/dashboard/publish-results`, a flat top-level nav item visible to `school_admin`/`national_admin` (same tier as the existing Attendance report nav item — a management-only reporting/action surface, not under System Setup).

- [ ] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add:

```json
"management.nav.publishResults": "Publish Results",
"management.publishResults.title": "Publish Exam Results",
"management.publishResults.selectSection": "Select section",
"management.publishResults.selectTerm": "Select exam term",
"management.publishResults.loading": "Loading…",
"management.publishResults.colSubject": "Subject",
"management.publishResults.colEntered": "Entered / Roster",
"management.publishResults.alreadyPublished": "Published",
"management.publishResults.publish": "Publish Results",
"management.publishResults.publishing": "Publishing…",
"management.publishResults.publishSuccess": "Results published.",
"management.publishResults.publishError": "Could not publish results. Please try again."
```

In `frontend/packages/i18n/src/common/bn.json`, mirrored with real Bengali translations:

```json
"management.nav.publishResults": "ফলাফল প্রকাশ",
"management.publishResults.title": "পরীক্ষার ফলাফল প্রকাশ করুন",
"management.publishResults.selectSection": "শাখা নির্বাচন করুন",
"management.publishResults.selectTerm": "পরীক্ষার মেয়াদ নির্বাচন করুন",
"management.publishResults.loading": "লোড হচ্ছে…",
"management.publishResults.colSubject": "বিষয়",
"management.publishResults.colEntered": "প্রবেশকৃত / রোস্টার",
"management.publishResults.alreadyPublished": "প্রকাশিত",
"management.publishResults.publish": "ফলাফল প্রকাশ করুন",
"management.publishResults.publishing": "প্রকাশ হচ্ছে…",
"management.publishResults.publishSuccess": "ফলাফল প্রকাশ করা হয়েছে।",
"management.publishResults.publishError": "ফলাফল প্রকাশ করা যায়নি। আবার চেষ্টা করুন।"
```

Verify parity with the same `node -e` command as Task 5 Step 1.

- [ ] **Step 2: Write the page**

`frontend/apps/management/src/pages/PublishResultsPage.tsx`:

```tsx
import { useEffect, useState } from 'react'
import {
  ApiError,
  getAcademicExamPublicationStatus,
  listAcademicExamTerms,
  listAcademicYears,
  listSchools,
  listSections,
  publishAcademicExamResults,
  type AcademicExamPublicationStatus,
  type AcademicExamTerm,
  type AcademicYear,
  type School,
  type Section,
} from '@ttla/api-client'
import { useAuth } from '@ttla/auth'
import { getCommonStrings } from '@ttla/i18n'
import SearchableSelect from '../components/SearchableSelect'
import { buttonPrimary, inputClass } from '../components/styles'
import { useToast, ToastViewport } from '../components/Toast'

const t = getCommonStrings('en')

function sectionLabel(section: Section): string {
  return [section.shift?.class_version?.class?.name, section.shift?.class_version?.name, section.shift?.name, section.name]
    .filter(Boolean)
    .join(' - ')
}

// Report/action-only — recording marks itself stays in the teacher
// portal (see AcademicExamMarkPolicy); this page only reads entered-vs-
// roster counts and fires the publish action, same split as
// AttendanceReportPage's own relationship to Take Attendance.
export default function PublishResultsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

  const [terms, setTerms] = useState<AcademicExamTerm[]>([])
  const [academicExamTermId, setAcademicExamTermId] = useState<number | null>(null)

  const [years, setYears] = useState<AcademicYear[]>([])
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)

  const [loading, setLoading] = useState(false)
  const [status, setStatus] = useState<AcademicExamPublicationStatus | null>(null)
  const [publishing, setPublishing] = useState(false)

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))

    listAcademicExamTerms()
      .then((result) => setTerms(result.data))
      .catch(() => setTerms([]))

    listAcademicYears({ perPage: 200 })
      .then((result) => {
        setYears(result.data)
        setAcademicYearId((previous) => previous ?? result.data.find((year) => year.is_current)?.id ?? null)
      })
      .catch(() => setYears([]))
  }, [])

  useEffect(() => {
    if (!schoolId) {
      setSections([])
      return
    }

    let active = true

    listSections({ schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setSections(result.data)
        setSectionId(null)
      })
      .catch(() => {
        if (active) setSections([])
      })

    return () => {
      active = false
    }
  }, [schoolId])

  useEffect(() => {
    if (!token || sectionId === null || academicExamTermId === null || academicYearId === null) {
      setStatus(null)
      return
    }

    let active = true
    setLoading(true)

    getAcademicExamPublicationStatus({ sectionId, academicExamTermId, academicYearId }, token)
      .then((result) => {
        if (active) setStatus(result.data)
      })
      .catch(() => {
        if (active) setStatus(null)
      })
      .finally(() => {
        if (active) setLoading(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, academicExamTermId, academicYearId])

  async function handlePublish() {
    if (!token || sectionId === null || academicExamTermId === null || academicYearId === null) return

    setPublishing(true)

    try {
      await publishAcademicExamResults({ section_id: sectionId, academic_exam_term_id: academicExamTermId, academic_year_id: academicYearId }, token)
      showToast({ type: 'success', message: t['management.publishResults.publishSuccess'] })
      setStatus((previous) => (previous ? { ...previous, is_published: true } : previous))
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['management.publishResults.publishError'] })
    } finally {
      setPublishing(false)
    }
  }

  return (
    <section>
      <h1 className="text-2xl font-bold text-gray-900">{t['management.publishResults.title']}</h1>

      <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectSchool']}</span>
          <SearchableSelect
            className={`${inputClass} min-w-[14rem]`}
            value={schoolId !== null ? String(schoolId) : ''}
            onChange={(value) => setSchoolId(Number(value))}
            placeholder={t['management.attendance.selectSchool']}
            searchPlaceholder={t['management.table.search']}
            noMatchesLabel={t['management.table.noMatches']}
            options={schools.map((school) => ({ value: String(school.id), label: school.bn_name ?? school.name }))}
          />
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.publishResults.selectSection']}</span>
          <select
            className={`${inputClass} min-w-[12rem]`}
            value={sectionId ?? ''}
            onChange={(event) => setSectionId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {sections.map((section) => (
              <option key={section.id} value={section.id}>
                {sectionLabel(section)}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.publishResults.selectTerm']}</span>
          <select
            className={`${inputClass} min-w-[10rem]`}
            value={academicExamTermId ?? ''}
            onChange={(event) => setAcademicExamTermId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {terms.map((term) => (
              <option key={term.id} value={term.id}>
                {term.bn_name ?? term.name}
              </option>
            ))}
          </select>
        </label>

        <label className="flex flex-col gap-1.5 text-sm">
          <span className="font-medium text-gray-700">{t['management.attendance.selectAcademicYear']}</span>
          <select
            className={`${inputClass} min-w-[9rem]`}
            value={academicYearId ?? ''}
            onChange={(event) => setAcademicYearId(event.target.value ? Number(event.target.value) : null)}
          >
            <option value="">—</option>
            {years.map((year) => (
              <option key={year.id} value={year.id}>
                {year.name}
              </option>
            ))}
          </select>
        </label>
      </div>

      {loading && <p className="mt-6 text-sm text-gray-500">{t['management.publishResults.loading']}</p>}

      {!loading && status && (
        <>
          <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[28rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.publishResults.colSubject']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.publishResults.colEntered']}</th>
                </tr>
              </thead>
              <tbody>
                {status.subjects.map((row) => (
                  <tr key={row.subject_id} className="border-t border-gray-100">
                    <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.subject_name ?? '—'}</td>
                    <td className="px-5 py-3.5 whitespace-nowrap text-gray-600">
                      {row.entered_count} / {status.roster_size}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4">
            {status.is_published ? (
              <span className="rounded-full bg-success-50 px-3 py-1.5 text-sm font-medium text-success-600">
                {t['management.publishResults.alreadyPublished']}
              </span>
            ) : (
              <button type="button" onClick={handlePublish} disabled={publishing} className={buttonPrimary}>
                {publishing ? t['management.publishResults.publishing'] : t['management.publishResults.publish']}
              </button>
            )}
          </div>
        </>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
```

- [ ] **Step 3: Wire the route and nav item**

In `frontend/apps/management/src/App.tsx`, add the import next to the other page imports:

```tsx
import PublishResultsPage from './pages/PublishResultsPage'
```

Add a new flat nav item, right after `attendanceNavItem`'s own definition:

```tsx
// Same placement/visibility reasoning as attendanceNavItem above —
// school_admin/national_admin only; recording stays in the teacher
// portal, this page only reports status and fires the publish action.
const publishResultsNavItem: SidebarItem = { to: '/dashboard/publish-results', label: t['management.nav.publishResults'], icon: 'campaign' }
```

Add it to the `navItems` array inside `DashboardLayout`, in the same conditional as `attendanceNavItem`:

```tsx
...(role === 'national_admin' || role === 'school_admin' ? [teachersNavItem, studentsNavItem, attendanceNavItem, publishResultsNavItem] : []),
```

Add the route inside the existing `RequireSchoolManager` block, after the `attendance` route:

```tsx
<Route path="publish-results" element={<PublishResultsPage />} />
```

- [ ] **Step 4: Typecheck and build**

```bash
cd frontend/apps/management && npx tsc -b --noEmit && npm run build
```

- [ ] **Step 5: Commit**

```bash
cd frontend && git add apps/management/src/pages/PublishResultsPage.tsx apps/management/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add management Publish Results page"
```

---

### Task 7: End-to-end verification

**Files:** none (verification only — no code changes).

**Interfaces:** none.

- [ ] **Step 1: Run the full backend suite one more time**

```bash
cd backend && php artisan test --compact && vendor/bin/pint --dirty --format agent
```

Expected: both fully green.

- [ ] **Step 2: Typecheck and build both frontend apps one more time**

```bash
cd frontend/apps/teacher && npx tsc -b --noEmit && npm run build
cd ../management && npx tsc -b --noEmit && npm run build
```

Expected: clean.

- [ ] **Step 3: Manual smoke test in the browser**

Using the data seeded by Phase 1's own manual smoke test (exam terms, grade scale, and a subject mark configure for at least one class/subject):

1. As a teacher who is a section's class teacher: navigate to Marks Entry, pick that section, pick any subject configured for its class, pick an exam term and academic year, confirm the roster loads with every student, enter CQ/MCQ marks within the configured full marks, Save, confirm the saved values redisplay.
2. Confirm entering a value above the configured full marks (e.g. 999) is rejected with a clear error.
3. As a teacher assigned only via Subject Teacher Assignment to one specific subject in that section: confirm Marks Entry's subject dropdown offers only that one subject for that section, and saving marks for it succeeds.
4. As a school_admin: navigate to Publish Results, pick the same school/section/term/year, confirm the per-subject entered-count table matches what was just saved, click Publish, confirm the status flips to "Published" and clicking again doesn't error.
5. As a teacher with no relation to that section (not class teacher, no assignment): confirm Marks Entry's section list does not include that section at all.

Report back what you observed; this step has no pass/fail assertion beyond "did the two screens behave as described."

- [ ] **Step 4: No commit for this task** (verification-only).

---

## What this plan does NOT cover

Explicitly deferred to later phases, per the spec: `App\Support\MarksheetBuilder` (grade/GPA/position computation), the `/academic-exam-marks/marksheet` and `/academic-exam-marks/mine` endpoints, the Teacher Marksheets page, the Student Results page, and `student_class_promotions` (bulk promotion). None of those are started by this plan.
