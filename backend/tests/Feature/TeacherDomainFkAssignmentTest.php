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
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class TeacherDomainFkAssignmentTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    public function test_subject_teacher_assignment_teacher_id_resolves_to_a_teacher_user_instance(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $school = School::create([
            'division_id' => $division->id,
            'district_id' => $district->id,
            'upazila_id' => $upazila->id,
            'name' => 'School',
        ]);

        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class']);

        $classVersion = ClassVersion::create([
            'class_id' => $class->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'Version',
        ]);

        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            'name' => 'Section',
        ]);

        $subject = Subject::create(['name' => 'Math']);
        $class->subjects()->attach($subject->id);
        $classVersion->subjects()->attach($subject->id);

        $teacher = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);

        $staff = $this->actorWithPermissions(['subject_teacher_assignments.create'], $school->id, $school->district_id);

        $payload = ['section_id' => $section->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id];

        $response = $this->actingAs($staff, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', $payload)
            ->assertCreated();

        $assignment = SubjectTeacherAssignment::findOrFail($response->json('data.id'));

        $this->assertInstanceOf(TeacherUser::class, $assignment->teacher);
        $this->assertSame($teacher->id, $assignment->teacher->id);
    }
}
