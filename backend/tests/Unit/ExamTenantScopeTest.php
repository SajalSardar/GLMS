<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\Question;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ExamTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Exam $examA;

    private School $schoolA;

    private ClassVersion $versionA;

    private Subject $subject;

    private AcademicYear $academicYearA;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $districtA = District::create(['division_id' => $division->id, 'name' => 'District A', 'bn_name' => 'জেলা এ']);
        $districtB = District::create(['division_id' => $division->id, 'name' => 'District B', 'bn_name' => 'জেলা বি']);
        $upazilaA = Upazila::create(['district_id' => $districtA->id, 'name' => 'Upazila A', 'bn_name' => 'উপজেলা এ']);
        $upazilaB = Upazila::create(['district_id' => $districtB->id, 'name' => 'Upazila B', 'bn_name' => 'উপজেলা বি']);

        $this->schoolA = School::create(['division_id' => $division->id, 'district_id' => $districtA->id, 'upazila_id' => $upazilaA->id, 'name' => 'School A']);
        $schoolB = School::create(['division_id' => $division->id, 'district_id' => $districtB->id, 'upazila_id' => $upazilaB->id, 'name' => 'School B']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Class 6']);
        $classB = SchoolClass::create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Class 6']);

        $this->versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $this->academicYearA = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $academicYearB = AcademicYear::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->examA = Exam::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYearA->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Midterm',
            'selection_mode' => 'manual',
        ]);

        Exam::create([
            'class_version_id' => $versionB->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $academicYearB->id,
            'school_id' => $versionB->school_id,
            'district_id' => $versionB->district_id,
            'name' => 'Midterm',
            'selection_mode' => 'manual',
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_exams(): void
    {
        $role = Role::create(['name' => 'school_admin_exams', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = Exam::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->examA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_exams(): void
    {
        $role = Role::create(['name' => 'national_admin_exams', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, Exam::all());
    }

    public function test_status_defaults_to_draft(): void
    {
        $this->assertSame('draft', $this->examA->status);
    }

    public function test_denormalized_tenant_columns_match_the_parent_class_version(): void
    {
        $this->assertSame($this->versionA->school_id, $this->examA->school_id);
        $this->assertSame($this->versionA->district_id, $this->examA->district_id);
    }

    public function test_total_marks_sums_attached_questions_for_manual_mode(): void
    {
        $book = Book::create(['class_version_id' => $this->versionA->id, 'subject_id' => $this->subject->id, 'school_id' => $this->versionA->school_id, 'district_id' => $this->versionA->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 1']);

        $q1 = Question::create(['chapter_id' => $chapter->id, 'question_type' => 'mcq_single', 'question_text' => 'Q1', 'marks' => 2]);
        $q2 = Question::create(['chapter_id' => $chapter->id, 'question_type' => 'mcq_single', 'question_text' => 'Q2', 'marks' => 3]);

        $this->examA->questions()->attach([$q1->id => ['order_index' => 0], $q2->id => ['order_index' => 1]]);

        $this->assertSame(5, $this->examA->load('questions')->total_marks);
    }

    public function test_total_marks_multiplies_count_by_marks_per_question_for_per_attempt_random_mode(): void
    {
        $exam = Exam::create([
            'class_version_id' => $this->versionA->id,
            'subject_id' => $this->subject->id,
            'academic_year_id' => $this->academicYearA->id,
            'school_id' => $this->versionA->school_id,
            'district_id' => $this->versionA->district_id,
            'name' => 'Final',
            'selection_mode' => 'per_attempt_random',
            'random_question_count' => 5,
            'marks_per_question' => 2,
        ]);

        $this->assertSame(10, $exam->total_marks);
    }
}
