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

        $this->generator = new TimetableGenerator(new TimetableConflictChecker, new TimetablePeriodGrid);

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

    public function test_places_two_subjects_in_one_section_without_double_booking_any_slot(): void
    {
        // Two subjects in the same section, different teachers each, and
        // no section default_classroom_id — this is the exact
        // configuration that previously crashed the generator with an
        // uncaught unique-constraint QueryException: bestCandidate() only
        // checked $alreadyPlaced (reset per subject), so once Math's
        // periods were placed, Science's placement attempt could not see
        // them as occupied and would pick an already-taken
        // (section_id, day_of_week, period_no) slot.
        $section = $this->makeSection('A');
        $math = $this->makeSubject('Math', 3);
        $science = $this->makeSubject('Science', 2);
        $mathTeacher = $this->makeTeacher();
        $scienceTeacher = $this->makeTeacher();
        $this->assign($section, $math, $mathTeacher);
        $this->assign($section, $science, $scienceTeacher);

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);
        $this->assertContains($section->id, array_column($result['generated'], 'section_id'));

        $entries = TimetableEntry::where('section_id', $section->id)->get(['day_of_week', 'period_no']);
        $this->assertSame(5, $entries->count());

        // No two of this section's entries share the same (day_of_week,
        // period_no) — proving the occupancy fix actually works, not
        // just that generation didn't crash.
        $this->assertSame(
            $entries->count(),
            $entries->unique(fn ($e) => $e->day_of_week.'-'.$e->period_no)->count()
        );
    }

    public function test_reports_a_shortfall_when_a_shared_teacher_cannot_cover_both_sections_without_double_booking(): void
    {
        // One teacher is assigned to both sections for a subject that
        // needs 2 periods each (4 total bookings), but the teacher can
        // only be in one place per (day, period). A normal shift would
        // have plenty of slots to avoid conflict, so this test uses a
        // tight 2-day x 1-period shift (2 total slots) to force a real
        // shortfall: whichever section is processed first takes both
        // slots, leaving the other section with none of its 2 required
        // periods.
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

        // A second subject, still needing fresh placement, so this test
        // actually exercises a *successful* generation that has to route
        // around the already-fully-published subject above, rather than
        // the generator doing nothing for this section at all.
        $science = $this->makeSubject('Science', 2);
        $scienceTeacher = $this->makeTeacher();
        $this->assign($section, $science, $scienceTeacher);

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

        $result = $this->generator->generate($this->shift);

        $this->assertDatabaseHas('timetable_entries', [
            'id' => $published->id,
            'status' => 'published',
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'deleted_at' => null,
        ]);

        // No other subject's entry landed on the published row's exact
        // slot — proving the generator actually routed around it rather
        // than, say, happening to leave it alone while also double-booking
        // the same slot for Science.
        $this->assertDatabaseMissing('timetable_entries', [
            'section_id' => $section->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'subject_id' => $science->id,
        ]);

        $this->assertSame([], $result['failed']);
        $this->assertContains($section->id, array_column($result['generated'], 'section_id'));
        $this->assertSame(2, TimetableEntry::where('section_id', $section->id)->where('subject_id', $science->id)->count());
    }

    public function test_preexisting_draft_entries_are_replaced_on_success(): void
    {
        $section = $this->makeSection('A');
        $math = $this->makeSubject('Math', 1);
        $teacher = $this->makeTeacher();
        $this->assign($section, $math, $teacher);

        // Placed at (saturday, 1) — the exact slot the generator's greedy
        // placement picks first for a single-subject, single-period
        // section — so the generator's createOrRestore() restore branch
        // is actually exercised, not just a delete-and-recreate-elsewhere.
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

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);

        // createOrRestore() restores this exact row (same id) at the same
        // slot rather than soft-deleting it and inserting a new row.
        $this->assertDatabaseHas('timetable_entries', [
            'id' => $staleDraft->id,
            'deleted_at' => null,
            'day_of_week' => 'saturday',
            'period_no' => 1,
            'status' => 'draft',
        ]);
        $this->assertSame(1, TimetableEntry::where('section_id', $section->id)->count());
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

    public function test_generates_entries_with_a_section_default_classroom_set(): void
    {
        // Every other test in this file leaves default_classroom_id null,
        // so TimetableConflictChecker's classroom-double-booking and
        // classroom-capacity branches were never exercised through
        // TimetableGenerator at all — including this exact configuration
        // (two subjects, shared default classroom) that caused the
        // local-search swap bug a prior fix round corrected.
        $classroom = Classroom::create([
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Room 1',
            'capacity' => 100,
        ]);

        $section = Section::create([
            'shift_id' => $this->shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'A',
            'default_classroom_id' => $classroom->id,
        ]);

        $math = $this->makeSubject('Math', 3);
        $science = $this->makeSubject('Science', 2);
        $mathTeacher = $this->makeTeacher();
        $scienceTeacher = $this->makeTeacher();
        $this->assign($section, $math, $mathTeacher);
        $this->assign($section, $science, $scienceTeacher);

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);
        $this->assertContains($section->id, array_column($result['generated'], 'section_id'));

        $entries = TimetableEntry::where('section_id', $section->id)->get(['day_of_week', 'period_no', 'classroom_id']);
        $this->assertSame(5, $entries->count());

        foreach ($entries as $entry) {
            $this->assertSame($classroom->id, $entry->classroom_id);
        }

        // No two of this section's entries share the same (day_of_week,
        // period_no), proving the classroom-conflict code path was
        // genuinely exercised (not merely skipped) while still placing a
        // correct, conflict-free schedule.
        $this->assertSame(
            $entries->count(),
            $entries->unique(fn ($e) => $e->day_of_week.'-'.$e->period_no)->count()
        );
    }

    public function test_runs_local_search_without_error_when_required_periods_exceed_available_days(): void
    {
        // required_weekly_periods (7) > count(days_of_week) (5) forces
        // greedy placement to double up on at least one day, so the
        // initial softScore() is > 0 — the only configuration where
        // runLocalSearch() actually enters its loop body at all (a score
        // of 0, the normal case, now returns immediately per the "skip
        // local search when already optimal" fix) and its swap-accept
        // branch is reachable.
        $section = $this->makeSection('A');
        $math = $this->makeSubject('Math', 7);
        $teacher = $this->makeTeacher();
        $this->assign($section, $math, $teacher);

        $result = $this->generator->generate($this->shift);

        $this->assertSame([], $result['failed']);
        $this->assertContains($section->id, array_column($result['generated'], 'section_id'));

        $entries = TimetableEntry::where('section_id', $section->id)->get(['day_of_week', 'period_no']);
        $this->assertSame(7, $entries->count());
        $this->assertSame(
            $entries->count(),
            $entries->unique(fn ($e) => $e->day_of_week.'-'.$e->period_no)->count()
        );
    }

    public function test_shortfall_reason_is_specific_when_shift_has_no_period_duration_configured(): void
    {
        $shift = Shift::create([
            'class_version_id' => $this->classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Unconfigured Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday', 'sunday'],
            'period_duration_minutes' => null,
        ]);

        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'A']);
        $math = $this->makeSubject('Math', 2);
        $teacher = $this->makeTeacher();
        $this->assign($section, $math, $teacher);

        $result = $this->generator->generate($shift);

        $this->assertCount(1, $result['failed']);
        $this->assertStringContainsString(
            'period_duration_minutes',
            $result['failed'][0]['shortfalls'][0]['reason']
        );
    }
}
