<?php

namespace Tests\Feature;

use App\Models\ClassRoutine;
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

        $this->class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class']);

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
        $this->class->subjects()->attach($this->subject->id);
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
        $payload = ['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'day_of_week' => 'saturday', 'period_no' => 1];

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
