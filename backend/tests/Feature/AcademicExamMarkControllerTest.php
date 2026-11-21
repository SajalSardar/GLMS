<?php

namespace Tests\Feature;

use App\Models\AcademicExamTerm;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicExamMarkControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private Subject $subject;

    private AcademicExamTerm $term;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->class = SchoolClass::create(['name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Main']);

        $this->subject = Subject::create(['name' => 'Mathematics']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $this->term = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);
    }

    private function studentInSection(?string $rollNumber = '1'): StudentUser
    {
        $student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        StudentProfile::create([
            'user_id' => $student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'roll_number' => $rollNumber,
        ]);

        return $student;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordPayload(int $studentProfileId, array $overrides = []): array
    {
        return [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [
                [
                    'student_profile_id' => $studentProfileId,
                    'cq_marks_obtained' => 60,
                    'mcq_marks_obtained' => 25,
                    'practical_marks_obtained' => null,
                    ...$overrides,
                ],
            ],
        ];
    }

    public function test_the_class_teacher_can_record_marks_for_any_configured_subject_in_their_section(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertOk()
            ->assertJsonPath('data.students.0.cq_marks_obtained', 60);

        $this->assertDatabaseHas('academic_exam_marks', [
            'student_profile_id' => $profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'cq_marks_obtained' => 60,
            'mcq_marks_obtained' => 25,
            'entered_by_id' => $classTeacher->id,
        ]);
    }

    public function test_a_subject_teacher_can_record_marks_for_their_own_assigned_subject(): void
    {
        $subjectTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $subjectTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($subjectTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertOk();

        $this->assertDatabaseHas('academic_exam_marks', ['student_profile_id' => $profile->id, 'entered_by_id' => $subjectTeacher->id]);
    }

    public function test_a_teacher_with_no_relation_to_the_section_and_subject_cannot_record(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($unrelatedTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertForbidden();
    }

    public function test_a_subject_teacher_cannot_record_a_different_subject_in_the_same_section(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $mathTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $mathTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($mathTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', [
            'section_id' => $this->section->id,
            'subject_id' => $otherSubject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [['student_profile_id' => $profile->id, 'cq_marks_obtained' => 80]],
        ])->assertForbidden();
    }

    public function test_recording_again_updates_the_existing_row_instead_of_duplicating(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['cq_marks_obtained' => 50]))->assertOk();
        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['cq_marks_obtained' => 65]))->assertOk();

        $this->assertDatabaseCount('academic_exam_marks', 1);
        $this->assertDatabaseHas('academic_exam_marks', ['student_profile_id' => $profile->id, 'cq_marks_obtained' => 65]);
    }

    public function test_record_is_rejected_when_no_subject_mark_configure_exists(): void
    {
        $unconfigured = Subject::create(['name' => 'Art']);

        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', [
            'section_id' => $this->section->id,
            'subject_id' => $unconfigured->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [['student_profile_id' => $profile->id, 'cq_marks_obtained' => 50]],
        ])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
    }

    public function test_record_rejects_obtained_marks_exceeding_full_marks(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['cq_marks_obtained' => 90]))
            ->assertUnprocessable()->assertJsonValidationErrors('records.0.cq_marks_obtained');
    }

    public function test_record_rejects_a_component_not_configured_for_the_subject(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        // practical_full_marks is null for this subject's config.
        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['practical_marks_obtained' => 10]))
            ->assertUnprocessable()->assertJsonValidationErrors('records.0.practical_marks_obtained');
    }

    public function test_my_assignments_includes_every_subject_for_a_class_teachers_section(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $response = $this->actingAs($classTeacher, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/my-assignments')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $subjectIds = collect($response->json('data'))->pluck('subject_id')->sort()->values()->all();
        $this->assertSame([$this->subject->id, $otherSubject->id], $subjectIds);
    }

    public function test_my_assignments_includes_only_the_assigned_subject_for_a_subject_teacher(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $subjectTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $subjectTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->actingAs($subjectTeacher, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/my-assignments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', $this->subject->id);
    }

    public function test_a_school_admin_can_view_and_record_without_a_direct_assignment(): void
    {
        $student = $this->studentInSection();
        $profile = $student->profile;

        $schoolAdmin = $this->actorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($schoolAdmin, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))
            ->assertOk();
    }
}
