<?php

namespace Tests\Feature;

use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ClassRoutineControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Subject $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);

        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $this->subject = Subject::create(['name' => 'Mathematics']);

        ClassSchool::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        DB::table('class_subjects')->insert([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_index_filters_by_class_version_shift_and_section(): void
    {
        // Two DIFFERENT classes, not two versions of the same class:
        // class_routines is unique on (class_id, school_id, day_of_week,
        // period_no) — NOT section-scoped — so two versions of the SAME
        // class could never both hold a Sunday-period-1 slot in the same
        // school. Each school_class in practice gets exactly one
        // class_version's worth of routine, so class_version_id/shift_id/
        // section_id all narrow to the SAME single grid as class_id does
        // here — this test just confirms each filter independently wires
        // through to the right `where()`.
        $classB = SchoolClass::create(['name' => 'Class 7']);
        ClassSchool::create(['class_id' => $classB->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        DB::table('class_subjects')->insert([
            'class_id' => $classB->id, 'school_id' => $this->school->id, 'subject_id' => $this->subject->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $versionA = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shiftA = Shift::create(['class_version_id' => $versionA->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $sectionA = Section::create(['shift_id' => $shiftA->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'A']);

        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shiftB = Shift::create(['class_version_id' => $versionB->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $sectionB = Section::create(['shift_id' => $shiftB->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'B']);

        $teacher = $this->teacherActorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);

        $routineAId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $sectionA->id,
            'subject_id' => $this->subject->id, 'day_of_week' => 'sunday', 'period_no' => 1,
        ])->json('data.id');

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $classB->id, 'school_id' => $this->school->id, 'section_id' => $sectionB->id,
            'subject_id' => $this->subject->id, 'day_of_week' => 'sunday', 'period_no' => 1,
        ])->assertCreated();

        // section_id alone narrows to exactly one grid, same as shift_id
        // or class_version_id alone would (each maps 1:1 to the other
        // two here) — exercised separately so a future divergence in any
        // one filter's `when()` clause is caught on its own.
        $this->getJson("/api/v1/class-routines?section_id={$sectionA->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $routineAId);

        $this->getJson("/api/v1/class-routines?shift_id={$shiftA->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $routineAId);

        $this->getJson("/api/v1/class-routines?class_version_id={$versionA->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $routineAId);

        // Eager-loaded relations the Management Portal's own Teacher/Class
        // Routine pages need to render the same grid the Teacher/Student
        // portals already do, without a second request per relation.
        $this->getJson("/api/v1/class-routines?section_id={$sectionA->id}")
            ->assertJsonPath('data.0.section.id', $sectionA->id)
            ->assertJsonPath('data.0.shift.id', $shiftA->id)
            ->assertJsonPath('data.0.class_version.id', $versionA->id);
    }

    public function test_store_denormalizes_shift_and_class_version_from_the_given_section(): void
    {
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $teacher = $this->teacherActorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'section_id' => $section->id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 1,
        ])->assertCreated();

        $response->assertJsonPath('data.section_id', $section->id)
            ->assertJsonPath('data.shift_id', $shift->id)
            ->assertJsonPath('data.class_version_id', $version->id);
    }

    public function test_store_rejects_a_section_belonging_to_a_different_school(): void
    {
        $otherDivision = Division::create(['name' => 'Division 2', 'bn_name' => 'বিভাগ ২']);
        $otherDistrict = District::create(['division_id' => $otherDivision->id, 'name' => 'District 2', 'bn_name' => 'জেলা ২']);
        $otherUpazila = Upazila::create(['district_id' => $otherDistrict->id, 'name' => 'Upazila 2', 'bn_name' => 'উপজেলা ২']);
        $otherSchool = School::create(['division_id' => $otherDivision->id, 'district_id' => $otherDistrict->id, 'upazila_id' => $otherUpazila->id, 'name' => 'Other School']);

        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $otherSchoolSection = Section::create(['shift_id' => $shift->id, 'school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id, 'name' => 'Main']);

        $teacher = $this->teacherActorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);

        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'section_id' => $otherSchoolSection->id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('section_id');
    }

    public function test_mine_returns_only_this_students_own_routine_in_weekly_order(): void
    {
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday', 'monday']]);
        $ownSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $teacher = $this->teacherActorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);

        $mondayId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $ownSection->id,
            'subject_id' => $this->subject->id, 'day_of_week' => 'monday', 'period_no' => 1,
        ])->json('data.id');

        $sundayId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $ownSection->id,
            'subject_id' => $this->subject->id, 'day_of_week' => 'sunday', 'period_no' => 1,
        ])->json('data.id');

        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $ownSection->id,
            'shift_id' => $shift->id,
            'class_version_id' => $version->id,
        ]);

        // Sunday comes before Monday in the canonical school-week order
        // (ClassRoutine::DAYS_OF_WEEK), despite being created second.
        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/class-routines/mine')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $sundayId)
            ->assertJsonPath('data.1.id', $mondayId);
    }

    public function test_mine_is_empty_for_a_student_with_no_section_assigned(): void
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create(['user_id' => $student->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/class-routines/mine')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_mine_returns_only_this_teachers_own_routine_in_weekly_order(): void
    {
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday', 'monday']]);
        $section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $teacherA = $this->teacherActorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);
        $teacherB = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        $mondayId = $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $section->id,
            'subject_id' => $this->subject->id, 'teacher_id' => $teacherA->id, 'day_of_week' => 'monday', 'period_no' => 1,
        ])->json('data.id');

        $sundayId = $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $section->id,
            'subject_id' => $this->subject->id, 'teacher_id' => $teacherA->id, 'day_of_week' => 'sunday', 'period_no' => 1,
        ])->json('data.id');

        // Someone else's period in the very same slot shape — must not
        // leak into teacherA's own "mine" response.
        $this->actingAs($teacherA, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $section->id,
            'subject_id' => $this->subject->id, 'teacher_id' => $teacherB->id, 'day_of_week' => 'sunday', 'period_no' => 2,
        ])->assertCreated();

        $this->actingAs($teacherA, 'sanctum')
            ->getJson('/api/v1/class-routines/mine')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $sundayId)
            ->assertJsonPath('data.1.id', $mondayId);
    }

    public function test_mine_filters_a_teachers_routine_by_shift(): void
    {
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);

        $dayShift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $daySection = Section::create(['shift_id' => $dayShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $morningShift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Morning Shift', 'start_time' => '06:00', 'end_time' => '11:00', 'days_of_week' => ['sunday']]);
        $morningSection = Section::create(['shift_id' => $morningShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $teacher = $this->teacherActorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);

        $dayRoutineId = $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $daySection->id,
            'subject_id' => $this->subject->id, 'teacher_id' => $teacher->id, 'day_of_week' => 'sunday', 'period_no' => 1,
        ])->json('data.id');

        // Different period_no — the same class_id can't be double-booked
        // in the same day_of_week/period_no slot even across sections
        // (the unique index has no section dimension), so this needs a
        // slot of its own purely to coexist in the fixture.
        $this->actingAs($teacher, 'sanctum')->postJson('/api/v1/class-routines', [
            'class_id' => $this->class->id, 'school_id' => $this->school->id, 'section_id' => $morningSection->id,
            'subject_id' => $this->subject->id, 'teacher_id' => $teacher->id, 'day_of_week' => 'sunday', 'period_no' => 2,
        ])->assertCreated();

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/class-routines/mine?shift_id={$dayShift->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dayRoutineId);
    }

    public function test_mine_is_forbidden_for_a_non_student_non_teacher_actor(): void
    {
        $staff = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/v1/class-routines/mine')
            ->assertForbidden();
    }
}
