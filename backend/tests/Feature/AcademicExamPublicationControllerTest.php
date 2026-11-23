<?php

namespace Tests\Feature;

use App\Models\AcademicExamMark;
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
use App\Models\Upazila;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class AcademicExamPublicationControllerTest extends TestCase
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
        $version = ClassVersion::create(['class_id' => $this->class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
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

    public function test_status_reports_entered_count_against_roster_size(): void
    {
        $student = $this->studentInSection();
        $profile = $student->profile;

        AcademicExamMark::create([
            'student_profile_id' => $profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => 60,
        ]);

        $admin = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/academic-exam-publications/status?section_id={$this->section->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.roster_size', 1)
            ->assertJsonPath('data.subjects.0.entered_count', 1)
            ->assertJsonPath('data.is_published', false);
    }

    public function test_status_entered_count_ignores_all_null_rows(): void
    {
        $realStudent = $this->studentInSection('1');
        $allNullStudent = $this->studentInSection('2');

        AcademicExamMark::create([
            'student_profile_id' => $realStudent->profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => 60,
        ]);

        AcademicExamMark::create([
            'student_profile_id' => $allNullStudent->profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => null,
            'mcq_marks_obtained' => null,
            'practical_marks_obtained' => null,
        ]);

        $admin = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/academic-exam-publications/status?section_id={$this->section->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.roster_size', 2)
            ->assertJsonPath('data.subjects.0.entered_count', 1);
    }

    public function test_status_requires_view_permission(): void
    {
        $viewer = $this->actorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/v1/academic-exam-publications/status?section_id={$this->section->id}&academic_exam_term_id={$this->term->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }

    public function test_publishing_requires_the_publish_permission(): void
    {
        $viewer = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertForbidden();
    }

    public function test_a_teacher_cannot_publish_even_as_the_class_teacher(): void
    {
        $classTeacher = $this->teacherActorWithPermissions(['academic_exam_marks.view', 'academic_exam_marks.update'], $this->school->id, $this->school->district_id);
        $this->section->update(['class_teacher_id' => $classTeacher->id]);

        $this->actingAs($classTeacher, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertForbidden();
    }

    public function test_publishing_creates_a_publication_row(): void
    {
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertCreated()->assertJsonPath('data.is_published', true);

        $this->assertDatabaseHas('academic_exam_publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);
    }

    public function test_publishing_again_is_a_no_op_not_an_error(): void
    {
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);

        $payload = ['section_id' => $this->section->id, 'academic_exam_term_id' => $this->term->id, 'academic_year_id' => $this->year->id];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', $payload)->assertCreated();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', $payload)->assertCreated();

        $this->assertDatabaseCount('academic_exam_publications', 1);
    }

    public function test_is_published_helper_reflects_publication_state(): void
    {
        $this->assertFalse(AcademicExamPublication::isPublished($this->section->id, $this->term->id, $this->year->id));

        AcademicExamPublication::create([
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        $this->assertTrue(AcademicExamPublication::isPublished($this->section->id, $this->term->id, $this->year->id));
    }

    public function test_publishing_rejects_an_academic_year_id_that_does_not_match_the_sections_own_academic_year(): void
    {
        $this->section->shift->update(['academic_year_id' => $this->year->id]);
        $otherYear = AcademicYear::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31', 'is_current' => false]);

        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $otherYear->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');

        $this->assertDatabaseCount('academic_exam_publications', 0);
    }

    public function test_publishing_rejects_an_incomplete_roster_naming_the_unentered_subject(): void
    {
        $english = Subject::create(['name' => 'English']);
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $english->id, 'cq_full_marks' => 70, 'mcq_full_marks' => 30]);

        $studentA = $this->studentInSection('1');
        $studentB = $this->studentInSection('2');

        foreach ([$studentA, $studentB] as $student) {
            AcademicExamMark::create([
                'student_profile_id' => $student->profile->id,
                'subject_id' => $this->subject->id,
                'academic_exam_term_id' => $this->term->id,
                'academic_year_id' => $this->year->id,
                'school_id' => $this->school->id,
                'district_id' => $this->school->district_id,
                'class_id' => $this->class->id,
                'section_id' => $this->section->id,
                'shift_id' => $this->section->shift_id,
                'class_version_id' => $this->section->shift->class_version_id,
                'cq_marks_obtained' => 60,
            ]);
        }

        // English has no marks entered for either student.
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('section_id');

        $this->assertStringContainsString('English', $response->json('errors.section_id.0'));
        $this->assertDatabaseCount('academic_exam_publications', 0);
    }

    public function test_publishing_rejects_an_incomplete_roster_even_when_a_promoted_out_student_has_marks_for_this_section(): void
    {
        $studentA = $this->studentInSection('1');
        $studentB = $this->studentInSection('2');
        $studentC = $this->studentInSection('3');

        $otherSection = Section::create(['shift_id' => $this->section->shift_id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Other']);

        foreach ([$studentA, $studentC] as $student) {
            AcademicExamMark::create([
                'student_profile_id' => $student->profile->id,
                'subject_id' => $this->subject->id,
                'academic_exam_term_id' => $this->term->id,
                'academic_year_id' => $this->year->id,
                'school_id' => $this->school->id,
                'district_id' => $this->school->district_id,
                'class_id' => $this->class->id,
                'section_id' => $this->section->id,
                'shift_id' => $this->section->shift_id,
                'class_version_id' => $this->section->shift->class_version_id,
                'cq_marks_obtained' => 60,
            ]);
        }

        // Student C was promoted out of this section after being graded here.
        $studentC->profile->update(['section_id' => $otherSection->id]);

        // Student B is still enrolled in this section but was never graded.
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('section_id');

        $this->assertStringContainsString('Mathematics', $response->json('errors.section_id.0'));
        $this->assertDatabaseCount('academic_exam_publications', 0);
    }

    public function test_publishing_restores_a_soft_deleted_publication_for_the_same_triple(): void
    {
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);
        $payload = ['section_id' => $this->section->id, 'academic_exam_term_id' => $this->term->id, 'academic_year_id' => $this->year->id];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', $payload)->assertCreated();

        AcademicExamPublication::where('section_id', $this->section->id)
            ->where('academic_exam_term_id', $this->term->id)
            ->where('academic_year_id', $this->year->id)
            ->first()->delete();

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', $payload)
            ->assertCreated()->assertJsonPath('data.is_published', true);

        $this->assertSame(1, AcademicExamPublication::withTrashed()
            ->where('section_id', $this->section->id)
            ->where('academic_exam_term_id', $this->term->id)
            ->where('academic_year_id', $this->year->id)
            ->count());
        $this->assertTrue(AcademicExamPublication::isPublished($this->section->id, $this->term->id, $this->year->id));
    }

    public function test_unpublishing_soft_deletes_the_publication_row(): void
    {
        $admin = $this->actorWithPermissions(['academic_exam_marks.publish'], $this->school->id, $this->school->district_id);
        $payload = ['section_id' => $this->section->id, 'academic_exam_term_id' => $this->term->id, 'academic_year_id' => $this->year->id];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/academic-exam-publications', $payload)->assertCreated();

        $this->actingAs($admin, 'sanctum')->deleteJson('/api/v1/academic-exam-publications', $payload)
            ->assertOk()->assertJsonPath('data.is_published', false);

        $this->assertFalse(AcademicExamPublication::isPublished($this->section->id, $this->term->id, $this->year->id));
        $this->assertSoftDeleted('academic_exam_publications', [
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->term->id,
            'academic_year_id' => $this->year->id,
        ]);
    }

    public function test_unpublishing_requires_the_publish_permission(): void
    {
        $viewer = $this->actorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);
        $payload = ['section_id' => $this->section->id, 'academic_exam_term_id' => $this->term->id, 'academic_year_id' => $this->year->id];

        $this->actingAs($viewer, 'sanctum')->deleteJson('/api/v1/academic-exam-publications', $payload)
            ->assertForbidden();
    }
}
