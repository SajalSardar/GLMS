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
use Illuminate\Support\Facades\DB;
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
        // class_subjects.school_id is NOT NULL as of
        // 2026_11_19_100100_add_school_id_to_class_subjects_table.php.
        DB::table('class_subjects')->insert([
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'school_id' => $school->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    public function test_subject_teacher_assignment_index_filters_by_class_id(): void
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

        $classA = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class A']);
        $classB = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class B']);

        $versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Version']);

        $shiftA = Shift::create([
            'class_version_id' => $versionA->id, 'school_id' => $school->id, 'district_id' => $school->district_id,
            'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday'],
        ]);
        $shiftB = Shift::create([
            'class_version_id' => $versionB->id, 'school_id' => $school->id, 'district_id' => $school->district_id,
            'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday'],
        ]);

        $sectionA = Section::create(['shift_id' => $shiftA->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Section']);
        $sectionB = Section::create(['shift_id' => $shiftB->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Section']);

        $subject = Subject::create(['name' => 'Math']);
        // class_subjects.school_id is NOT NULL as of
        // 2026_11_19_100100_add_school_id_to_class_subjects_table.php.
        DB::table('class_subjects')->insert([
            'class_id' => $classA->id,
            'subject_id' => $subject->id,
            'school_id' => $school->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('class_subjects')->insert([
            'class_id' => $classB->id,
            'subject_id' => $subject->id,
            'school_id' => $school->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $versionA->subjects()->attach($subject->id);
        $versionB->subjects()->attach($subject->id);

        $teacher = TeacherUser::factory()->create(['school_id' => $school->id, 'district_id' => $school->district_id]);

        SubjectTeacherAssignment::create([
            'section_id' => $sectionA->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'school_id' => $school->id, 'district_id' => $school->district_id,
        ]);
        SubjectTeacherAssignment::create([
            'section_id' => $sectionB->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id,
            'school_id' => $school->id, 'district_id' => $school->district_id,
        ]);

        $this->getJson("/api/v1/subject-teacher-assignments?class_id={$classA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.section_id', $sectionA->id);
    }
}
