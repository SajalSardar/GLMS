# Automatic Timetable Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `POST /shifts/{shift}/timetable-entries/generate`, which fills
every section under a shift with conflict-free `TimetableEntry` drafts for
each assigned subject's full `required_weekly_periods`, per-section
all-or-nothing, never touching published entries.

**Architecture:** Extract the hard-constraint checks already living in
`TimetableEntryController` into a shared `TimetableConflictChecker`, add a
`TimetablePeriodGrid` to derive a Shift's period slots from its
start/end/duration/breaks, and build a `TimetableGenerator` that does
most-constrained-first greedy placement (reusing the checker) plus a
bounded local-search polish, all behind one new controller action.

**Tech Stack:** Laravel 13 / PHP 8.3+, PHPUnit, SQLite in-memory for tests
(foreign keys enforced).

**Spec:** `docs/superpowers/specs/2026-11-05-automatic-timetable-generation-design.md`

## Global Constraints

- No new Composer dependency (no queue/job infrastructure, no
  constraint-solver library — confirmed absent from `composer.json`).
- `TimetableEntryController::store()`/`update()`'s existing behavior must
  not change for any existing caller — the extraction in Task 1 is a
  refactor, not a rewrite. Where the existing code throws
  `ValidationException` with a specific field key (`teacher_id`,
  `day_of_week`, `start_time`, `subject_id`, `classroom_id`), the
  extracted service must preserve that exact field key so the thrown
  exception is identical to today's.
- Published `TimetableEntry` rows are never updated or deleted by the
  generator, under any circumstance.
- Every new support class is pure business logic — no HTTP concerns, no
  thrown `ValidationException` — matching `StudentDashboardBuilder`/
  `QuestionCsvImporter`'s existing precedent in this codebase.
- Run `vendor/bin/pint --dirty --format agent` before each commit that
  touches a `.php` file (per this repo's own CLAUDE.md convention).
- Run the full suite (`php artisan test --compact`) at the end of every
  task — zero regressions, every time.

---

### Task 1: Extract `TimetableConflictChecker`

**Files:**
- Create: `app/Support/TimetableConflictChecker.php`
- Modify: `app/Http/Controllers/Api/V1/TimetableEntryController.php:1-343`
  (replace the bodies of `assertValid()`/`assertNoConflicts()`, delete the
  now-dead `assertWithinShiftHours()` private method, update imports)
- Test: `tests/Unit/TimetableConflictCheckerTest.php`

**Interfaces:**
- Produces:
  `TimetableConflictChecker::invalidReason(Section $section, array $attributes, ?int $exceptId = null): ?array` —
  returns `null` if valid, else `['field' => string, 'message' => string]`.
  `$attributes` keys: `subject_id` (int), `teacher_id` (int),
  `classroom_id` (?int), `day_of_week` (string), `period_no` (int),
  `start_time` (?string, key may be absent), `end_time` (?string, key may
  be absent).
  `TimetableConflictChecker::conflictReason(array $attributes, ?int $exceptId = null): ?array` —
  same return shape. `$attributes` keys: `teacher_id` (int),
  `classroom_id` (?int), `day_of_week` (string), `period_no` (int).
- Consumes: nothing from other tasks (first task).

- [ ] **Step 1: Write the fixture-building `setUp()` and the full test file**

Create `tests/Unit/TimetableConflictCheckerTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Classroom;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\Upazila;
use App\Models\User;
use App\Support\TimetableConflictChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableConflictCheckerTest extends TestCase
{
    use RefreshDatabase;

    private TimetableConflictChecker $checker;

    private School $school;

    private ClassVersion $classVersion;

    private Shift $shift;

    private Section $section;

    private Subject $subject;

    private User $teacher;

    private SubjectTeacherAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checker = new TimetableConflictChecker();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);

        $this->classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $this->shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday', 'sunday'],
            'breaks' => [['start_time' => '10:00', 'end_time' => '10:20', 'label' => 'Tiffin']],
        ]);

        $this->section = Section::create([
            'shift_id' => $this->shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section',
            'capacity' => 30,
        ]);

        $this->subject = Subject::create(['name' => 'Math']);
        $this->classVersion->subjects()->attach($this->subject->id, ['required_weekly_periods' => 1]);

        $this->teacher = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->assignment = SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validAttributes(): array
    {
        return [
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'classroom_id' => null,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'start_time' => '08:00',
            'end_time' => '08:50',
        ];
    }

    public function test_valid_attributes_return_null(): void
    {
        $this->assertNull($this->checker->invalidReason($this->section, $this->validAttributes()));
    }

    public function test_missing_time_keys_are_treated_as_null_and_skip_the_hours_check(): void
    {
        $attributes = $this->validAttributes();
        unset($attributes['start_time'], $attributes['end_time']);

        $this->assertNull($this->checker->invalidReason($this->section, $attributes));
    }

    public function test_teacher_without_assignment_is_invalid(): void
    {
        $other = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $attributes = [...$this->validAttributes(), 'teacher_id' => $other->id];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('teacher_id', $error['field']);
    }

    public function test_day_not_in_shift_schedule_is_invalid(): void
    {
        $attributes = [...$this->validAttributes(), 'day_of_week' => 'friday'];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('day_of_week', $error['field']);
    }

    public function test_time_outside_shift_hours_is_invalid(): void
    {
        $attributes = [...$this->validAttributes(), 'start_time' => '13:00', 'end_time' => '13:50'];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('start_time', $error['field']);
    }

    public function test_time_overlapping_a_break_is_invalid(): void
    {
        $attributes = [...$this->validAttributes(), 'start_time' => '09:50', 'end_time' => '10:40'];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('start_time', $error['field']);
    }

    public function test_weekly_cap_exceeded_is_invalid(): void
    {
        TimetableEntry::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);

        $attributes = [...$this->validAttributes(), 'day_of_week' => 'sunday'];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('subject_id', $error['field']);
    }

    public function test_weekly_cap_check_excludes_the_given_except_id(): void
    {
        $existing = TimetableEntry::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);

        $this->assertNull($this->checker->invalidReason($this->section, $this->validAttributes(), $existing->id));
    }

    public function test_classroom_from_another_school_is_invalid(): void
    {
        $division = Division::create(['name' => 'Division B', 'bn_name' => 'বিভাগ২']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা২']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা২']);
        $otherSchool = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School B']);
        $classroom = Classroom::create(['school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Room 1']);

        $attributes = [...$this->validAttributes(), 'classroom_id' => $classroom->id];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('classroom_id', $error['field']);
    }

    public function test_classroom_with_insufficient_capacity_is_invalid(): void
    {
        $classroom = Classroom::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Room 1', 'capacity' => 10]);

        $attributes = [...$this->validAttributes(), 'classroom_id' => $classroom->id];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('classroom_id', $error['field']);
    }

    public function test_teacher_from_another_school_is_invalid(): void
    {
        // (section_id, subject_id) is unique on subject_teacher_assignments,
        // so the only way to reach the final "teacher belongs to this
        // school" check — past the earlier "teacher matches the
        // assignment" check — is to repoint the existing assignment's
        // teacher, not create a second one for the same pair.
        $otherSchoolTeacher = User::factory()->create(['school_id' => null, 'district_id' => null]);
        $this->assignment->update(['teacher_id' => $otherSchoolTeacher->id]);

        $attributes = [...$this->validAttributes(), 'teacher_id' => $otherSchoolTeacher->id];

        $error = $this->checker->invalidReason($this->section, $attributes);

        $this->assertSame('teacher_id', $error['field']);
    }

    public function test_no_conflict_returns_null(): void
    {
        $attributes = ['teacher_id' => $this->teacher->id, 'classroom_id' => null, 'day_of_week' => 'saturday', 'period_no' => 1];

        $this->assertNull($this->checker->conflictReason($attributes));
    }

    public function test_teacher_double_booked_is_a_conflict(): void
    {
        TimetableEntry::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);

        $attributes = ['teacher_id' => $this->teacher->id, 'classroom_id' => null, 'day_of_week' => 'saturday', 'period_no' => 1];

        $error = $this->checker->conflictReason($attributes);

        $this->assertSame('teacher_id', $error['field']);
    }

    public function test_classroom_double_booked_is_a_conflict(): void
    {
        $classroom = Classroom::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Room 1']);

        TimetableEntry::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'classroom_id' => $classroom->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);

        $otherTeacher = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        $attributes = ['teacher_id' => $otherTeacher->id, 'classroom_id' => $classroom->id, 'day_of_week' => 'saturday', 'period_no' => 1];

        $error = $this->checker->conflictReason($attributes);

        $this->assertSame('classroom_id', $error['field']);
    }

    public function test_conflict_check_excludes_the_given_except_id(): void
    {
        $existing = TimetableEntry::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);

        $attributes = ['teacher_id' => $this->teacher->id, 'classroom_id' => null, 'day_of_week' => 'saturday', 'period_no' => 1];

        $this->assertNull($this->checker->conflictReason($attributes, $existing->id));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Unit/TimetableConflictCheckerTest.php --compact`
Expected: FAIL — `App\Support\TimetableConflictChecker` does not exist.

- [ ] **Step 3: Create `app/Support/TimetableConflictChecker.php`**

```php
<?php

namespace App\Support;

use App\Models\Classroom;
use App\Models\Section;
use App\Models\Shift;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Hard-constraint validity and conflict checks for a single TimetableEntry
 * slot, shared by TimetableEntryController's manual store()/update() path
 * and TimetableGenerator's automatic placement path — the two must never
 * independently re-derive the same rule and drift apart. Pure: no HTTP
 * concerns, no exceptions. A null return means "valid"; a
 * ['field' => ..., 'message' => ...] return names the violated field (so
 * callers that throw ValidationException can keep using the exact same
 * field key callers have always used) and a human-readable reason.
 */
class TimetableConflictChecker
{
    /**
     * Configuration guards: the subject must have a valid teacher
     * assignment for this section (hard constraint #9), the day must be
     * one the section's shift actually runs, the period's time (if given)
     * must fall within the shift's hours and outside its breaks, the
     * weekly period cap for the subject must not be exceeded, and room
     * capacity must be sufficient.
     *
     * @param  array{subject_id:int, teacher_id:int, classroom_id:?int, day_of_week:string, period_no:int, start_time?:?string, end_time?:?string}  $attributes
     * @return array{field: string, message: string}|null
     */
    public function invalidReason(Section $section, array $attributes, ?int $exceptId = null): ?array
    {
        $shift = $section->shift;
        $classVersion = $shift->classVersion;

        $assignment = SubjectTeacherAssignment::where('section_id', $section->id)
            ->where('subject_id', $attributes['subject_id'])
            ->first();

        if (! $assignment || $assignment->teacher_id !== $attributes['teacher_id']) {
            return ['field' => 'teacher_id', 'message' => 'This teacher has no valid subject assignment for this section.'];
        }

        if (! in_array($attributes['day_of_week'], $shift->days_of_week, true)) {
            return ['field' => 'day_of_week', 'message' => 'This day is not part of the section\'s shift schedule.'];
        }

        $startTime = $attributes['start_time'] ?? null;
        $endTime = $attributes['end_time'] ?? null;

        if ($startTime !== null && $endTime !== null) {
            $error = $this->shiftHoursError($shift, $startTime, $endTime);

            if ($error !== null) {
                return $error;
            }
        }

        $pivot = $classVersion->subjects()->where('subjects.id', $attributes['subject_id'])->first()?->pivot;
        $requiredWeekly = $pivot?->required_weekly_periods;

        if ($requiredWeekly !== null) {
            $scheduledCount = TimetableEntry::where('section_id', $section->id)
                ->where('subject_id', $attributes['subject_id'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->count();

            if ($scheduledCount >= $requiredWeekly) {
                return ['field' => 'subject_id', 'message' => "This subject already has its configured {$requiredWeekly} weekly period(s) scheduled."];
            }
        }

        if ($attributes['classroom_id'] !== null) {
            $classroom = Classroom::find($attributes['classroom_id']);

            if (! $classroom || $classroom->school_id !== $section->school_id) {
                return ['field' => 'classroom_id', 'message' => 'The selected classroom does not belong to this section\'s school.'];
            }

            if ($classroom->capacity !== null && $section->capacity !== null && $classroom->capacity < $section->capacity) {
                return ['field' => 'classroom_id', 'message' => 'This classroom\'s capacity is smaller than the section\'s capacity.'];
            }
        }

        if (User::where('id', $attributes['teacher_id'])->where('school_id', $section->school_id)->doesntExist()) {
            return ['field' => 'teacher_id', 'message' => 'The selected teacher does not belong to this section\'s school.'];
        }

        return null;
    }

    /**
     * @return array{field: string, message: string}|null
     */
    private function shiftHoursError(Shift $shift, string $startTime, string $endTime): ?array
    {
        $start = Carbon::parse($startTime);
        $end = Carbon::parse($endTime);
        $shiftStart = Carbon::parse($shift->start_time->format('H:i'));
        $shiftEnd = Carbon::parse($shift->end_time->format('H:i'));

        if ($start->lessThan($shiftStart) || $end->greaterThan($shiftEnd)) {
            return ['field' => 'start_time', 'message' => 'This period falls outside the section\'s shift hours.'];
        }

        foreach ($shift->breaks ?? [] as $break) {
            $breakStart = Carbon::parse($break['start_time']);
            $breakEnd = Carbon::parse($break['end_time']);

            if ($start->lessThan($breakEnd) && $end->greaterThan($breakStart)) {
                return ['field' => 'start_time', 'message' => 'This period overlaps a configured break interval.'];
            }
        }

        return null;
    }

    /**
     * Hard constraints: a teacher or a classroom can't be double-booked in
     * the same period on the same day. The section/day/period triplet
     * itself is enforced by a DB unique index instead.
     *
     * @param  array{teacher_id:int, classroom_id:?int, day_of_week:string, period_no:int}  $attributes
     * @return array{field: string, message: string}|null
     */
    public function conflictReason(array $attributes, ?int $exceptId = null): ?array
    {
        $teacherConflict = TimetableEntry::where('teacher_id', $attributes['teacher_id'])
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($teacherConflict) {
            return ['field' => 'teacher_id', 'message' => 'This teacher is already assigned to another section in this period.'];
        }

        if ($attributes['classroom_id'] !== null) {
            $classroomConflict = TimetableEntry::where('classroom_id', $attributes['classroom_id'])
                ->where('day_of_week', $attributes['day_of_week'])
                ->where('period_no', $attributes['period_no'])
                ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
                ->exists();

            if ($classroomConflict) {
                return ['field' => 'classroom_id', 'message' => 'This classroom is already booked for another section in this period.'];
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test tests/Unit/TimetableConflictCheckerTest.php --compact`
Expected: PASS, all assertions green.

- [ ] **Step 5: Wire the checker into `TimetableEntryController` and remove the old inline logic**

In `app/Http/Controllers/Api/V1/TimetableEntryController.php`:

Replace the import block (lines 9-14 and line 19) —

```php
use App\Models\Classroom;
use App\Models\Section;
use App\Models\Shift;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\User;
```

and

```php
use Illuminate\Support\Carbon;
```

— with:

```php
use App\Models\Section;
use App\Models\TimetableEntry;
use App\Support\TimetableConflictChecker;
```

Add a constructor just above `index()`:

```php
public function __construct(private TimetableConflictChecker $conflictChecker)
{
}
```

Replace `assertValid()` (including its docblock) and the now-separate
`assertWithinShiftHours()` private method entirely with:

```php
    /**
     * Configuration guards: the subject must have a valid teacher
     * assignment for this section (hard constraint #9), the day must be
     * one the section's shift actually runs, the period's time (if given)
     * must fall within the shift's hours and outside its breaks, the
     * weekly period cap for the subject must not be exceeded, and room
     * capacity must be sufficient. Delegates to TimetableConflictChecker,
     * shared with TimetableGenerator.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertValid(Section $section, array $attributes, ?int $exceptId = null): void
    {
        $error = $this->conflictChecker->invalidReason($section, $attributes, $exceptId);

        if ($error !== null) {
            throw ValidationException::withMessages([$error['field'] => [$error['message']]]);
        }
    }
```

Replace `assertNoConflicts()` with:

```php
    /**
     * Hard constraints: a teacher or a classroom can't be double-booked in
     * the same period on the same day. The section/day/period triplet
     * itself is enforced by a DB unique index instead (mirrors
     * ClassRoutineController's conflict checks). Delegates to
     * TimetableConflictChecker, shared with TimetableGenerator.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertNoConflicts(array $attributes, ?int $exceptId = null): void
    {
        $error = $this->conflictChecker->conflictReason($attributes, $exceptId);

        if ($error !== null) {
            throw ValidationException::withMessages([$error['field'] => [$error['message']]]);
        }
    }
```

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test --compact`
Expected: PASS, zero regressions (no existing test exercised the removed
private methods directly, but `RoutineAssignmentTimetableAuthorizationTest`'s
`test_timetable_entry_store_is_authorized` exercises `store()` end to end
and must still pass).

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/TimetableConflictChecker.php app/Http/Controllers/Api/V1/TimetableEntryController.php tests/Unit/TimetableConflictCheckerTest.php
git commit -m "Extract TimetableConflictChecker from TimetableEntryController"
```

---

### Task 2: `TimetableEntry::createOrRestore()`

**Files:**
- Modify: `app/Models/TimetableEntry.php:1-71` (add static method)
- Modify: `app/Http/Controllers/Api/V1/TimetableEntryController.php` (simplify `store()`)
- Test: `tests/Unit/TimetableEntryCreateOrRestoreTest.php`

**Interfaces:**
- Produces: `TimetableEntry::createOrRestore(array $attributes): TimetableEntry`
  — attributes need not include `status`/`published_at`/`published_by`;
  these are always forced to `'draft'`/`null`/`null` regardless of what is
  passed. Required keys: `section_id`, `subject_id`, `teacher_id`,
  `classroom_id`, `academic_year_id`, `school_id`, `district_id`,
  `day_of_week`, `period_no`; optional: `start_time`, `end_time`.
- Consumes: nothing new (uses the model's own existing table/scopes).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TimetableEntryCreateOrRestoreTest.php`:

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
use App\Models\TimetableEntry;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TimetableEntryCreateOrRestoreTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Subject $subject;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);
        $classVersion = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Version']);
        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section']);
        $this->subject = Subject::create(['name' => 'Math']);
        $this->teacher = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'classroom_id' => null,
            'academic_year_id' => null,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ];
    }

    public function test_creates_a_fresh_draft_when_no_trashed_row_occupies_the_slot(): void
    {
        $entry = TimetableEntry::createOrRestore($this->attributes());

        $this->assertDatabaseHas('timetable_entries', [
            'id' => $entry->id,
            'section_id' => $this->section->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);
    }

    public function test_restores_and_overwrites_a_trashed_row_at_the_same_slot(): void
    {
        $original = TimetableEntry::create([...$this->attributes(), 'status' => 'published', 'published_at' => now()]);
        $originalId = $original->id;
        $original->delete();

        // createOrRestore() is a raw persistence operation with no
        // business-rule checks of its own (those live in
        // TimetableConflictChecker, exercised separately) — no
        // SubjectTeacherAssignment is needed to prove it overwrites
        // teacher_id correctly.
        $otherTeacher = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $restored = TimetableEntry::createOrRestore([
            ...$this->attributes(),
            'teacher_id' => $otherTeacher->id,
        ]);

        $this->assertSame($originalId, $restored->id);
        $this->assertNull($restored->deleted_at);
        $this->assertSame('draft', $restored->status);
        $this->assertNull($restored->published_at);
        $this->assertNull($restored->published_by);
        $this->assertSame($otherTeacher->id, $restored->teacher_id);
    }

    public function test_store_restores_a_previously_soft_deleted_entry_at_the_same_slot(): void
    {
        $original = TimetableEntry::create([...$this->attributes(), 'status' => 'draft']);
        $originalId = $original->id;
        $original->delete();

        $allowed = $this->actorWithPermissions(['timetable_entries.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/timetable-entries', [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ]);

        $response->assertCreated();
        $this->assertSame($originalId, $response->json('data.id'));
        $this->assertDatabaseHas('timetable_entries', ['id' => $originalId, 'deleted_at' => null, 'status' => 'draft']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/TimetableEntryCreateOrRestoreTest.php --compact`
Expected: FAIL — `createOrRestore` does not exist (the third test may
currently pass, since `store()` already has this behavior inline — that's
fine, it becomes a regression guard).

- [ ] **Step 3: Add `createOrRestore()` to `app/Models/TimetableEntry.php`**

Add this public static method to the `TimetableEntry` class, after the
`publisher()` relation method:

```php
    /**
     * Create a new entry at (section_id, day_of_week, period_no), or — if
     * a soft-deleted row already occupies that slot (the DB unique index
     * on these three columns does not exclude trashed rows, so a plain
     * insert would violate it) — restore and overwrite that row instead.
     * Always lands as a fresh draft: status/published_at/published_by in
     * $attributes (if present) are ignored and forced to
     * 'draft'/null/null.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createOrRestore(array $attributes): self
    {
        $draftAttributes = [...$attributes, 'status' => 'draft', 'published_at' => null, 'published_by' => null];

        $entry = static::onlyTrashed()
            ->where('section_id', $attributes['section_id'])
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->first();

        if ($entry) {
            $entry->restore();
            $entry->update($draftAttributes);

            return $entry;
        }

        return static::create($draftAttributes);
    }
```

- [ ] **Step 4: Simplify `TimetableEntryController::store()` to use it**

In `app/Http/Controllers/Api/V1/TimetableEntryController.php`, replace the
block:

```php
        $entry = TimetableEntry::onlyTrashed()
            ->where('section_id', $section->id)
            ->where('day_of_week', $attributes['day_of_week'])
            ->where('period_no', $attributes['period_no'])
            ->first();

        if ($entry) {
            $entry->restore();
            $entry->update([...$attributes, 'status' => 'draft', 'published_at' => null, 'published_by' => null]);
        } else {
            $entry = TimetableEntry::create($attributes);
        }
```

with:

```php
        $entry = TimetableEntry::createOrRestore($attributes);
```

(The `'status' => 'draft'` entry already present in the `$attributes`
array built earlier in `store()` is now redundant but harmless — leave it
as-is, it keeps the array self-documenting.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/TimetableEntryCreateOrRestoreTest.php --compact`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, zero regressions.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/TimetableEntry.php app/Http/Controllers/Api/V1/TimetableEntryController.php tests/Unit/TimetableEntryCreateOrRestoreTest.php
git commit -m "Add TimetableEntry::createOrRestore(), reuse it in store()"
```

---

### Task 3: `TimetablePeriodGrid`

**Files:**
- Create: `app/Support/TimetablePeriodGrid.php`
- Test: `tests/Unit/TimetablePeriodGridTest.php`

**Interfaces:**
- Produces: `TimetablePeriodGrid::build(Shift $shift): array` — returns a
  list of `['day_of_week' => string, 'period_no' => int, 'start_time' =>
  string, 'end_time' => string]` rows (`H:i` formatted strings), one row
  per period per day in `$shift->days_of_week`. Returns `[]` if
  `$shift->period_duration_minutes` is `null` or `$shift->days_of_week` is
  empty.
- Consumes: nothing from other tasks (operates on a `Shift` instance
  directly, does not need to be persisted).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TimetablePeriodGridTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\Shift;
use App\Support\TimetablePeriodGrid;
use Tests\TestCase;

class TimetablePeriodGridTest extends TestCase
{
    private TimetablePeriodGrid $grid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->grid = new TimetablePeriodGrid();
    }

    public function test_builds_sequential_periods_with_no_breaks(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
        ]);

        $slots = $this->grid->build($shift);

        $this->assertCount(6, $slots);
        $this->assertSame(['day_of_week' => 'saturday', 'period_no' => 1, 'start_time' => '08:00', 'end_time' => '08:50'], $slots[0]);
        $this->assertSame(['day_of_week' => 'saturday', 'period_no' => 6, 'start_time' => '12:10', 'end_time' => '13:00'], $slots[5]);
    }

    public function test_period_numbers_continue_sequentially_across_a_break(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
            'breaks' => [['start_time' => '10:00', 'end_time' => '10:20', 'label' => 'Tiffin']],
        ]);

        $slots = $this->grid->build($shift);

        // [08:00,10:00) -> 2 full periods, 20min remainder dropped.
        // [10:20,13:00) -> 3 full periods, 10min remainder dropped.
        $this->assertCount(5, $slots);
        $this->assertSame([1, 2, 3, 4, 5], array_column($slots, 'period_no'));
        $this->assertSame('08:00', $slots[0]['start_time']);
        $this->assertSame('09:40', $slots[1]['end_time']);
        $this->assertSame('10:20', $slots[2]['start_time']);
        $this->assertSame('12:50', $slots[4]['end_time']);
    }

    public function test_trailing_remainder_shorter_than_one_period_is_dropped(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '08:49',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
        ]);

        $this->assertSame([], $this->grid->build($shift));
    }

    public function test_every_day_gets_its_own_full_grid(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '09:00',
            'days_of_week' => ['saturday', 'sunday'],
            'period_duration_minutes' => 30,
        ]);

        $slots = $this->grid->build($shift);

        $this->assertCount(4, $slots);
        $this->assertSame(['saturday', 'saturday', 'sunday', 'sunday'], array_column($slots, 'day_of_week'));
        $this->assertSame([1, 2, 1, 2], array_column($slots, 'period_no'));
    }

    public function test_returns_empty_when_period_duration_is_not_configured(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $this->assertSame([], $this->grid->build($shift));
    }

    public function test_returns_empty_when_no_days_are_configured(): void
    {
        $shift = new Shift([
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => [],
            'period_duration_minutes' => 50,
        ]);

        $this->assertSame([], $this->grid->build($shift));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/TimetablePeriodGridTest.php --compact`
Expected: FAIL — `App\Support\TimetablePeriodGrid` does not exist.

- [ ] **Step 3: Create `app/Support/TimetablePeriodGrid.php`**

```php
<?php

namespace App\Support;

use App\Models\Shift;
use Illuminate\Support\Carbon;

/**
 * Derives the weekly period grid a Shift implies from its configured
 * start/end time, period duration, and breaks. There is no stored
 * period-to-time mapping anywhere else in this codebase — a manual
 * TimetableEntry row carries its own start_time/end_time/period_no
 * supplied directly by the caller; TimetableGenerator needs this grid to
 * know what candidate slots even exist.
 */
class TimetablePeriodGrid
{
    /**
     * @return list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>
     */
    public function build(Shift $shift): array
    {
        if ($shift->period_duration_minutes === null || empty($shift->days_of_week)) {
            return [];
        }

        $freeWindows = $this->freeWindows($shift);
        $slots = [];

        foreach ($shift->days_of_week as $day) {
            $periodNo = 1;

            foreach ($freeWindows as [$windowStart, $windowEnd]) {
                $cursor = $windowStart->copy();

                while ($cursor->copy()->addMinutes($shift->period_duration_minutes)->lessThanOrEqualTo($windowEnd)) {
                    $periodEnd = $cursor->copy()->addMinutes($shift->period_duration_minutes);

                    $slots[] = [
                        'day_of_week' => $day,
                        'period_no' => $periodNo,
                        'start_time' => $cursor->format('H:i'),
                        'end_time' => $periodEnd->format('H:i'),
                    ];

                    $periodNo++;
                    $cursor = $periodEnd;
                }
            }
        }

        return $slots;
    }

    /**
     * The shift's [start_time, end_time) interval with every configured
     * break interval subtracted out, as a time-ordered list of
     * [start, end] Carbon pairs.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    private function freeWindows(Shift $shift): array
    {
        $shiftStart = Carbon::parse($shift->start_time->format('H:i'));
        $shiftEnd = Carbon::parse($shift->end_time->format('H:i'));

        $breaks = collect($shift->breaks ?? [])
            ->map(fn (array $break): array => [Carbon::parse($break['start_time']), Carbon::parse($break['end_time'])])
            ->sortBy(fn (array $break) => $break[0]->format('H:i'))
            ->values()
            ->all();

        $windows = [];
        $cursor = $shiftStart;

        foreach ($breaks as [$breakStart, $breakEnd]) {
            if ($breakStart->greaterThan($cursor)) {
                $windows[] = [$cursor, $breakStart->lessThan($shiftEnd) ? $breakStart : $shiftEnd];
            }

            if ($breakEnd->greaterThan($cursor)) {
                $cursor = $breakEnd;
            }
        }

        if ($cursor->lessThan($shiftEnd)) {
            $windows[] = [$cursor, $shiftEnd];
        }

        return $windows;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/TimetablePeriodGridTest.php --compact`
Expected: PASS.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, zero regressions.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/TimetablePeriodGrid.php tests/Unit/TimetablePeriodGridTest.php
git commit -m "Add TimetablePeriodGrid to derive a Shift's period slots"
```

---

### Task 4: `timetable_entries.generate` permission and policy

**Files:**
- Modify: `database/seeders/PermissionSeeder.php:148-152`
- Modify: `database/seeders/RolePermissionSeeder.php:51-52`
- Modify: `app/Policies/TimetableEntryPolicy.php:1-43`
- Modify: `tests/Feature/DatabaseSeederTest.php:23-30`

**Interfaces:**
- Produces: permission `timetable_entries.generate`, seeded to
  `school_admin` (plus `national_admin` via its all-permissions
  wildcard); `TimetableEntryPolicy::generate(User $user): bool`.
- Consumes: nothing new.

- [ ] **Step 1: Write the failing test assertion**

In `tests/Feature/DatabaseSeederTest.php`, add a line after the existing
`student_progress.view` assertion (inside
`test_full_seed_chain_completes_without_error()`):

```php
        $this->assertTrue(Permission::where('name', 'timetable_entries.generate')->exists());
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php --compact`
Expected: FAIL — no `timetable_entries.generate` permission row.

- [ ] **Step 3: Add the permission to `PermissionSeeder`**

In `database/seeders/PermissionSeeder.php`, after the line
`['name' => 'timetable_entries.publish', 'label' => 'Publish or revise published timetable entries'],`
add:

```php
            ['name' => 'timetable_entries.generate', 'label' => 'Automatically generate timetable entries for a shift'],
```

- [ ] **Step 4: Seed it to `school_admin` in `RolePermissionSeeder`**

In `database/seeders/RolePermissionSeeder.php`, change:

```php
                'timetable_entries.view', 'timetable_entries.create', 'timetable_entries.update',
                'timetable_entries.delete', 'timetable_entries.publish',
```

to:

```php
                'timetable_entries.view', 'timetable_entries.create', 'timetable_entries.update',
                'timetable_entries.delete', 'timetable_entries.publish', 'timetable_entries.generate',
```

- [ ] **Step 5: Add the policy ability**

In `app/Policies/TimetableEntryPolicy.php`, add after `publish()`:

```php
    /**
     * A no-instance ability (same convention as ClassVersionPolicy's
     * updateSubjects()/ChapterVideoPolicy's reorder()): generating
     * replaces entries across every section in a shift at once, gated
     * independently of create() — it is at least as sensitive.
     */
    public function generate(User $user): bool
    {
        return $user->hasPermission('timetable_entries.generate');
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test tests/Feature/DatabaseSeederTest.php --compact`
Expected: PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, zero regressions.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/seeders/PermissionSeeder.php database/seeders/RolePermissionSeeder.php app/Policies/TimetableEntryPolicy.php tests/Feature/DatabaseSeederTest.php
git commit -m "Add timetable_entries.generate permission and policy ability"
```

---

### Task 5: `TimetableGenerator`

**Files:**
- Create: `app/Support/TimetableGenerator.php`
- Test: `tests/Unit/TimetableGeneratorTest.php`

**Interfaces:**
- Consumes: `TimetableConflictChecker::invalidReason()`/`conflictReason()`
  (Task 1), `TimetablePeriodGrid::build()` (Task 3),
  `TimetableEntry::createOrRestore()` (Task 2).
- Produces:
  `TimetableGenerator::generate(Shift $shift): array` returning
  `['generated' => list<array{section_id: int, entries_created: int}>,
  'failed' => list<array{section_id: int, shortfalls: list<array{subject_id: int, subject_name: string, required: int, placed: int, reason: string}>}>]`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/TimetableGeneratorTest.php`:

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
use App\Models\TimetableEntry;
use App\Models\Upazila;
use App\Models\User;
use App\Support\TimetableConflictChecker;
use App\Support\TimetableGenerator;
use App\Support\TimetablePeriodGrid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimetableGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private TimetableGenerator $generator;

    private School $school;

    private ClassVersion $classVersion;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = new TimetableGenerator(new TimetableConflictChecker(), new TimetablePeriodGrid());

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);

        $this->classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $this->shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday'],
            'period_duration_minutes' => 50,
        ]);
    }

    private function makeSection(string $name): Section
    {
        return Section::create([
            'shift_id' => $this->shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => $name,
        ]);
    }

    private function makeSubject(string $name, int $requiredWeeklyPeriods): Subject
    {
        $subject = Subject::create(['name' => $name]);
        $this->classVersion->subjects()->attach($subject->id, ['required_weekly_periods' => $requiredWeeklyPeriods]);

        return $subject;
    }

    private function makeTeacher(): User
    {
        return User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
    }

    private function assign(Section $section, Subject $subject, User $teacher): void
    {
        SubjectTeacherAssignment::create([
            'section_id' => $section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);
    }

    public function test_generates_a_full_conflict_free_schedule_for_two_independent_sections(): void
    {
        $sectionA = $this->makeSection('A');
        $sectionB = $this->makeSection('B');
        $math = $this->makeSubject('Math', 3);
        $teacherA = $this->makeTeacher();
        $teacherB = $this->makeTeacher();
        $this->assign($sectionA, $math, $teacherA);
        $this->assign($sectionB, $math, $teacherB);

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);
        $this->assertCount(2, $result['generated']);
        $this->assertSame(3, TimetableEntry::where('section_id', $sectionA->id)->count());
        $this->assertSame(3, TimetableEntry::where('section_id', $sectionB->id)->count());
    }

    public function test_reports_a_shortfall_when_a_shared_teacher_cannot_cover_both_sections_without_double_booking(): void
    {
        // 5 days x 1 period/day of usable grid per section if they shared
        // every slot; one teacher assigned to both sections for a subject
        // that needs 3 periods each is only 6 total bookings but the
        // teacher can only be in one place per (day, period) — this shift
        // has 5 days x 6 periods = 30 slots, more than enough to NOT
        // conflict, so shrink the grid to one period per day to force a
        // real shortfall.
        $tightShift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Tight Shift',
            'start_time' => '08:00',
            'end_time' => '08:50',
            'days_of_week' => ['saturday', 'sunday'],
            'period_duration_minutes' => 50,
        ]);

        $sectionA = Section::create(['shift_id' => $tightShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'A']);
        $sectionB = Section::create(['shift_id' => $tightShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'B']);
        $math = $this->makeSubject('Math', 2);
        $teacher = $this->makeTeacher();
        $this->assign($sectionA, $math, $teacher);
        $this->assign($sectionB, $math, $teacher);

        $result = $this->generator->generate($tightShift);

        // Exactly 2 slots exist in this grid total; one section can take
        // both, the other gets none of its 2 required periods.
        $this->assertCount(1, $result['generated']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(0, $result['failed'][0]['shortfalls'][0]['placed']);
        $this->assertSame(2, $result['failed'][0]['shortfalls'][0]['required']);

        // No teacher double-booking exists anywhere as a result.
        $bookings = TimetableEntry::where('teacher_id', $teacher->id)->get(['day_of_week', 'period_no']);
        $this->assertSame($bookings->count(), $bookings->unique(fn ($b) => $b->day_of_week.'-'.$b->period_no)->count());
    }

    public function test_published_entries_are_never_altered_or_deleted(): void
    {
        $section = $this->makeSection('A');
        $math = $this->makeSubject('Math', 1);
        $teacher = $this->makeTeacher();
        $this->assign($section, $math, $teacher);

        $published = TimetableEntry::create([
            'section_id' => $section->id,
            'subject_id' => $math->id,
            'teacher_id' => $teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'published',
            'published_at' => now(),
        ]);

        $this->generator->generate($this->shift);

        $this->assertDatabaseHas('timetable_entries', [
            'id' => $published->id,
            'status' => 'published',
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ]);
    }

    public function test_preexisting_draft_entries_are_replaced_on_success(): void
    {
        $section = $this->makeSection('A');
        $math = $this->makeSubject('Math', 1);
        $teacher = $this->makeTeacher();
        $this->assign($section, $math, $teacher);

        $staleDraft = TimetableEntry::create([
            'section_id' => $section->id,
            'subject_id' => $math->id,
            'teacher_id' => $teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'tuesday',
            'period_no' => 5,
            'status' => 'draft',
        ]);

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);
        $this->assertDatabaseMissing('timetable_entries', ['id' => $staleDraft->id, 'deleted_at' => null]);
    }

    public function test_preexisting_draft_entries_are_restored_on_failure(): void
    {
        $tightShift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Tight Shift 2',
            'start_time' => '08:00',
            'end_time' => '08:50',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
        ]);

        $section = Section::create(['shift_id' => $tightShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'A']);
        $math = $this->makeSubject('Math', 2);
        $teacher = $this->makeTeacher();
        $this->assign($section, $math, $teacher);

        $staleDraft = TimetableEntry::create([
            'section_id' => $section->id,
            'subject_id' => $math->id,
            'teacher_id' => $teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);

        $result = $this->generator->generate($tightShift);

        $this->assertCount(1, $result['failed']);
        $this->assertDatabaseHas('timetable_entries', [
            'id' => $staleDraft->id,
            'deleted_at' => null,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);
    }

    public function test_subject_with_null_required_weekly_periods_is_skipped_not_reported(): void
    {
        $section = $this->makeSection('A');
        $subject = Subject::create(['name' => 'Elective']);
        $this->classVersion->subjects()->attach($subject->id, ['required_weekly_periods' => null]);
        $teacher = $this->makeTeacher();
        $this->assign($section, $subject, $teacher);

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);
        $this->assertSame(0, TimetableEntry::where('section_id', $section->id)->count());
    }

    public function test_section_with_no_assigned_subjects_succeeds_with_zero_entries(): void
    {
        $this->makeSection('A');

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);
        $this->assertSame(0, $result['generated'][0]['entries_created']);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Unit/TimetableGeneratorTest.php --compact`
Expected: FAIL — `App\Support\TimetableGenerator` does not exist.

- [ ] **Step 3: Create `app/Support/TimetableGenerator.php`**

```php
<?php

namespace App\Support;

use App\Models\Section;
use App\Models\Shift;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use Illuminate\Support\Facades\DB;

/**
 * Fills every section under a Shift with conflict-free TimetableEntry
 * drafts for each assigned subject's full required_weekly_periods —
 * Phase 5.2 of the timetable-management requirements doc. See
 * docs/superpowers/specs/2026-11-05-automatic-timetable-generation-design.md
 * for the algorithm this implements: most-constrained-subject-first
 * greedy placement using TimetableConflictChecker for every hard
 * constraint, per-section all-or-nothing, then a bounded local-search
 * pass to improve soft constraints.
 */
class TimetableGenerator
{
    private const MAX_LOCAL_SEARCH_ITERATIONS = 50;

    public function __construct(
        private TimetableConflictChecker $conflictChecker,
        private TimetablePeriodGrid $periodGrid,
    ) {
    }

    /**
     * @return array{generated: list<array{section_id: int, entries_created: int}>, failed: list<array{section_id: int, shortfalls: list<array{subject_id: int, subject_name: string, required: int, placed: int, reason: string}>}>}
     */
    public function generate(Shift $shift): array
    {
        $grid = $this->periodGrid->build($shift);
        $generated = [];
        $failed = [];

        // Deterministic order: sections are processed id-ascending (not
        // guaranteed by the bare relation), since the first section
        // processed gets first pick of any shared teacher's open slots —
        // this is the one behavior-affecting ordering choice the spec
        // leaves to the implementation.
        foreach ($shift->sections()->orderBy('id')->get() as $section) {
            $result = $this->generateForSection($section, $grid);

            if ($result['shortfalls'] === []) {
                $generated[] = ['section_id' => $section->id, 'entries_created' => $result['entries_created']];
            } else {
                $failed[] = ['section_id' => $section->id, 'shortfalls' => $result['shortfalls']];
            }
        }

        return ['generated' => $generated, 'failed' => $failed];
    }

    /**
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @return array{entries_created: int, shortfalls: list<array{subject_id: int, subject_name: string, required: int, placed: int, reason: string}>}
     */
    private function generateForSection(Section $section, array $grid): array
    {
        DB::beginTransaction();

        TimetableEntry::where('section_id', $section->id)->where('status', 'draft')->delete();

        $entriesCreated = 0;
        $shortfalls = [];

        foreach ($this->orderSubjectsByConstraint($section, $grid) as [$subject, $assignment, $required]) {
            $result = $this->placeSubject($section, $subject, $assignment, $required, $grid);
            $entriesCreated += count($result['placed']);

            if ($result['shortfall'] !== null) {
                $shortfalls[] = $result['shortfall'];
            }
        }

        if ($shortfalls !== []) {
            DB::rollBack();

            return ['entries_created' => 0, 'shortfalls' => $shortfalls];
        }

        $this->runLocalSearch($section);
        DB::commit();

        return ['entries_created' => $entriesCreated, 'shortfalls' => []];
    }

    /**
     * Most-constrained-subject-first: a subject with fewer currently
     * conflict-free candidate slots for its assigned teacher is placed
     * before one with many, so it never loses its only viable slots to a
     * less-constrained subject placed earlier. A subject with no teacher
     * assignment at all sorts first (it has zero options) and is reported
     * as an immediate full shortfall.
     *
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @return list<array{0: Subject, 1: ?SubjectTeacherAssignment, 2: int}>
     */
    private function orderSubjectsByConstraint(Section $section, array $grid): array
    {
        $classVersion = $section->shift->classVersion;
        $rows = [];

        foreach ($classVersion->subjects as $subject) {
            $required = $subject->pivot->required_weekly_periods;

            if ($required === null) {
                continue;
            }

            $assignment = SubjectTeacherAssignment::where('section_id', $section->id)
                ->where('subject_id', $subject->id)
                ->first();

            if (! $assignment) {
                $rows[] = [$subject, null, $required, 0];

                continue;
            }

            $availableCount = 0;

            foreach ($grid as $candidate) {
                $conflict = $this->conflictChecker->conflictReason([
                    'teacher_id' => $assignment->teacher_id,
                    'classroom_id' => $section->default_classroom_id,
                    'day_of_week' => $candidate['day_of_week'],
                    'period_no' => $candidate['period_no'],
                ]);

                if ($conflict === null) {
                    $availableCount++;
                }
            }

            $rows[] = [$subject, $assignment, $required, $availableCount];
        }

        usort($rows, fn (array $a, array $b): int => $a[3] <=> $b[3]);

        return array_map(fn (array $row): array => [$row[0], $row[1], $row[2]], $rows);
    }

    /**
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @return array{placed: list<array{day_of_week: string, period_no: int}>, shortfall: ?array{subject_id: int, subject_name: string, required: int, placed: int, reason: string}}
     */
    private function placeSubject(Section $section, Subject $subject, ?SubjectTeacherAssignment $assignment, int $required, array $grid): array
    {
        if ($assignment === null) {
            return [
                'placed' => [],
                'shortfall' => [
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'required' => $required,
                    'placed' => 0,
                    'reason' => 'No teacher is assigned to this subject for this section.',
                ],
            ];
        }

        $placedSlots = [];
        $lastReason = 'This shift has no remaining period that fits this subject\'s constraints.';

        for ($i = 0; $i < $required; $i++) {
            $outcome = $this->bestCandidate($section, $subject, $assignment, $grid, $placedSlots, $lastReason);

            if ($outcome['candidate'] === null) {
                $lastReason = $outcome['reason'];

                break;
            }

            $candidate = $outcome['candidate'];

            TimetableEntry::createOrRestore([
                'subject_id' => $subject->id,
                'teacher_id' => $assignment->teacher_id,
                'classroom_id' => $section->default_classroom_id,
                'day_of_week' => $candidate['day_of_week'],
                'period_no' => $candidate['period_no'],
                'start_time' => $candidate['start_time'],
                'end_time' => $candidate['end_time'],
                'section_id' => $section->id,
                'academic_year_id' => $section->shift->academic_year_id,
                'school_id' => $section->school_id,
                'district_id' => $section->district_id,
            ]);

            $placedSlots[] = ['day_of_week' => $candidate['day_of_week'], 'period_no' => $candidate['period_no']];
        }

        if (count($placedSlots) === $required) {
            return ['placed' => $placedSlots, 'shortfall' => null];
        }

        return [
            'placed' => $placedSlots,
            'shortfall' => [
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'required' => $required,
                'placed' => count($placedSlots),
                'reason' => $lastReason,
            ],
        ];
    }

    /**
     * Scans the grid in three preference tiers — a day this subject
     * hasn't used yet, then a day that wouldn't create 3+ consecutive
     * periods for it, then anything left — returning the first
     * hard-constraint-valid candidate found, or null with the last
     * rejection reason encountered if none exists.
     *
     * @param  list<array{day_of_week: string, period_no: int, start_time: string, end_time: string}>  $grid
     * @param  list<array{day_of_week: string, period_no: int}>  $alreadyPlaced
     * @return array{candidate: ?array{day_of_week: string, period_no: int, start_time: string, end_time: string}, reason: string}
     */
    private function bestCandidate(Section $section, Subject $subject, SubjectTeacherAssignment $assignment, array $grid, array $alreadyPlaced, string $fallbackReason): array
    {
        $usedDays = array_unique(array_column($alreadyPlaced, 'day_of_week'));

        $tiers = [
            fn (array $c): bool => ! in_array($c['day_of_week'], $usedDays, true),
            fn (array $c): bool => ! $this->wouldCreateTripleConsecutive($c, $alreadyPlaced),
            fn (array $c): bool => true,
        ];

        $lastReason = $fallbackReason;

        foreach ($tiers as $tierFilter) {
            foreach ($grid as $candidateSlot) {
                $alreadyHere = array_filter(
                    $alreadyPlaced,
                    fn (array $p): bool => $p['day_of_week'] === $candidateSlot['day_of_week'] && $p['period_no'] === $candidateSlot['period_no']
                );

                if ($alreadyHere !== []) {
                    continue;
                }

                if (! $tierFilter($candidateSlot)) {
                    continue;
                }

                $attributes = [
                    'subject_id' => $subject->id,
                    'teacher_id' => $assignment->teacher_id,
                    'classroom_id' => $section->default_classroom_id,
                    'day_of_week' => $candidateSlot['day_of_week'],
                    'period_no' => $candidateSlot['period_no'],
                    'start_time' => $candidateSlot['start_time'],
                    'end_time' => $candidateSlot['end_time'],
                ];

                $error = $this->conflictChecker->invalidReason($section, $attributes)
                    ?? $this->conflictChecker->conflictReason($attributes);

                if ($error === null) {
                    return ['candidate' => $candidateSlot, 'reason' => ''];
                }

                $lastReason = $error['message'];
            }
        }

        return ['candidate' => null, 'reason' => $lastReason];
    }

    /**
     * @param  array{day_of_week: string, period_no: int}  $candidateSlot
     * @param  list<array{day_of_week: string, period_no: int}>  $alreadyPlaced
     */
    private function wouldCreateTripleConsecutive(array $candidateSlot, array $alreadyPlaced): bool
    {
        $sameDayPeriods = array_values(array_map(
            fn (array $p): int => $p['period_no'],
            array_filter($alreadyPlaced, fn (array $p): bool => $p['day_of_week'] === $candidateSlot['day_of_week'])
        ));
        $sameDayPeriods[] = $candidateSlot['period_no'];
        sort($sameDayPeriods);

        $consecutive = 1;
        $max = 1;

        for ($i = 1; $i < count($sameDayPeriods); $i++) {
            if ($sameDayPeriods[$i] === $sameDayPeriods[$i - 1] + 1) {
                $consecutive++;
                $max = max($max, $consecutive);
            } else {
                $consecutive = 1;
            }
        }

        return $max >= 3;
    }

    /**
     * Bounded best-effort polish: repeatedly try swapping two of this
     * section's placed entries (different subjects only — swapping
     * identical-subject slots can never change the score) and keep the
     * swap only if both resulting placements are still hard-constraint
     * valid AND it reduces the soft-constraint score. This check is
     * deliberately conservative: it excepts only the entry being moved,
     * not its swap partner, from the conflict query, so a same-teacher-
     * or same-classroom coincidence between the two can cause a valid
     * swap to be rejected as a false conflict. That is a safe failure —
     * it only means fewer swaps happen, never an invalid final state.
     */
    private function runLocalSearch(Section $section): void
    {
        for ($i = 0; $i < self::MAX_LOCAL_SEARCH_ITERATIONS; $i++) {
            $entries = TimetableEntry::where('section_id', $section->id)->where('status', 'draft')->get();

            if ($entries->count() < 2) {
                return;
            }

            [$a, $b] = $entries->random(2)->values()->all();

            if ($a->subject_id === $b->subject_id) {
                continue;
            }

            $scoreBefore = $this->softScore($entries);

            $aOriginal = ['day_of_week' => $a->day_of_week, 'period_no' => $a->period_no, 'start_time' => $a->start_time, 'end_time' => $a->end_time];
            $bOriginal = ['day_of_week' => $b->day_of_week, 'period_no' => $b->period_no, 'start_time' => $b->start_time, 'end_time' => $b->end_time];

            $aBaseAttributes = ['subject_id' => $a->subject_id, 'teacher_id' => $a->teacher_id, 'classroom_id' => $a->classroom_id, 'section_id' => $a->section_id, 'academic_year_id' => $a->academic_year_id, 'school_id' => $a->school_id, 'district_id' => $a->district_id];
            $bBaseAttributes = ['subject_id' => $b->subject_id, 'teacher_id' => $b->teacher_id, 'classroom_id' => $b->classroom_id, 'section_id' => $b->section_id, 'academic_year_id' => $b->academic_year_id, 'school_id' => $b->school_id, 'district_id' => $b->district_id];

            $bSlotAsStrings = ['day_of_week' => $b->day_of_week, 'period_no' => $b->period_no, 'start_time' => $b->start_time?->format('H:i'), 'end_time' => $b->end_time?->format('H:i')];
            $aSlotAsStrings = ['day_of_week' => $a->day_of_week, 'period_no' => $a->period_no, 'start_time' => $a->start_time?->format('H:i'), 'end_time' => $a->end_time?->format('H:i')];

            $aSwapped = [...$aBaseAttributes, ...$bSlotAsStrings];
            $bSwapped = [...$bBaseAttributes, ...$aSlotAsStrings];

            $aValid = ($this->conflictChecker->invalidReason($section, $aSwapped, $a->id) ?? $this->conflictChecker->conflictReason($aSwapped, $a->id)) === null;
            $bValid = ($this->conflictChecker->invalidReason($section, $bSwapped, $b->id) ?? $this->conflictChecker->conflictReason($bSwapped, $b->id)) === null;

            if (! $aValid || ! $bValid) {
                continue;
            }

            $a->forceDelete();
            $b->forceDelete();

            TimetableEntry::createOrRestore($aSwapped);
            TimetableEntry::createOrRestore($bSwapped);

            $scoreAfter = $this->softScore(TimetableEntry::where('section_id', $section->id)->where('status', 'draft')->get());

            if ($scoreAfter >= $scoreBefore) {
                TimetableEntry::where('section_id', $section->id)
                    ->where('day_of_week', $aSwapped['day_of_week'])->where('period_no', $aSwapped['period_no'])
                    ->forceDelete();
                TimetableEntry::where('section_id', $section->id)
                    ->where('day_of_week', $bSwapped['day_of_week'])->where('period_no', $bSwapped['period_no'])
                    ->forceDelete();

                TimetableEntry::createOrRestore([...$aBaseAttributes, ...$aOriginal]);
                TimetableEntry::createOrRestore([...$bBaseAttributes, ...$bOriginal]);
            }
        }
    }

    /**
     * Lower is better: count of same-subject slots beyond the first on
     * the same day, summed across every subject — a crude proxy for
     * "spread this subject across the week."
     *
     * @param  \Illuminate\Support\Collection<int, TimetableEntry>  $entries
     */
    private function softScore($entries): int
    {
        return $entries
            ->groupBy(fn (TimetableEntry $e): string => $e->subject_id.'-'.$e->day_of_week)
            ->sum(fn ($group): int => max(0, $group->count() - 1));
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test tests/Unit/TimetableGeneratorTest.php --compact`
Expected: PASS. If a test is flaky due to the randomized local search
(`$entries->random(2)`), re-run once — the local search only ever keeps an
improving, hard-constraint-valid swap, so it cannot turn a passing
assertion about `generated`/`failed`/counts into a failing one; only a
soft-constraint-quality assertion (none are written above) could be
sensitive to it.

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, zero regressions.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Support/TimetableGenerator.php tests/Unit/TimetableGeneratorTest.php
git commit -m "Add TimetableGenerator: greedy placement + bounded local search"
```

---

### Task 6: `generate` controller action, route, and feature tests

**Files:**
- Modify: `app/Http/Controllers/Api/V1/TimetableEntryController.php`
- Modify: `routes/api.php`
- Modify: `tests/Unit/ControllerAuthorizationConventionTest.php`
- Test: `tests/Feature/TimetableEntryGenerateControllerTest.php`

**Interfaces:**
- Consumes: `TimetableGenerator::generate()` (Task 5),
  `TimetableEntryPolicy::generate()` (Task 4).
- Produces: `POST /shifts/{shift}/timetable-entries/generate`.

- [ ] **Step 1: Write the failing feature tests**

Create `tests/Feature/TimetableEntryGenerateControllerTest.php`:

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
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TimetableEntry;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TimetableEntryGenerateControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private ClassVersion $classVersion;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School A',
        ]);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);

        $this->classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $this->shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday', 'sunday', 'monday'],
            'period_duration_minutes' => 50,
        ]);
    }

    public function test_generate_is_forbidden_without_the_permission(): void
    {
        $denied = $this->actorWithNoPermissions($this->school->id, $this->school->district_id);

        $this->actingAs($denied, 'sanctum')
            ->postJson("/api/v1/shifts/{$this->shift->id}/timetable-entries/generate")
            ->assertForbidden();
    }

    public function test_generate_succeeds_and_matches_the_response_shape(): void
    {
        $section = Section::create(['shift_id' => $this->shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'A']);
        $subject = Subject::create(['name' => 'Math']);
        $this->classVersion->subjects()->attach($subject->id, ['required_weekly_periods' => 2]);
        $teacher = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        SubjectTeacherAssignment::create([
            'section_id' => $section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);

        $allowed = $this->actorWithPermissions(['timetable_entries.generate'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/shifts/{$this->shift->id}/timetable-entries/generate");

        $response->assertOk();
        $response->assertJson([
            'data' => [
                'generated' => [['section_id' => $section->id, 'entries_created' => 2]],
                'failed' => [],
            ],
        ]);
        $this->assertSame(2, TimetableEntry::where('section_id', $section->id)->count());
    }

    public function test_a_mixed_result_reports_both_a_success_and_a_failure_in_one_call(): void
    {
        $tightShift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Tight Shift',
            'start_time' => '08:00',
            'end_time' => '08:50',
            'days_of_week' => ['saturday'],
            'period_duration_minutes' => 50,
        ]);

        $sectionA = Section::create(['shift_id' => $tightShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'A']);
        $sectionB = Section::create(['shift_id' => $tightShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'B']);
        $subject = Subject::create(['name' => 'Math']);
        $this->classVersion->subjects()->attach($subject->id, ['required_weekly_periods' => 1]);
        $teacher = User::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        SubjectTeacherAssignment::create([
            'section_id' => $sectionA->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);
        SubjectTeacherAssignment::create([
            'section_id' => $sectionB->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
        ]);

        $allowed = $this->actorWithPermissions(['timetable_entries.generate'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($allowed, 'sanctum')
            ->postJson("/api/v1/shifts/{$tightShift->id}/timetable-entries/generate");

        $response->assertOk();
        $this->assertCount(1, $response->json('data.generated'));
        $this->assertCount(1, $response->json('data.failed'));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test tests/Feature/TimetableEntryGenerateControllerTest.php --compact`
Expected: FAIL — route does not exist (404).

- [ ] **Step 3: Add the controller action**

In `app/Http/Controllers/Api/V1/TimetableEntryController.php`, add the
`App\Models\Shift` and `App\Support\TimetableGenerator` imports, and add
this public method after `unpublish()`:

```php
    /**
     * Fill every section under this shift with conflict-free draft
     * entries for each assigned subject's full required_weekly_periods.
     * Published entries are never touched; a section's own pre-existing
     * draft entries are replaced on success and left untouched on
     * failure (TimetableGenerator handles both per-section, inside its
     * own transaction).
     */
    public function generate(Shift $shift, TimetableGenerator $generator): JsonResponse
    {
        $this->authorize('generate', TimetableEntry::class);

        return response()->json(['data' => $generator->generate($shift)]);
    }
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, inside the `auth:sanctum` group, after the
`unpublish` route line, add:

```php
        Route::post('/shifts/{shift}/timetable-entries/generate', [TimetableEntryController::class, 'generate']);
```

- [ ] **Step 5: Add the authorization-convention row**

In `tests/Unit/ControllerAuthorizationConventionTest.php`, add to the
`mutatingActions()` array, inside the `TimetableEntryController` group:

```php
            [TimetableEntryController::class, 'generate'],
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test tests/Feature/TimetableEntryGenerateControllerTest.php tests/Unit/ControllerAuthorizationConventionTest.php --compact`
Expected: PASS.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, zero regressions.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/Api/V1/TimetableEntryController.php routes/api.php tests/Unit/ControllerAuthorizationConventionTest.php tests/Feature/TimetableEntryGenerateControllerTest.php
git commit -m "Add POST /shifts/{shift}/timetable-entries/generate endpoint"
```
