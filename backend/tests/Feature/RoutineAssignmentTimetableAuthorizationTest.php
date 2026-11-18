<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassRoutine;
use App\Models\ClassSchool;
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

class RoutineAssignmentTimetableAuthorizationTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private Subject $subject;

    private TeacherUser $teacher;

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

        $this->class = SchoolClass::create(['name' => 'Class']);
        ClassSchool::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $classVersion = ClassVersion::create([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Version',
        ]);

        $shift = Shift::create([
            'class_version_id' => $classVersion->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Shift',
            'start_time' => '08:00',
            'end_time' => '13:00',
            'days_of_week' => ['saturday'],
        ]);

        $this->section = Section::create([
            'shift_id' => $shift->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section',
        ]);

        $this->subject = Subject::create(['name' => 'Math']);
        // class_subjects.school_id is NOT NULL as of
        // 2026_11_19_100100_add_school_id_to_class_subjects_table.php.
        DB::table('class_subjects')->insert([
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'school_id' => $this->school->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classVersion->subjects()->attach($this->subject->id);

        $this->teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
    }

    public function test_class_routine_store_and_update_are_authorized(): void
    {
        $routine = ClassRoutine::create([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 2,
        ]);
        $payload = ['class_id' => $this->class->id, 'school_id' => $this->school->id, 'subject_id' => $this->subject->id, 'day_of_week' => 'saturday', 'period_no' => 1];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/class-routines', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/class-routines/{$routine->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['class_routines.create', 'class_routines.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-routines/{$routine->id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_class_routine_store_and_update_accept_teacher_from_the_class_school(): void
    {
        $routine = ClassRoutine::create([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 3,
        ]);
        $payload = [
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 'saturday',
            'period_no' => 4,
        ];

        $allowed = $this->actorWithPermissions(['class_routines.create', 'class_routines.update']);

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payload)
            ->assertCreated()
            ->assertJsonPath('data.teacher.id', $this->teacher->id);

        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-routines/{$routine->id}", ['teacher_id' => $this->teacher->id])
            ->assertOk()
            ->assertJsonPath('data.teacher.id', $this->teacher->id);
    }

    /**
     * Regression test for ClassRoutineController: a national academic_year_id must
     * be accepted when creating or updating a class routine (previously this would fail
     * with "does not belong to this class's school" because the school_id column no
     * longer exists on academic_years).
     */
    public function test_class_routine_store_and_update_with_national_academic_year(): void
    {
        $academicYear = AcademicYear::create([
            'name' => 'Academic Year 2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $routine = ClassRoutine::create([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 5,
        ]);

        $allowed = $this->actorWithPermissions(['class_routines.create', 'class_routines.update']);

        $payloadWithYear = [
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $academicYear->id,
            'day_of_week' => 'monday',
            'period_no' => 6,
        ];

        $response = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payloadWithYear)
            ->assertCreated()
            ->assertJsonPath('data.academic_year_id', $academicYear->id);

        $routineId = $response->json('data.id');

        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/class-routines/{$routineId}", ['academic_year_id' => $academicYear->id])
            ->assertOk()
            ->assertJsonPath('data.academic_year_id', $academicYear->id);
    }

    /**
     * Regression test: school_classes.name is national now, so the same
     * class_id can be adopted by more than one school via class_schools.
     * Two different schools scheduling the identical (day_of_week,
     * period_no) slot for that shared class must not collide with each
     * other - only a slot the SAME school has already filled should be
     * rejected. Guards against class_routines' unique index (and this
     * request's own uniqueness rule) being scoped by class_id alone
     * instead of (class_id, school_id).
     */
    public function test_class_routine_store_allows_two_schools_to_share_the_same_class_day_and_period(): void
    {
        ClassRoutine::create([
            'class_id' => $this->class->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 7,
        ]);

        $divisionB = Division::create(['name' => 'Division B', 'bn_name' => 'বিভাগ বি']);
        $districtB = District::create(['division_id' => $divisionB->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $schoolB = School::create([
            'division_id' => $divisionB->id,
            'district_id' => $districtB->id,
            'upazila_id' => $upazilaB->id,
            'name' => 'School B',
        ]);

        ClassSchool::create(['class_id' => $this->class->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id]);
        DB::table('class_subjects')->insert([
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'school_id' => $schoolB->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allowed = $this->actorWithPermissions(['class_routines.create']);

        // Same class_id, same day_of_week, same period_no as School A's
        // routine above - but for School B, so this must succeed rather
        // than be rejected as "already scheduled".
        $payload = [
            'class_id' => $this->class->id,
            'school_id' => $schoolB->id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'sunday',
            'period_no' => 7,
        ];

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payload)
            ->assertCreated()
            ->assertJsonPath('data.school_id', $schoolB->id);
    }

    /**
     * Regression test: StoreClassRoutineRequest::authorize() used to
     * resolve a ClassSchool row and return false (-> 403) when the
     * (class_id, school_id) pair hadn't adopted this class, which made
     * withValidator()'s 422 check for the exact same condition
     * unreachable. authorize() now resolves the school directly, so an
     * actor who otherwise has class_routines.create and is scoped to
     * school_id sees the business-rule 422, not a 403.
     */
    public function test_class_routine_store_rejects_a_class_school_pair_missing_from_class_schools(): void
    {
        $unadoptedClass = SchoolClass::create(['name' => 'Unadopted Class']);

        $allowed = $this->actorWithPermissions(['class_routines.create'], $this->school->id, $this->school->district_id);

        $payload = [
            'class_id' => $unadoptedClass->id,
            'school_id' => $this->school->id,
            'subject_id' => $this->subject->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ];

        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/class-routines', $payload)->assertStatus(422);
    }

    public function test_subject_teacher_assignment_store_and_update_are_authorized(): void
    {
        $assignment = SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);

        // A second section on the same shift/class version, so the new
        // assignment created below targets a (section_id, subject_id) pair
        // that doesn't collide with the DB-level unique constraint on the
        // row already seeded above for the update() half of this test.
        $otherSection = Section::create([
            'shift_id' => $this->section->shift_id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section B',
        ]);
        $payload = ['section_id' => $otherSection->id, 'subject_id' => $this->subject->id, 'teacher_id' => $this->teacher->id];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', $payload)->assertForbidden();
        $this->actingAs($denied, 'sanctum')->patchJson("/api/v1/subject-teacher-assignments/{$assignment->id}", [])->assertForbidden();

        $allowed = $this->actorWithPermissions(['subject_teacher_assignments.create', 'subject_teacher_assignments.update']);
        $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/subject-teacher-assignments/{$assignment->id}", ['teacher_id' => $this->teacher->id])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }

    public function test_subject_teacher_assignment_index_is_paginated_and_filterable_by_section(): void
    {
        $otherSection = Section::create([
            'shift_id' => $this->section->shift_id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'name' => 'Section B',
        ]);

        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);
        SubjectTeacherAssignment::create([
            'section_id' => $otherSection->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $response = $this->getJson('/api/v1/subject-teacher-assignments')->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.total', 2);

        $filtered = $this->getJson("/api/v1/subject-teacher-assignments?section_id={$this->section->id}")->assertOk();
        $filtered->assertJsonCount(1, 'data');
        $filtered->assertJsonPath('data.0.section_id', $this->section->id);
    }

    public function test_timetable_entry_store_and_update_are_authorized(): void
    {
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);

        $payload = [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => 'saturday',
            'period_no' => 1,
        ];

        $denied = $this->actorWithNoPermissions();
        $this->actingAs($denied, 'sanctum')->postJson('/api/v1/timetable-entries', $payload)->assertForbidden();

        $allowed = $this->actorWithPermissions(['timetable_entries.create', 'timetable_entries.update']);
        $createResponse = $this->actingAs($allowed, 'sanctum')->postJson('/api/v1/timetable-entries', $payload)
            ->assertCreated()
            ->assertJsonPath('data.created_by_type', get_class($allowed))
            ->assertJsonPath('data.created_by_id', $allowed->id);
        $id = $createResponse->json('data.id');

        $this->actingAs($allowed, 'sanctum')->patchJson("/api/v1/timetable-entries/{$id}", [])
            ->assertOk()
            ->assertJsonPath('data.updated_by_type', get_class($allowed))
            ->assertJsonPath('data.updated_by_id', $allowed->id);
    }
}
