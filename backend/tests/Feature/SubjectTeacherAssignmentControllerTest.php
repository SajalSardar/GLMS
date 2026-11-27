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
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class SubjectTeacherAssignmentControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private Section $section;

    private Subject $subject;

    private TeacherUser $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        $version->subjects()->attach($this->subject->id);

        $this->teacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
    }

    public function test_store_creates_an_assignment(): void
    {
        $actor = $this->actorWithPermissions(['subject_teacher_assignments.create']);

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.teacher.id', $this->teacher->id);

        $this->assertDatabaseHas('subject_teacher_assignments', [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
        ]);
    }

    public function test_store_rejects_a_subject_not_configured_for_the_sections_class_version(): void
    {
        $actor = $this->actorWithPermissions(['subject_teacher_assignments.create']);
        $unconfigured = Subject::create(['name' => 'Physics']);

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', [
            'section_id' => $this->section->id,
            'subject_id' => $unconfigured->id,
            'teacher_id' => $this->teacher->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_store_rejects_a_teacher_from_a_different_school(): void
    {
        $actor = $this->actorWithPermissions(['subject_teacher_assignments.create']);
        $otherSchool = School::create(['division_id' => $this->school->division_id, 'district_id' => $this->school->district_id, 'upazila_id' => $this->school->upazila_id, 'name' => 'Other School']);
        $outsideTeacher = TeacherUser::factory()->create(['school_id' => $otherSchool->id, 'district_id' => $otherSchool->district_id]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $outsideTeacher->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('teacher_id');
    }

    /**
     * Regression guard: the table's own unique(section_id, subject_id)
     * index already makes a second live assignment impossible at the DB
     * layer — this proves the conflict now surfaces as a clean 422
     * validation error instead of a raw 500 QueryException.
     */
    public function test_store_rejects_a_second_teacher_for_a_subject_the_section_already_has_an_assignment_for(): void
    {
        $actor = $this->actorWithPermissions(['subject_teacher_assignments.create']);
        $secondTeacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $secondTeacher->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');

        $this->assertDatabaseCount('subject_teacher_assignments', 1);
    }

    /**
     * A DIFFERENT subject in the same section is unaffected by the
     * conflict guard above.
     */
    public function test_store_allows_a_second_assignment_for_a_different_subject_in_the_same_section(): void
    {
        $actor = $this->actorWithPermissions(['subject_teacher_assignments.create']);
        $otherSubject = Subject::create(['name' => 'English']);
        $classVersion = $this->section->shift->classVersion;
        $classVersion->subjects()->attach($otherSubject->id);

        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', [
            'section_id' => $this->section->id,
            'subject_id' => $otherSubject->id,
            'teacher_id' => $this->teacher->id,
        ])->assertCreated();

        $this->assertDatabaseCount('subject_teacher_assignments', 2);
    }

    /**
     * Restoring a soft-deleted assignment for the same (section, subject)
     * pair must keep working — the conflict guard only sees LIVE rows
     * (SoftDeletes excludes trashed rows from the default query), so it
     * must not fire here.
     */
    public function test_store_restores_a_soft_deleted_assignment_for_the_same_section_and_subject(): void
    {
        $actor = $this->actorWithPermissions(['subject_teacher_assignments.create']);

        $assignment = SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);
        $assignment->delete();

        $newTeacher = TeacherUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id]);

        $this->actingAs($actor, 'sanctum')->postJson('/api/v1/subject-teacher-assignments', [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $newTeacher->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', $assignment->id);

        $this->assertDatabaseHas('subject_teacher_assignments', [
            'id' => $assignment->id,
            'teacher_id' => $newTeacher->id,
            'deleted_at' => null,
        ]);
    }
}
