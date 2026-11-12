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
use App\Models\TeacherUser;
use App\Models\TimetableEntry;
use App\Models\Upazila;
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

    private TeacherUser $teacher;

    private SubjectTeacherAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checker = new TimetableConflictChecker;

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

        $this->teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

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
        $other = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
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
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা२']);
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
        $otherSchoolTeacher = TeacherUser::factory()->create(['school_id' => null, 'district_id' => null]);
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

        $otherTeacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
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
