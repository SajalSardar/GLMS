# Academic Exam Results — Phase 4: Promotion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a school_admin/national_admin bulk-promote a section's passing students (per the Annual exam term's computed result) into an already-existing target class/section for the next academic year, with a per-student override and a permanent audit log — the final phase of the Academic Exam Results feature.

**Architecture:** One new resource, `student_class_promotions` — a plain, append-only audit-log table (no `Auditable`/`SoftDeletes`/`TenantScoped`, matching the already-shipped `StudentSectionTransfer`'s own minimal shape) plus a controller with two actions: `preview` (read-only — computes each student's result/GPA via Phase 3's `MarksheetBuilder` and a suggested target class) and `store` (the actual bulk mutation). Confirming promotion mutates each promoted student's existing `StudentProfile` row in place — `class_id`/`section_id`/`shift_id`/`class_version_id` — exactly the way `StudentProfileController::transferSection()` already does for a single-student section move, extended here to also update `class_id` (since promotion crosses classes, unlike a same-class section transfer) and to run as a bulk, transactional batch. A retained (failing, or overridden-to-retain) student's profile is left completely untouched; a log row is still written for them, with every `to_*` column `null`.

**Tech Stack:** Laravel 13 / PHP 8.5 (backend), React + TypeScript + Vite (management frontend), MySQL in production / SQLite in tests.

**Spec:** `docs/superpowers/specs/2026-11-20-academic-exam-results-design.md` — this plan implements the `student_class_promotions` row of "Data model", the `/student-promotions/*` rows of "Workflows → endpoints", the `student_promotions.create` row of "Permissions & policies", and the Management "Promote Students" row of "Frontend". This is the last phase of the feature — nothing is deferred beyond it.

## Global Constraints

- `student_class_promotions` is a **plain model** — no `Auditable`, no `SoftDeletes`, no `TenantScoped` — matching `StudentSectionTransfer`'s own shape exactly (an append-only log, never edited or soft-deleted after the fact).
- Promotion is decided by exactly one term: whichever `academic_exam_terms` row has `is_annual = true`. The backend resolves this itself (`AcademicExamTerm::where('is_annual', true)->first()`); the client never supplies a term id. If no annual term is configured yet, both `preview` and `store` reject with a 422 — this is a real, reachable setup gap, not a theoretical one.
- **A student with zero `academic_exam_marks` rows for the annual term/year is NOT the same as a passing student.** `MarksheetBuilder::build()` correctly (and per Phase 3's own Global Constraints) returns `result: 'pass'` for an empty subject list — that behavior must NOT be changed here, since other call sites depend on it. Instead, this phase's own `preview`/`store` logic computes its OWN, local `result` override on top of the builder's output: when `count($marksheet['subjects']) === 0`, treat the student as `'no_marks'` (not `'pass'`) for THIS phase's purposes only, defaulting their suggested action to `retain`. This exact gap — a confident "Pass" rendered for an ungraded student — is what Phase 3's own final review caught and fixed in the two marksheet-viewing pages; promotion is a more consequential, data-mutating action, so it gets the same treatment from the start rather than inheriting the bug.
- Promotion is **bulk-per-section with a per-student override** — one target class/section is chosen for the whole batch (not per student); the only per-student choice is `promote` (boolean, defaulting to `true` for `'pass'`, `false` for `'fail'`/`'no_marks'`). A row is written to `student_class_promotions` for **every** student in the batch, promoted or not — `to_class_id`/`to_section_id`/`to_shift_id`/`to_class_version_id` are `null` for a retained student, so the log shows the full decision, not just the moves.
- The target section's `shift_id`/`class_version_id` are always **derived from the target `Section`'s own relations**, never trusted from a separate client-supplied field — same "derive from the authoritative row, don't trust redundant client input" convention already used by `transferSection()` and every controller in Phases 1-3 of this feature.
- **Target section capacity is enforced**, mirroring `StudentProfileController::transferSection()`'s own existing capacity check exactly: if the target section has a non-null `capacity` and `enrolledCount() + (number of students actually being promoted in this batch)` would exceed it, `store` rejects with a 422 UNLESS the request explicitly requests `override_capacity` AND the acting user holds the already-existing `sections.override_capacity` permission (no new permission for this — same one `transferSection()` already checks).
- `student_promotions.create` is the only new permission — granted to `school_admin` only (plus `national_admin` automatically via its catalog-wide grant). It gates BOTH `preview` and `store` (there is no separate `.view` tier for this feature — the spec names only this one permission). Teachers get nothing here; promotion is an admin-only action with no class-teacher exception of any kind, unlike every per-subject/per-section ability in Phases 1-3.
- No new `Shift`/`Section`/`ClassVersion` creation as part of promotion — the target structure for the new academic year must already exist via the existing System Setup pages. This phase only reads existing sections.
- After any backend PHP change: `vendor/bin/pint --dirty --format agent` must report `{"result":"passed"}`, and `php artisan test --compact` must stay fully green.
- After any frontend change: `npx tsc -b --noEmit` (run from the specific app directory touched) must produce no output, and `npm run build` (same directory) must succeed.
- Every new/changed i18n key must exist in **both** `packages/i18n/src/common/en.json` and `packages/i18n/src/common/bn.json` with a real Bengali translation — verify with:
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

### Task 1: `student_class_promotions` backend (migration, model, policy, request, controller, routes, tests)

**Files:**
- Create (via `php artisan make:migration create_student_class_promotions_table --no-interaction`, then edit): `backend/database/migrations/<timestamp>_create_student_class_promotions_table.php`
- Create: `backend/app/Models/StudentClassPromotion.php`
- Create: `backend/app/Policies/StudentClassPromotionPolicy.php`
- Create: `backend/app/Http/Requests/Api/V1/StoreStudentPromotionRequest.php`
- Create: `backend/app/Http/Controllers/Api/V1/StudentPromotionController.php`
- Create: `backend/tests/Feature/StudentPromotionControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `App\Models\StudentClassPromotion` (plain model, no traits). Routes (`auth:sanctum`): `GET /api/v1/student-promotions/preview`, `POST /api/v1/student-promotions`.
- Consumes: `App\Support\MarksheetBuilder::build()` (Phase 3, already committed — exact signature `build(StudentProfile $profile, AcademicExamTerm $term, int $academicYearId): array`), `App\Models\AcademicExamTerm`, `App\Models\Section` (including its existing `enrolledCount(): int` method), `App\Models\SchoolClass` (including its existing `numeric_level` column), `App\Models\StudentProfile` — no changes to any of them.

- [ ] **Step 1: Generate and write the migration**

```bash
cd backend && php artisan make:migration create_student_class_promotions_table --no-interaction
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
     * A plain, append-only audit log — no Auditable/SoftDeletes/
     * TenantScoped, matching student_section_transfers' own minimal
     * shape exactly. Null to_* columns mean "retained" — a row is
     * written for every student in a promotion batch, promoted or not,
     * so the log shows the full decision, not just the moves.
     */
    public function up(): void
    {
        Schema::create('student_class_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_exam_term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('from_section_id')->constrained('sections')->cascadeOnDelete();
            $table->foreignId('to_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->foreignId('to_section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->foreignId('to_shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('to_class_version_id')->nullable()->constrained('class_versions')->nullOnDelete();

            $table->string('result');
            $table->decimal('gpa', 3, 2)->nullable();
            $table->foreignId('promoted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('promoted_at');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_class_promotions');
    }
};
```

- [ ] **Step 2: Create the model**

`backend/app/Models/StudentClassPromotion.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentClassPromotion extends Model
{
    protected $fillable = [
        'student_profile_id',
        'academic_exam_term_id',
        'academic_year_id',
        'from_class_id',
        'from_section_id',
        'to_class_id',
        'to_section_id',
        'to_shift_id',
        'to_class_version_id',
        'result',
        'gpa',
        'promoted_by_id',
        'promoted_at',
    ];

    protected function casts(): array
    {
        return [
            'gpa' => 'decimal:2',
            'promoted_at' => 'datetime',
        ];
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function academicExamTerm(): BelongsTo
    {
        return $this->belongsTo(AcademicExamTerm::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function fromClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'from_class_id');
    }

    public function fromSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'from_section_id');
    }

    public function toClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'to_class_id');
    }

    public function toSection(): BelongsTo
    {
        return $this->belongsTo(Section::class, 'to_section_id');
    }

    public function toShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'to_shift_id');
    }

    public function toClassVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class, 'to_class_version_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by_id');
    }
}
```

- [ ] **Step 3: Migrate and confirm the table exists**

```bash
cd backend && php artisan migrate --no-interaction
```

Expected: `student_class_promotions ... DONE`.

- [ ] **Step 4: Write the failing feature test**

`backend/tests/Feature/StudentPromotionControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\AcademicExamMark;
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
use App\Models\StudentClassPromotion;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class StudentPromotionControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class6;

    private SchoolClass $class7;

    private Section $section;

    private Section $toSection;

    private Subject $subject;

    private AcademicExamTerm $annualTerm;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class6 = SchoolClass::create(['name' => 'Class 6', 'numeric_level' => 6]);
        $this->class7 = SchoolClass::create(['name' => 'Class 7', 'numeric_level' => 7]);

        $version = ClassVersion::create(['class_id' => $this->class6->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $toVersion = ClassVersion::create(['class_id' => $this->class7->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $toShift = Shift::create(['class_version_id' => $toVersion->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->toSection = Section::create(['shift_id' => $toShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class6->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 100]);

        $this->annualTerm = AcademicExamTerm::create(['name' => 'Annual Examination', 'sort_order' => 3, 'is_annual' => true]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
    }

    private function studentInSection(?string $rollNumber, ?int $cqMarks): StudentProfile
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        $profile = StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class6->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'roll_number' => $rollNumber,
        ]);

        if ($cqMarks !== null) {
            AcademicExamMark::create([
                'student_profile_id' => $profile->id,
                'subject_id' => $this->subject->id,
                'academic_exam_term_id' => $this->annualTerm->id,
                'academic_year_id' => $this->year->id,
                'school_id' => $this->school->id,
                'district_id' => $this->school->district_id,
                'class_id' => $this->class6->id,
                'section_id' => $this->section->id,
                'shift_id' => $this->section->shift_id,
                'class_version_id' => $this->section->shift->class_version_id,
                'cq_marks_obtained' => $cqMarks,
            ]);
        }

        return $profile;
    }

    public function test_preview_defaults_passing_students_to_promote_and_failing_students_to_retain(): void
    {
        $pass = $this->studentInSection('1', 85);
        $fail = $this->studentInSection('2', 10);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertOk();

        $rows = collect($response->json('data.students'))->keyBy('student_profile_id');
        $this->assertSame('promote', $rows[$pass->id]['default_action']);
        $this->assertSame('pass', $rows[$pass->id]['result']);
        $this->assertSame('retain', $rows[$fail->id]['default_action']);
        $this->assertSame('fail', $rows[$fail->id]['result']);
    }

    public function test_preview_suggests_the_next_numeric_level_class(): void
    {
        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.suggested_to_class_id', $this->class7->id);
    }

    public function test_preview_marks_a_student_with_no_entered_marks_as_no_marks_defaulting_to_retain(): void
    {
        $ungraded = $this->studentInSection('1', null);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertOk();

        $row = collect($response->json('data.students'))->firstWhere('student_profile_id', $ungraded->id);
        $this->assertSame('no_marks', $row['result']);
        $this->assertSame('retain', $row['default_action']);
    }

    public function test_preview_requires_the_permission(): void
    {
        $viewer = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }

    public function test_preview_rejects_when_no_annual_term_is_configured(): void
    {
        $this->annualTerm->update(['is_annual' => false]);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/student-promotions/preview?section_id={$this->section->id}&academic_year_id={$this->year->id}")
            ->assertUnprocessable();
    }

    public function test_store_promotes_a_passing_student_by_moving_their_profile_in_place(): void
    {
        $pass = $this->studentInSection('1', 85);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertCreated();

        $pass->refresh();
        $this->assertSame($this->class7->id, $pass->class_id);
        $this->assertSame($this->toSection->id, $pass->section_id);
        $this->assertSame($this->toSection->shift_id, $pass->shift_id);
        $this->assertSame($this->toSection->shift->class_version_id, $pass->class_version_id);
        $this->assertSame('1', $pass->roll_number);
    }

    public function test_store_leaves_a_retained_students_profile_completely_untouched(): void
    {
        $fail = $this->studentInSection('2', 10);
        $originalAttributes = $fail->only(['class_id', 'section_id', 'shift_id', 'class_version_id']);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $fail->id, 'promote' => false]],
        ])->assertCreated();

        $fail->refresh();
        $this->assertSame($originalAttributes, $fail->only(['class_id', 'section_id', 'shift_id', 'class_version_id']));
    }

    public function test_store_writes_an_audit_row_for_every_student_with_null_to_fields_for_retained(): void
    {
        $pass = $this->studentInSection('1', 85);
        $fail = $this->studentInSection('2', 10);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [
                ['student_profile_id' => $pass->id, 'promote' => true],
                ['student_profile_id' => $fail->id, 'promote' => false],
            ],
        ])->assertCreated();

        $this->assertDatabaseCount('student_class_promotions', 2);
        $this->assertDatabaseHas('student_class_promotions', [
            'student_profile_id' => $pass->id, 'result' => 'pass', 'to_section_id' => $this->toSection->id,
            'to_class_id' => $this->class7->id, 'promoted_by_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('student_class_promotions', [
            'student_profile_id' => $fail->id, 'result' => 'fail', 'to_section_id' => null, 'to_class_id' => null,
        ]);
    }

    public function test_store_honors_a_per_student_override_in_both_directions(): void
    {
        $pass = $this->studentInSection('1', 85);
        $fail = $this->studentInSection('2', 10);

        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        // Override: promote the failing student, retain the passing one.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [
                ['student_profile_id' => $pass->id, 'promote' => false],
                ['student_profile_id' => $fail->id, 'promote' => true],
            ],
        ])->assertCreated();

        $pass->refresh();
        $fail->refresh();
        $this->assertSame($this->class6->id, $pass->class_id);
        $this->assertSame($this->class7->id, $fail->class_id);
    }

    public function test_store_requires_the_permission(): void
    {
        $pass = $this->studentInSection('1', 85);
        $viewer = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertForbidden();
    }

    public function test_store_is_forbidden_for_a_teacher_even_as_the_sections_own_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);
        $pass = $this->studentInSection('1', 85);

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertForbidden();
    }

    public function test_store_rejects_a_target_section_from_a_different_school(): void
    {
        $otherDivision = Division::create(['name' => 'Other Division', 'bn_name' => 'অন্য বিভাগ']);
        $otherDistrict = District::create(['division_id' => $otherDivision->id, 'name' => 'Other District', 'bn_name' => 'অন্য জেলা']);
        $otherUpazila = Upazila::create(['district_id' => $otherDistrict->id, 'name' => 'Other Upazila', 'bn_name' => 'অন্য উপজেলা']);
        $otherSchool = School::create(['division_id' => $otherDivision->id, 'district_id' => $otherDistrict->id, 'upazila_id' => $otherUpazila->id, 'name' => 'Other School']);
        $otherVersion = ClassVersion::create(['class_id' => $this->class7->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'General']);
        $otherShift = Shift::create(['class_version_id' => $otherVersion->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $otherSchoolSection = Section::create(['shift_id' => $otherShift->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Main']);

        $pass = $this->studentInSection('1', 85);
        $admin = $this->actorWithPermissions(['student_promotions.create']); // unscoped: national_admin-shaped actor, no school_id

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $otherSchoolSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_section_id');
    }

    public function test_store_rejects_promotion_exceeding_target_section_capacity_without_override(): void
    {
        $this->toSection->update(['capacity' => 1]);
        $existingTargetStudent = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $existingTargetStudent->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
            'class_id' => $this->class7->id, 'section_id' => $this->toSection->id,
            'shift_id' => $this->toSection->shift_id, 'class_version_id' => $this->toSection->shift->class_version_id,
            'roll_number' => '1',
        ]);

        $pass = $this->studentInSection('1', 85);
        $admin = $this->actorWithPermissions(['student_promotions.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('to_section_id');
    }

    public function test_store_allows_capacity_override_with_the_override_capacity_permission(): void
    {
        $this->toSection->update(['capacity' => 1]);
        $existingTargetStudent = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $existingTargetStudent->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
            'class_id' => $this->class7->id, 'section_id' => $this->toSection->id,
            'shift_id' => $this->toSection->shift_id, 'class_version_id' => $this->toSection->shift->class_version_id,
            'roll_number' => '1',
        ]);

        $pass = $this->studentInSection('1', 85);
        $admin = $this->actorWithPermissions(['student_promotions.create', 'sections.override_capacity'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/student-promotions', [
            'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id,
            'to_section_id' => $this->toSection->id,
            'override_capacity' => true,
            'records' => [['student_profile_id' => $pass->id, 'promote' => true]],
        ])->assertCreated();
    }
}
```

- [ ] **Step 5: Run the test to confirm it fails**

```bash
cd backend && php artisan test --compact --filter=StudentPromotionControllerTest
```

Expected: FAIL — route/model/policy/request/controller don't exist yet.

- [ ] **Step 6: Write the policy**

`backend/app/Policies/StudentClassPromotionPolicy.php`:

```php
<?php

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;

class StudentClassPromotionPolicy
{
    /**
     * The one ability this whole feature needs — gates both the
     * read-only preview and the actual promotion. school_admin/
     * national_admin only; no teacher exception of any kind, unlike
     * every per-subject/per-section ability elsewhere in this feature.
     */
    public function create(Authenticatable $user): bool
    {
        return $user->hasPermission('student_promotions.create');
    }
}
```

- [ ] **Step 7: Write the form request**

`backend/app/Http/Requests/Api/V1/StoreStudentPromotionRequest.php`:

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\StudentClassPromotion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentPromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', StudentClassPromotion::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', 'exists:sections,id'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'to_section_id' => ['required', 'integer', 'exists:sections,id'],
            'override_capacity' => ['sometimes', 'boolean'],
            'records' => ['required', 'array', 'min:1'],
            'records.*.student_profile_id' => ['required', 'integer'],
            'records.*.promote' => ['required', 'boolean'],
        ];
    }
}
```

- [ ] **Step 8: Write the controller**

`backend/app/Http/Controllers/Api/V1/StudentPromotionController.php`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreStudentPromotionRequest;
use App\Models\AcademicExamTerm;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentClassPromotion;
use App\Models\StudentProfile;
use App\Support\MarksheetBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentPromotionController extends Controller
{
    public function __construct(private readonly MarksheetBuilder $marksheetBuilder) {}

    /**
     * Read-only roster for one section's promotion decision: every
     * student's computed result/GPA for the Annual term, a suggested
     * promote/retain default, and the next numeric-level class as a
     * starting suggestion for the target class picker.
     */
    public function preview(Request $request): JsonResponse
    {
        $section = Section::with('shift.classVersion')->findOrFail($request->integer('section_id'));
        $this->authorize('create', StudentClassPromotion::class);

        $term = AcademicExamTerm::where('is_annual', true)->first();
        if ($term === null) {
            throw ValidationException::withMessages([
                'section_id' => ['No exam term is marked as the Annual/Final term yet.'],
            ]);
        }

        $academicYearId = $request->integer('academic_year_id');
        $fromClass = $section->shift?->classVersion?->schoolClass;
        $suggestedClass = $fromClass?->numeric_level !== null
            ? SchoolClass::where('numeric_level', $fromClass->numeric_level + 1)->first()
            : null;

        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        $rows = $students->map(function (StudentProfile $profile) use ($term, $academicYearId) {
            $marksheet = $this->marksheetBuilder->build($profile, $term, $academicYearId);
            $hasMarks = count($marksheet['subjects']) > 0;
            $result = $hasMarks ? $marksheet['result'] : 'no_marks';

            return [
                'student_profile_id' => $profile->id,
                'name' => $profile->user?->name,
                'roll_number' => $profile->roll_number,
                'result' => $result,
                'gpa' => $marksheet['gpa'],
                'default_action' => ($hasMarks && $marksheet['result'] === 'pass') ? 'promote' : 'retain',
            ];
        })->values();

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'academic_exam_term_id' => $term->id,
                'academic_year_id' => $academicYearId,
                'suggested_to_class_id' => $suggestedClass?->id,
                'suggested_to_class_name' => $suggestedClass?->bn_name ?? $suggestedClass?->name,
                'students' => $rows,
            ],
        ]);
    }

    /**
     * Confirm the batch: promoted students have their existing
     * StudentProfile row mutated in place (class_id/section_id/
     * shift_id/class_version_id); retained students are left completely
     * untouched. A student_class_promotions row is written for every
     * student either way — null to_* columns mean "retained."
     */
    public function store(StoreStudentPromotionRequest $request): JsonResponse
    {
        $section = Section::with('shift.classVersion')->findOrFail($request->integer('section_id'));
        $term = AcademicExamTerm::where('is_annual', true)->first();

        if ($term === null) {
            throw ValidationException::withMessages([
                'section_id' => ['No exam term is marked as the Annual/Final term yet.'],
            ]);
        }

        $academicYearId = $request->integer('academic_year_id');
        $toSection = Section::with('shift.classVersion')->findOrFail($request->integer('to_section_id'));

        // Same explicit cross-school guard StudentProfileController::
        // transferSection() already applies for a single-student move —
        // TenantScope alone isn't enough here since a national_admin's
        // queries aren't restricted to one school at all.
        if ($toSection->school_id !== $section->school_id) {
            throw ValidationException::withMessages([
                'to_section_id' => ['The selected section does not belong to the same school as the source section.'],
            ]);
        }

        $toShift = $toSection->shift;
        $toClassId = $toShift?->classVersion?->class_id;
        $fromClassId = $section->shift?->classVersion?->class_id;

        $actor = $request->user();
        $now = now();
        $records = $request->array('records');

        $overrideRequested = $request->boolean('override_capacity');

        if ($overrideRequested && ! $actor->hasPermission('sections.override_capacity')) {
            throw ValidationException::withMessages([
                'override_capacity' => ['You are not authorized to override section capacity.'],
            ]);
        }

        $promotingCount = count(array_filter($records, fn (array $record) => (bool) ($record['promote'] ?? false)));

        if (! $overrideRequested && $toSection->capacity !== null && ($toSection->enrolledCount() + $promotingCount) > $toSection->capacity) {
            throw ValidationException::withMessages([
                'to_section_id' => ['This section does not have enough remaining capacity for all promoted students.'],
            ]);
        }

        DB::transaction(function () use ($records, $section, $term, $academicYearId, $toSection, $toShift, $toClassId, $fromClassId, $actor, $now) {
            foreach ($records as $record) {
                $profile = StudentProfile::where('section_id', $section->id)->find($record['student_profile_id'] ?? 0);

                if ($profile === null) {
                    continue;
                }

                $marksheet = $this->marksheetBuilder->build($profile, $term, $academicYearId);
                $hasMarks = count($marksheet['subjects']) > 0;
                $result = $hasMarks ? $marksheet['result'] : 'no_marks';
                $promote = (bool) ($record['promote'] ?? false);

                StudentClassPromotion::create([
                    'student_profile_id' => $profile->id,
                    'academic_exam_term_id' => $term->id,
                    'academic_year_id' => $academicYearId,
                    'from_class_id' => $fromClassId,
                    'from_section_id' => $section->id,
                    'to_class_id' => $promote ? $toClassId : null,
                    'to_section_id' => $promote ? $toSection->id : null,
                    'to_shift_id' => $promote ? $toSection->shift_id : null,
                    'to_class_version_id' => $promote ? $toShift?->class_version_id : null,
                    'result' => $result,
                    'gpa' => $marksheet['gpa'],
                    'promoted_by_id' => $actor?->id,
                    'promoted_at' => $now,
                ]);

                if ($promote) {
                    $profile->update([
                        'class_id' => $toClassId,
                        'section_id' => $toSection->id,
                        'shift_id' => $toSection->shift_id,
                        'class_version_id' => $toShift?->class_version_id,
                    ]);
                }
            }
        });

        return response()->json(['data' => ['section_id' => $section->id, 'promoted' => true]], 201);
    }
}
```

- [ ] **Step 9: Register the routes**

In `backend/routes/api.php`, add the import:

```php
use App\Http\Controllers\Api\V1\StudentPromotionController;
```

Add to the `auth:sanctum` block, right after the `/academic-exam-publications` lines from Phase 2:

```php
Route::get('/student-promotions/preview', [StudentPromotionController::class, 'preview']);
Route::post('/student-promotions', [StudentPromotionController::class, 'store']);
```

- [ ] **Step 10: Run the test to confirm it passes**

```bash
cd backend && php artisan test --compact --filter=StudentPromotionControllerTest
```

Expected: all 14 tests green.

- [ ] **Step 11: Run Pint and the full suite**

```bash
cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact
```

Expected: Pint `{"result":"passed"}`; full suite fully green (no regressions).

- [ ] **Step 12: Commit**

```bash
cd backend && git add app/Models/StudentClassPromotion.php app/Policies/StudentClassPromotionPolicy.php app/Http/Requests/Api/V1/StoreStudentPromotionRequest.php app/Http/Controllers/Api/V1/StudentPromotionController.php database/migrations tests/Feature/StudentPromotionControllerTest.php routes/api.php
git commit -m "Add student_class_promotions: bulk promotion with per-student override"
```

---

### Task 2: Permissions

**Files:**
- Modify: `backend/database/seeders/PermissionSeeder.php`
- Modify: `backend/database/seeders/RolePermissionSeeder.php`

**Interfaces:**
- Produces: 1 new permission row (`student_promotions.create`), granted to `school_admin` only (plus `national_admin` automatically).
- Consumes: Task 1's permission name exactly as used in `StudentClassPromotionPolicy` — must match verbatim.

- [ ] **Step 1: Add the permission**

In `backend/database/seeders/PermissionSeeder.php`, add after the `academic_exam_marks.*` block added in Phase 2:

```php
// Granularity precedent: sections.override_capacity — a single,
// standalone action permission, not split into .view/.create/.update/
// .delete. No teacher exception anywhere: promotion is admin-only.
['name' => 'student_promotions.create', 'label' => 'Promote students to the next class'],
```

- [ ] **Step 2: Grant to `school_admin` only**

In `backend/database/seeders/RolePermissionSeeder.php`, find the `school_admin` array's `'academic_exam_marks.view', 'academic_exam_marks.update', 'academic_exam_marks.publish',` line (added in Phase 2) and add right after it:

```php
'academic_exam_marks.view', 'academic_exam_marks.update', 'academic_exam_marks.publish',
'student_promotions.create',
```

Do **not** add this to any other role's array — not `teacher`, not any of the roles that get `academic_exam_marks.view` only. `national_admin` needs no change (already gets `$allPermissions`).

- [ ] **Step 3: Re-seed and verify**

```bash
cd backend && php artisan db:seed --class=PermissionSeeder --no-interaction && php artisan db:seed --class=RolePermissionSeeder --no-interaction
```

```bash
php artisan tinker --execute '
$schoolAdmin = App\Models\Role::where("name", "school_admin")->first();
echo $schoolAdmin->permissions()->where("name", "student_promotions.create")->count() . PHP_EOL;
$teacher = App\Models\Role::where("name", "teacher")->first();
echo $teacher->permissions()->where("name", "student_promotions.create")->count() . PHP_EOL;
'
```

Expected: school_admin line is `1`; teacher line is `0`.

- [ ] **Step 4: Run the full backend suite**

```bash
cd backend && php artisan test --compact && vendor/bin/pint --dirty --format agent
```

Expected: both green — this task touches only seeders, which no feature test depends on (tests create their own ad-hoc permissions).

- [ ] **Step 5: Commit**

```bash
cd backend && git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php
git commit -m "Grant student_promotions.create to school_admin only"
```

---

### Task 3: `api-client` additions

**Files:**
- Modify: `frontend/packages/api-client/src/index.ts`

**Interfaces:**
- Produces: TypeScript types `StudentPromotionPreviewRow`, `StudentPromotionPreview`, and functions `getStudentPromotionPreview`, `confirmStudentPromotions` — **these exact names are what Task 4's page imports.**
- Consumes: the existing `apiFetch`/`authHeaders`/`buildQuery` helpers already used throughout this file.

- [ ] **Step 1: Add the section**

Append to the end of `frontend/packages/api-client/src/index.ts`:

```typescript
// ---------- Student promotions ----------

export interface StudentPromotionPreviewRow {
  student_profile_id: number
  name: string | null
  roll_number: string | null
  result: 'pass' | 'fail' | 'no_marks'
  gpa: number
  default_action: 'promote' | 'retain'
}

export interface StudentPromotionPreview {
  section_id: number
  academic_exam_term_id: number
  academic_year_id: number
  suggested_to_class_id: number | null
  suggested_to_class_name: string | null
  students: StudentPromotionPreviewRow[]
}

export function getStudentPromotionPreview(
  params: { sectionId: number; academicYearId: number },
  token: string,
): Promise<{ data: StudentPromotionPreview }> {
  const query = buildQuery({ section_id: params.sectionId, academic_year_id: params.academicYearId })

  return apiFetch<{ data: StudentPromotionPreview }>(`/student-promotions/preview${query}`, {
    headers: authHeaders(token),
  })
}

// Confirms a promotion batch — promoted students (per `records[].promote`)
// have their StudentProfile moved to `to_section_id` in place; retained
// students are left untouched. A student_class_promotions audit row is
// written for every record either way. `override_capacity` requires the
// acting user to hold the existing `sections.override_capacity` permission.
export function confirmStudentPromotions(
  payload: {
    section_id: number
    academic_year_id: number
    to_section_id: number
    override_capacity?: boolean
    records: { student_profile_id: number; promote: boolean }[]
  },
  token: string,
): Promise<{ data: { section_id: number; promoted: boolean } }> {
  return apiFetch<{ data: { section_id: number; promoted: boolean } }>('/student-promotions', {
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
git commit -m "Add api-client types/functions for student promotion"
```

---

### Task 4: Management — Promote Students page

**Files:**
- Create: `frontend/apps/management/src/pages/PromoteStudentsPage.tsx`
- Modify: `frontend/apps/management/src/App.tsx`
- Modify: `frontend/packages/i18n/src/common/en.json`
- Modify: `frontend/packages/i18n/src/common/bn.json`

**Interfaces:**
- Consumes: `getStudentPromotionPreview`/`confirmStudentPromotions` and their types from Task 3; the existing `listSchools`/`listSections`/`listAcademicYears`/`listClasses` (used by `AttendanceReportPage.tsx`/`PublishResultsPage.tsx`) and the existing `SearchableSelect`/`inputClass`/`buttonPrimary`/`buttonNeutral` components and this app's own `useToast`/`ToastViewport`.
- Produces: route `/dashboard/promote-students`, a flat nav item visible to `school_admin`/`national_admin` (same tier as `attendanceNavItem`/`publishResultsNavItem`).

- [ ] **Step 1: Add i18n keys**

In `frontend/packages/i18n/src/common/en.json`, add:

```json
"management.nav.promoteStudents": "Promote Students",
"management.promoteStudents.title": "Promote Students",
"management.promoteStudents.selectSection": "Select section",
"management.promoteStudents.selectTargetClass": "Target class",
"management.promoteStudents.selectTargetSection": "Target section",
"management.promoteStudents.loading": "Loading…",
"management.promoteStudents.pickSectionAndYear": "Pick a section and academic year to load the roster.",
"management.promoteStudents.colRoll": "Roll",
"management.promoteStudents.colStudent": "Student",
"management.promoteStudents.colResult": "Result",
"management.promoteStudents.colGpa": "GPA",
"management.promoteStudents.colAction": "Action",
"management.promoteStudents.resultPass": "Pass",
"management.promoteStudents.resultFail": "Fail",
"management.promoteStudents.resultNoMarks": "No marks",
"management.promoteStudents.actionPromote": "Promote",
"management.promoteStudents.actionRetain": "Retain",
"management.promoteStudents.overrideCapacity": "Allow this to exceed the target section's capacity",
"management.promoteStudents.confirm": "Confirm Promotion",
"management.promoteStudents.confirming": "Confirming…",
"management.promoteStudents.confirmSuccess": "Students promoted successfully.",
"management.promoteStudents.confirmError": "Could not confirm promotion. Please try again.",
"management.promoteStudents.pickTarget": "Pick a target class and section before confirming."
```

In `frontend/packages/i18n/src/common/bn.json`, mirrored with real Bengali translations:

```json
"management.nav.promoteStudents": "শিক্ষার্থী উত্তরণ",
"management.promoteStudents.title": "শিক্ষার্থী উত্তরণ",
"management.promoteStudents.selectSection": "শাখা নির্বাচন করুন",
"management.promoteStudents.selectTargetClass": "লক্ষ্য শ্রেণি",
"management.promoteStudents.selectTargetSection": "লক্ষ্য শাখা",
"management.promoteStudents.loading": "লোড হচ্ছে…",
"management.promoteStudents.pickSectionAndYear": "রোস্টার লোড করতে একটি শাখা এবং শিক্ষাবর্ষ নির্বাচন করুন।",
"management.promoteStudents.colRoll": "রোল",
"management.promoteStudents.colStudent": "শিক্ষার্থী",
"management.promoteStudents.colResult": "ফলাফল",
"management.promoteStudents.colGpa": "জিপিএ",
"management.promoteStudents.colAction": "কার্যক্রম",
"management.promoteStudents.resultPass": "উত্তীর্ণ",
"management.promoteStudents.resultFail": "অকৃতকার্য",
"management.promoteStudents.resultNoMarks": "নম্বর নেই",
"management.promoteStudents.actionPromote": "উত্তরণ",
"management.promoteStudents.actionRetain": "অবনমন",
"management.promoteStudents.overrideCapacity": "লক্ষ্য শাখার ধারণক্ষমতা অতিক্রম করার অনুমতি দিন",
"management.promoteStudents.confirm": "উত্তরণ নিশ্চিত করুন",
"management.promoteStudents.confirming": "নিশ্চিত করা হচ্ছে…",
"management.promoteStudents.confirmSuccess": "শিক্ষার্থীদের সফলভাবে উত্তরণ করা হয়েছে।",
"management.promoteStudents.confirmError": "উত্তরণ নিশ্চিত করা যায়নি। আবার চেষ্টা করুন।",
"management.promoteStudents.pickTarget": "নিশ্চিত করার আগে একটি লক্ষ্য শ্রেণি এবং শাখা নির্বাচন করুন।"
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

`frontend/apps/management/src/pages/PromoteStudentsPage.tsx`:

```tsx
import { useEffect, useMemo, useState } from 'react'
import {
  ApiError,
  confirmStudentPromotions,
  getStudentPromotionPreview,
  listAcademicYears,
  listClasses,
  listSchools,
  listSections,
  type AcademicYear,
  type School,
  type SchoolClass,
  type Section,
  type StudentPromotionPreview,
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

const RESULT_LABEL: Record<string, string> = {
  pass: t['management.promoteStudents.resultPass'],
  fail: t['management.promoteStudents.resultFail'],
  no_marks: t['management.promoteStudents.resultNoMarks'],
}

const RESULT_BADGE: Record<string, string> = {
  pass: 'bg-success-50 text-success-600',
  fail: 'bg-danger-50 text-danger-600',
  no_marks: 'bg-gray-100 text-gray-500',
}

// Bulk-per-section with a per-student override — one target class/section
// for the whole batch; the only per-student choice is promote vs. retain.
export default function PromoteStudentsPage() {
  const { token } = useAuth()
  const { toast, showToast } = useToast()

  const [schools, setSchools] = useState<School[]>([])
  const [schoolId, setSchoolId] = useState<number | null>(null)

  const [sections, setSections] = useState<Section[]>([])
  const [sectionId, setSectionId] = useState<number | null>(null)

  const [years, setYears] = useState<AcademicYear[]>([])
  const [academicYearId, setAcademicYearId] = useState<number | null>(null)

  const [loadingPreview, setLoadingPreview] = useState(false)
  const [preview, setPreview] = useState<StudentPromotionPreview | null>(null)
  const [actions, setActions] = useState<Record<number, boolean>>({})

  const [classes, setClasses] = useState<SchoolClass[]>([])
  const [toClassId, setToClassId] = useState<number | null>(null)
  const [targetSections, setTargetSections] = useState<Section[]>([])
  const [toSectionId, setToSectionId] = useState<number | null>(null)
  const [overrideCapacity, setOverrideCapacity] = useState(false)

  const [confirming, setConfirming] = useState(false)

  useEffect(() => {
    listSchools({ perPage: 200 })
      .then((result) => {
        setSchools(result.data)
        setSchoolId((previous) => previous ?? result.data[0]?.id ?? null)
      })
      .catch(() => setSchools([]))

    listClasses({ perPage: 200 })
      .then((result) => setClasses(result.data))
      .catch(() => setClasses([]))

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
    if (!token || sectionId === null || academicYearId === null) {
      setPreview(null)
      setActions({})
      return
    }

    let active = true
    setLoadingPreview(true)

    getStudentPromotionPreview({ sectionId, academicYearId }, token)
      .then((result) => {
        if (!active) return
        setPreview(result.data)
        setActions(
          Object.fromEntries(result.data.students.map((row) => [row.student_profile_id, row.default_action === 'promote'])),
        )
        setToClassId(result.data.suggested_to_class_id)
      })
      .catch(() => {
        if (active) setPreview(null)
      })
      .finally(() => {
        if (active) setLoadingPreview(false)
      })

    return () => {
      active = false
    }
  }, [token, sectionId, academicYearId])

  useEffect(() => {
    if (!toClassId || !schoolId) {
      setTargetSections([])
      setToSectionId(null)
      return
    }

    let active = true

    listSections({ classId: toClassId, schoolId, perPage: 200 })
      .then((result) => {
        if (!active) return
        setTargetSections(result.data)
        setToSectionId(null)
      })
      .catch(() => {
        if (active) setTargetSections([])
      })

    return () => {
      active = false
    }
  }, [toClassId, schoolId])

  const promotingCount = useMemo(() => Object.values(actions).filter(Boolean).length, [actions])

  async function handleConfirm() {
    if (!token || sectionId === null || academicYearId === null || toSectionId === null) return

    setConfirming(true)

    try {
      await confirmStudentPromotions(
        {
          section_id: sectionId,
          academic_year_id: academicYearId,
          to_section_id: toSectionId,
          override_capacity: overrideCapacity,
          records: Object.entries(actions).map(([studentProfileId, promote]) => ({
            student_profile_id: Number(studentProfileId),
            promote,
          })),
        },
        token,
      )
      showToast({ type: 'success', message: t['management.promoteStudents.confirmSuccess'] })
      setPreview(null)
      setActions({})
    } catch (caught) {
      showToast({ type: 'error', message: caught instanceof ApiError ? caught.message : t['management.promoteStudents.confirmError'] })
    } finally {
      setConfirming(false)
    }
  }

  return (
    <section>
      <h1 className="text-2xl font-bold text-gray-900">{t['management.promoteStudents.title']}</h1>

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
          <span className="font-medium text-gray-700">{t['management.promoteStudents.selectSection']}</span>
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

      {loadingPreview && <p className="mt-6 text-sm text-gray-500">{t['management.promoteStudents.loading']}</p>}

      {!loadingPreview && !preview && sectionId !== null && academicYearId !== null && (
        <p className="mt-6 text-sm text-gray-500">{t['management.promoteStudents.pickSectionAndYear']}</p>
      )}

      {!loadingPreview && preview && (
        <>
          <div className="mt-4 overflow-x-auto rounded-md bg-white shadow-sm">
            <table className="w-full min-w-[36rem] text-sm">
              <thead className="bg-primary-50 text-xs font-medium tracking-wide text-gray-500 uppercase">
                <tr>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colRoll']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colStudent']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colResult']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colGpa']}</th>
                  <th className="px-5 py-3 text-left whitespace-nowrap">{t['management.promoteStudents.colAction']}</th>
                </tr>
              </thead>
              <tbody>
                {preview.students.map((row) => {
                  const promote = actions[row.student_profile_id] ?? false

                  return (
                    <tr key={row.student_profile_id} className="border-t border-gray-100">
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-500">{row.roll_number ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap font-medium text-gray-900">{row.name ?? '—'}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <span className={`rounded-full px-2.5 py-0.5 text-xs font-medium ${RESULT_BADGE[row.result]}`}>
                          {RESULT_LABEL[row.result]}
                        </span>
                      </td>
                      <td className="px-5 py-3.5 whitespace-nowrap text-gray-600">{row.gpa.toFixed(2)}</td>
                      <td className="px-5 py-3.5 whitespace-nowrap">
                        <div className="flex gap-2">
                          <button
                            type="button"
                            onClick={() => setActions((previous) => ({ ...previous, [row.student_profile_id]: true }))}
                            className={`rounded-md border px-2.5 py-1 text-xs font-semibold transition-colors ${
                              promote ? 'border-success-500 bg-success-50 text-success-600' : 'border-gray-200 text-gray-500 hover:border-primary-300'
                            }`}
                          >
                            {t['management.promoteStudents.actionPromote']}
                          </button>
                          <button
                            type="button"
                            onClick={() => setActions((previous) => ({ ...previous, [row.student_profile_id]: false }))}
                            className={`rounded-md border px-2.5 py-1 text-xs font-semibold transition-colors ${
                              !promote ? 'border-danger-500 bg-danger-50 text-danger-600' : 'border-gray-200 text-gray-500 hover:border-primary-300'
                            }`}
                          >
                            {t['management.promoteStudents.actionRetain']}
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex flex-wrap items-end gap-3 rounded-md bg-white p-4 shadow-sm">
            <label className="flex flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['management.promoteStudents.selectTargetClass']}</span>
              <select
                className={`${inputClass} min-w-[10rem]`}
                value={toClassId ?? ''}
                onChange={(event) => setToClassId(event.target.value ? Number(event.target.value) : null)}
              >
                <option value="">—</option>
                {classes.map((schoolClass) => (
                  <option key={schoolClass.id} value={schoolClass.id}>
                    {schoolClass.bn_name ?? schoolClass.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="flex flex-col gap-1.5 text-sm">
              <span className="font-medium text-gray-700">{t['management.promoteStudents.selectTargetSection']}</span>
              <select
                className={`${inputClass} min-w-[12rem]`}
                value={toSectionId ?? ''}
                disabled={toClassId === null}
                onChange={(event) => setToSectionId(event.target.value ? Number(event.target.value) : null)}
              >
                <option value="">—</option>
                {targetSections.map((section) => (
                  <option key={section.id} value={section.id}>
                    {sectionLabel(section)}
                  </option>
                ))}
              </select>
            </label>

            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input type="checkbox" checked={overrideCapacity} onChange={(event) => setOverrideCapacity(event.target.checked)} />
              {t['management.promoteStudents.overrideCapacity']}
            </label>
          </div>

          {toSectionId === null && <p className="mt-3 text-sm text-gray-500">{t['management.promoteStudents.pickTarget']}</p>}

          <div className="mt-4">
            <button
              type="button"
              onClick={handleConfirm}
              disabled={confirming || toSectionId === null}
              className={buttonPrimary}
            >
              {confirming
                ? t['management.promoteStudents.confirming']
                : `${t['management.promoteStudents.confirm']} (${promotingCount})`}
            </button>
          </div>
        </>
      )}

      <ToastViewport toast={toast} />
    </section>
  )
}
```

- [ ] **Step 3: Wire the route and nav item**

In `frontend/apps/management/src/App.tsx`, add the import:

```tsx
import PromoteStudentsPage from './pages/PromoteStudentsPage'
```

Add a new flat nav item, right after `publishResultsNavItem`'s own definition (from Phase 2):

```tsx
// Same placement/visibility reasoning as attendanceNavItem/publishResultsNavItem
// above — school_admin/national_admin only.
const promoteStudentsNavItem: SidebarItem = { to: '/dashboard/promote-students', label: t['management.nav.promoteStudents'], icon: 'move_up' }
```

Add it to the `navItems` array inside `DashboardLayout`, in the same conditional as `attendanceNavItem`/`publishResultsNavItem`:

```tsx
...(role === 'national_admin' || role === 'school_admin' ? [teachersNavItem, studentsNavItem, attendanceNavItem, publishResultsNavItem, promoteStudentsNavItem] : []),
```

Add the route inside the existing `RequireSchoolManager` block, after the `publish-results` route:

```tsx
<Route path="promote-students" element={<PromoteStudentsPage />} />
```

- [ ] **Step 4: Typecheck and build**

```bash
cd frontend/apps/management && npx tsc -b --noEmit && npm run build
```

- [ ] **Step 5: Commit**

```bash
cd frontend && git add apps/management/src/pages/PromoteStudentsPage.tsx apps/management/src/App.tsx packages/i18n/src/common/en.json packages/i18n/src/common/bn.json
git commit -m "Add management Promote Students page"
```

---

### Task 5: End-to-end verification

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

Using the data seeded by Phases 1-3's own smoke tests (a section with some passing and some failing students for the Annual term, a target class/section already set up for the next academic year via System Setup):

1. As a school_admin: navigate to Promote Students, pick the school/section/academic year, confirm the roster loads with each student's computed Result (Pass/Fail/No marks) and GPA, confirm passing students default to "Promote" (highlighted) and failing/no-marks students default to "Retain".
2. Flip one passing student to "Retain" and one failing student to "Promote" — confirm the toggle buttons visually update.
3. Confirm the Target Class dropdown defaults to the suggested next class, and picking it populates the Target Section dropdown scoped to that class/school.
4. Click Confirm Promotion. Confirm the promoted students' profiles now show the new class/section (e.g. via the Students page), and the retained students are completely unchanged.
5. As a teacher (including the section's own class teacher): confirm there is no "Promote Students" nav item at all, and confirm `POST /api/v1/student-promotions` is rejected if attempted directly.
6. Confirm a second promotion attempt for the same already-promoted section/year doesn't error oddly — promoted students simply won't appear in a future preview for their OLD section (since they've moved), and running the SAME preview again for the same section will now only show whichever students weren't promoted (retained ones), which is expected since the action already moved the others.

Report back what you observed; this step has no pass/fail assertion beyond "did the page behave as described."

- [ ] **Step 4: No commit for this task** (verification-only).

---

## What this plan does NOT cover

This is the final phase of the Academic Exam Results feature per the spec — there is nothing further deferred. Any future enhancement (e.g. undoing a promotion, bulk-promoting multiple sections at once, a promotion history view) is a new feature request, not a gap in this spec.
