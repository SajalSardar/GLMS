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
use App\Models\Guardian;
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

class AcademicExamMarkMineControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private SchoolClass $class;

    private Section $section;

    private Subject $subject;

    private AcademicExamTerm $publishedTerm;

    private AcademicExamTerm $unpublishedTerm;

    private AcademicYear $year;

    private StudentUser $student;

    private StudentProfile $profile;

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
        AcademicSubjectMarkConfigure::create(['class_id' => $this->class->id, 'subject_id' => $this->subject->id, 'cq_full_marks' => 100]);

        $this->publishedTerm = AcademicExamTerm::create(['name' => 'First Terminal Examination', 'sort_order' => 1]);
        $this->unpublishedTerm = AcademicExamTerm::create(['name' => 'Second Terminal Examination', 'sort_order' => 2]);
        $this->year = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'is_current' => true]);

        $this->student = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);
        $this->profile = StudentProfile::create([
            'user_id' => $this->student->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'roll_number' => '1',
        ]);

        AcademicExamMark::create([
            'student_profile_id' => $this->profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->publishedTerm->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => 90,
        ]);

        AcademicExamPublication::create([
            'section_id' => $this->section->id,
            'academic_exam_term_id' => $this->publishedTerm->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
        ]);

        // Marks exist for the unpublished term too, but deliberately no
        // corresponding AcademicExamPublication row — so the fixture has
        // a state where "marks exist" and "is published" disagree, and
        // mineTerms()'s filter is proven to key on publish state rather
        // than mark existence.
        AcademicExamMark::create([
            'student_profile_id' => $this->profile->id,
            'subject_id' => $this->subject->id,
            'academic_exam_term_id' => $this->unpublishedTerm->id,
            'academic_year_id' => $this->year->id,
            'school_id' => $this->school->id,
            'district_id' => $this->school->district_id,
            'class_id' => $this->class->id,
            'section_id' => $this->section->id,
            'shift_id' => $this->section->shift_id,
            'class_version_id' => $this->section->shift->class_version_id,
            'cq_marks_obtained' => 70,
        ]);
    }

    public function test_mine_terms_lists_only_published_terms(): void
    {
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/mine/terms')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($this->publishedTerm->id, $response->json('data.0.academic_exam_term_id'));
    }

    public function test_mine_returns_the_marksheet_for_a_published_term(): void
    {
        // section_position/section_total_students pin build()'s default
        // $withPositions=true at this call site — the lone student in a
        // published section resolves to position 1 of 1; if a future
        // change ever passed withPositions:false here, these would
        // silently become null/0 and this assertion would catch it.
        $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.subjects.0.cq', 90)
            ->assertJsonPath('data.section_position', 1)
            ->assertJsonPath('data.section_total_students', 1);
    }

    public function test_mine_404s_for_an_unpublished_term(): void
    {
        $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->unpublishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertNotFound();
    }

    public function test_mine_returns_null_data_when_the_student_has_no_profile(): void
    {
        $profileless = $this->studentActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($profileless, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /**
     * Simulates a promotion/transfer by mutating the student's own
     * profile row straight onto a brand-new class/section — exactly what
     * StudentPromotionController does in place, minus the endpoint. The
     * new class deliberately gets NO academic_subject_mark_configures
     * row, so anything resolving against the student's CURRENT class
     * would come back empty rather than quietly wrong.
     */
    private function promoteProfileToANewSection(): Section
    {
        $nextClass = SchoolClass::create(['name' => 'Class 7']);
        $version = ClassVersion::create(['class_id' => $nextClass->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'version_id' => Version::where('name', 'Bangla')->value('id')]);
        $shift = Shift::create(['class_version_id' => $version->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Day Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['sunday']]);
        $nextSection = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Seven-A']);

        $this->profile->update([
            'class_id' => $nextClass->id,
            'section_id' => $nextSection->id,
            'shift_id' => $nextSection->shift_id,
            'class_version_id' => $version->id,
        ]);

        return $nextSection;
    }

    public function test_mine_terms_still_lists_a_published_term_after_the_student_is_promoted_away(): void
    {
        $this->promoteProfileToANewSection();

        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/mine/terms')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame($this->publishedTerm->id, $response->json('data.0.academic_exam_term_id'));
    }

    public function test_mine_terms_still_lists_a_published_term_for_a_student_with_no_current_section(): void
    {
        $this->profile->update(['section_id' => null, 'shift_id' => null, 'class_version_id' => null, 'class_id' => null]);

        $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/academic-exam-marks/mine/terms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.academic_exam_term_id', $this->publishedTerm->id);
    }

    public function test_mine_still_returns_the_marksheet_after_the_student_is_promoted_away(): void
    {
        $this->promoteProfileToANewSection();

        $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertOk()
            ->assertJsonPath('data.subjects.0.cq', 90)
            ->assertJsonPath('data.result', 'pass')
            ->assertJsonPath('data.section_position', 1)
            ->assertJsonPath('data.section_total_students', 1);
    }

    public function test_mine_still_404s_for_an_unpublished_term_after_the_student_is_promoted_away(): void
    {
        $this->promoteProfileToANewSection();

        $this->actingAs($this->student, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->unpublishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertNotFound();
    }

    public function test_mine_is_forbidden_for_a_non_student_actor(): void
    {
        $teacher = $this->teacherActorWithPermissions([], $this->school->id, $this->school->district_id);

        $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertForbidden();
    }

    public function test_a_guardian_can_view_their_linked_childs_marksheet_and_terms(): void
    {
        $guardianUser = $this->guardianActorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);
        $guardian = Guardian::create(['user_id' => $guardianUser->id, 'name' => 'Guardian', 'phone' => '01700000001']);
        $guardian->students()->attach($this->profile->id, ['relation' => 'father', 'is_primary' => true]);

        $this->actingAs($guardianUser, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine/terms?student_profile_id={$this->profile->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($guardianUser, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}&student_profile_id={$this->profile->id}")
            ->assertOk()
            ->assertJsonPath('data.subjects.0.cq', 90);
    }

    public function test_mine_requires_student_profile_id_for_a_guardian(): void
    {
        $guardianUser = $this->guardianActorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);

        $this->actingAs($guardianUser, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_profile_id');
    }

    public function test_mine_rejects_a_guardian_requesting_a_child_not_linked_to_them(): void
    {
        $guardianUser = $this->guardianActorWithPermissions(['academic_exam_marks.view'], $this->school->id, $this->school->district_id);
        Guardian::create(['user_id' => $guardianUser->id, 'name' => 'Guardian', 'phone' => '01700000002']);

        $this->actingAs($guardianUser, 'sanctum')
            ->getJson("/api/v1/academic-exam-marks/mine?academic_exam_term_id={$this->publishedTerm->id}&academic_year_id={$this->year->id}&student_profile_id={$this->profile->id}")
            ->assertForbidden();
    }
}
