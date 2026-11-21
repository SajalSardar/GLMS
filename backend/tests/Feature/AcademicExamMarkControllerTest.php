<?php

namespace Tests\Feature;

use App\Models\AcademicExamPublication;
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
use App\Models\TeacherUser;
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

    public function test_an_all_null_record_for_an_untouched_student_does_not_create_a_row(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $touchedStudent = $this->studentInSection('1');
        $untouchedStudent = $this->studentInSection('2');

        $payload = [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [
                [
                    'student_profile_id' => $touchedStudent->profile->id,
                    'cq_marks_obtained' => 60,
                    'mcq_marks_obtained' => 25,
                    'practical_marks_obtained' => null,
                ],
                [
                    'student_profile_id' => $untouchedStudent->profile->id,
                    'cq_marks_obtained' => null,
                    'mcq_marks_obtained' => null,
                    'practical_marks_obtained' => null,
                ],
            ],
        ];

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $payload)->assertOk();

        $this->assertDatabaseCount('academic_exam_marks', 1);
        $this->assertDatabaseHas('academic_exam_marks', ['student_profile_id' => $touchedStudent->profile->id]);
        $this->assertDatabaseMissing('academic_exam_marks', ['student_profile_id' => $untouchedStudent->profile->id]);
    }

    public function test_saving_an_all_null_record_for_a_student_with_an_existing_row_clears_it(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        // First save gives this student a real mark row.
        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))->assertOk();
        $this->assertDatabaseCount('academic_exam_marks', 1);

        // A second save clearing every field must still update that
        // existing row to null rather than being skipped as "untouched".
        $clearPayload = [
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [
                [
                    'student_profile_id' => $profile->id,
                    'cq_marks_obtained' => null,
                    'mcq_marks_obtained' => null,
                    'practical_marks_obtained' => null,
                ],
            ],
        ];

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $clearPayload)->assertOk();

        $this->assertDatabaseCount('academic_exam_marks', 1);
        $this->assertDatabaseHas('academic_exam_marks', [
            'student_profile_id' => $profile->id,
            'cq_marks_obtained' => null,
            'mcq_marks_obtained' => null,
            'practical_marks_obtained' => null,
        ]);
    }

    public function test_academic_year_id_must_match_the_sections_own_academic_year(): void
    {
        $this->section->shift->update(['academic_year_id' => $this->year->id]);

        $otherYear = AcademicYear::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'is_current' => false]);

        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $payload = array_merge($this->recordPayload($profile->id), ['academic_year_id' => $otherYear->id]);

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
    }

    public function test_roster_for_an_unrelated_teacher_is_forbidden(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->studentInSection();

        $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/roster?'.http_build_query([
                'section_id' => $this->section->id,
                'subject_id' => $this->subject->id,
                'academic_exam_term_id' => $this->term->id,
                'academic_year_id' => $this->year->id,
            ]))
            ->assertForbidden();
    }

    public function test_roster_for_a_subject_teacher_who_is_not_the_class_teacher_allows_recording(): void
    {
        $subjectTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $subjectTeacher->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->studentInSection();

        $this->actingAs($subjectTeacher, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/roster?'.http_build_query([
                'section_id' => $this->section->id,
                'subject_id' => $this->subject->id,
                'academic_exam_term_id' => $this->term->id,
                'academic_year_id' => $this->year->id,
            ]))
            ->assertOk()
            ->assertJsonPath('data.can_record', true);
    }

    public function test_marksheet_is_viewable_by_the_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))->assertOk();

        // Published so position fields resolve to a real value rather than
        // the "unpublished" branch's null/0 — this pins build()'s default
        // $withPositions=true at this call site: if a future change ever
        // passed withPositions:false here (as the new promotion call sites
        // deliberately do), section_position would silently become null
        // and this assertion would catch it.
        AcademicExamPublication::create([
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->actingAs($classTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.subjects.0.subject_id', $this->subject->id)
            ->assertJsonPath('data.section_position', 1)
            ->assertJsonPath('data.section_total_students', 1);
    }

    public function test_marksheet_is_viewable_by_a_subject_teacher_with_no_class_teacher_role(): void
    {
        $otherSubject = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $otherSubject->id, 'cq_full_marks' => 100]);

        $englishTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        SubjectTeacherAssignment::create([
            'section_id' => $this->section->id, 'subject_id' => $otherSubject->id, 'teacher_id' => $englishTeacher->id,
            'school_id' => $this->school->id, 'district_id' => $this->school->district_id,
        ]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        // The English teacher may view the WHOLE marksheet (every subject,
        // including Math) even though they only teach English — unlike
        // per-subject recordSection(), which would reject them for Math.
        $this->actingAs($englishTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk();
    }

    public function test_marksheet_is_forbidden_for_a_teacher_with_no_relation_to_the_section(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }

    public function test_marksheet_is_viewable_by_staff_with_academic_exam_marks_view(): void
    {
        $student = $this->studentInSection();
        $profile = $student->profile;

        $admin = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk();
    }

    private function publishSectionTerm(Section $section): void
    {
        AcademicExamPublication::create([
            'section_id' => $section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);
    }

    /**
     * A second class/section a student can be "promoted" into, with its
     * own class teacher. Its class configures CQ only — no MCQ component
     * at all — which is what makes a cross-section overwrite destructive
     * rather than merely wrong: the MCQ score recorded under the original
     * class has nowhere to live here.
     *
     * @return array{0: Section, 1: TeacherUser}
     */
    private function promotionTargetSection(): array
    {
        $nextClass = SchoolClass::create(['name' => 'Class 7']);
        $version = ClassVersion::create(['class_id' => $nextClass->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'General']);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $nextSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Seven-A']);

        AcademicSubjectMarkConfigure::create(['class_id' => $nextClass->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 100]);

        $nextClassTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $nextSection->update(['class_teacher_id' => $nextClassTeacher->id]);

        return [$nextSection, $nextClassTeacher];
    }

    /**
     * Simulates a promotion/transfer by mutating the profile row in
     * place — exactly what StudentPromotionController does, minus the
     * endpoint.
     */
    private function moveProfileTo(StudentProfile $profile, Section $section): void
    {
        $profile->update([
            'class_id' => $section->shift->classVersion->class_id,
            'section_id' => $section->id,
            'shift_id' => $section->shift_id,
            'class_version_id' => $section->shift->class_version_id,
        ]);
    }

    public function test_marksheet_stays_with_the_grading_sections_teacher_after_the_student_is_promoted_away(): void
    {
        $gradingTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $gradingTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($gradingTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id))->assertOk();
        $this->publishSectionTerm($this->section);

        [$nextSection, $nextClassTeacher] = $this->promotionTargetSection();
        $this->moveProfileTo($profile, $nextSection);

        $url = "/api/v1/academic-exam-marks/marksheet?student_profile_id={$profile->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}";

        // The teacher who actually graded this exam keeps access, and the
        // marksheet still resolves against the class it was sat in.
        $this->actingAs($gradingTeacher, 'sanctum')->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.subjects.0.subject_id', $this->subject->id)
            ->assertJsonPath('data.subjects.0.cq', 60)
            ->assertJsonPath('data.subjects.0.mcq', 25)
            ->assertJsonPath('data.section_position', 1)
            ->assertJsonPath('data.section_total_students', 1);

        // The new section's class teacher never taught this student for
        // this exam and must NOT inherit their past marksheet.
        $this->actingAs($nextClassTeacher, 'sanctum')->getJson($url)->assertForbidden();
    }

    public function test_record_refuses_to_overwrite_a_mark_belonging_to_a_different_section(): void
    {
        $gradingTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $gradingTeacher->id]);

        $student = $this->studentInSection();
        $profile = $student->profile;

        $this->actingAs($gradingTeacher, 'sanctum')
            ->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($profile->id, ['cq_marks_obtained' => 60, 'mcq_marks_obtained' => 28]))
            ->assertOk();

        [$nextSection, $nextClassTeacher] = $this->promotionTargetSection();
        $this->moveProfileTo($profile, $nextSection);

        // The new section's teacher records the SAME (subject, term, year)
        // after the transfer. Overwriting the existing row would cross-wire
        // it onto Class 7/Seven-A and wipe the MCQ 28 that Class 7 has no
        // component for, so the record is skipped and reported instead.
        $this->actingAs($nextClassTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', [
            'section_id' => $nextSection->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'records' => [['student_profile_id' => $profile->id, 'cq_marks_obtained' => 40]],
        ])
            ->assertOk()
            ->assertJsonPath('conflicted_student_ids', [$profile->id]);

        $this->assertDatabaseCount('academic_exam_marks', 1);
        $this->assertDatabaseHas('academic_exam_marks', [
            'student_profile_id' => $profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'section_id' => $this->section->id,
            'class_id' => $this->class->id,
            'shift_id' => $this->section->shift_id,
            'cq_marks_obtained' => 60,
            'mcq_marks_obtained' => 28,
        ]);
    }

    public function test_record_reports_no_conflicts_for_an_ordinary_same_section_save(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $student = $this->studentInSection();

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-marks/record', $this->recordPayload($student->profile->id))
            ->assertOk()
            ->assertJsonPath('conflicted_student_ids', []);
    }

    public function test_section_students_lists_the_whole_roster_for_the_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $this->studentInSection('1');
        $this->studentInSection('2');

        $this->actingAs($classTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/section-students?section_id={$this->section->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.students');
    }

    public function test_section_students_is_forbidden_for_a_teacher_with_no_relation_to_the_section(): void
    {
        $unrelatedTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);

        $this->actingAs($unrelatedTeacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/section-students?section_id={$this->section->id}")
            ->assertForbidden();
    }
}
