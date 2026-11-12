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
use App\Models\TeacherUser;
use App\Models\TimetableEntry;
use App\Models\Upazila;
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
        $teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
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
        $teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
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
