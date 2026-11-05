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
