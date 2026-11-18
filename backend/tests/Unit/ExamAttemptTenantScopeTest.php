<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\ExamAttemptAnswer;
use App\Models\ExamAttemptQuestion;
use App\Models\Question;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ExamAttemptTenantScopeTest extends TestCase
{
    use RefreshDatabase;

    private ExamAttempt $attemptA;

    private School $schoolA;

    private Exam $examA;

    private Question $questionA;

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

        $subject = Subject::create(['name' => 'Mathematics']);

        $classA = SchoolClass::create(['name' => 'Class 6']);
        $classB = SchoolClass::create(['name' => 'Class 6 B']);

        $versionA = ClassVersion::create(['class_id' => $classA->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id, 'name' => 'Bangla Version']);
        $versionB = ClassVersion::create(['class_id' => $classB->id, 'school_id' => $schoolB->id, 'district_id' => $schoolB->district_id, 'name' => 'Bangla Version']);

        $academicYearA = AcademicYear::create(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $academicYearB = AcademicYear::create(['name' => '2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);

        $this->examA = Exam::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYearA->id, 'school_id' => $versionA->school_id, 'district_id' => $versionA->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual']);
        $examB = Exam::create(['class_version_id' => $versionB->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYearB->id, 'school_id' => $versionB->school_id, 'district_id' => $versionB->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual']);

        $book = Book::create(['class_version_id' => $versionA->id, 'subject_id' => $subject->id, 'school_id' => $this->examA->school_id, 'district_id' => $this->examA->district_id, 'name' => 'Mathematics Book']);
        $chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $this->examA->school_id, 'district_id' => $this->examA->district_id, 'name' => 'Chapter 1']);
        $this->questionA = Question::create(['chapter_id' => $chapter->id, 'school_id' => $this->examA->school_id, 'district_id' => $this->examA->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Q1']);

        $studentA = StudentUser::factory()->create(['school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);
        $studentB = StudentUser::factory()->create(['school_id' => $schoolB->id, 'district_id' => $schoolB->district_id]);

        $this->attemptA = ExamAttempt::create([
            'exam_id' => $this->examA->id,
            'user_id' => $studentA->id,
            'school_id' => $this->examA->school_id,
            'district_id' => $this->examA->district_id,
            'attempt_number' => 1,
            'started_at' => now(),
        ]);

        ExamAttempt::create([
            'exam_id' => $examB->id,
            'user_id' => $studentB->id,
            'school_id' => $examB->school_id,
            'district_id' => $examB->district_id,
            'attempt_number' => 1,
            'started_at' => now(),
        ]);
    }

    public function test_school_scoped_actor_sees_only_their_own_school_attempts(): void
    {
        $role = Role::create(['name' => 'school_admin_attempts', 'label' => 'School Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => $this->schoolA->id, 'district_id' => $this->schoolA->district_id]);

        Auth::login($actor);

        $visible = ExamAttempt::all();

        $this->assertCount(1, $visible);
        $this->assertSame($this->attemptA->id, $visible->first()->id);
    }

    public function test_unscoped_actor_sees_both_schools_attempts(): void
    {
        $role = Role::create(['name' => 'national_admin_attempts', 'label' => 'National Admin']);
        $actor = User::factory()->create(['role_id' => $role->id, 'school_id' => null, 'district_id' => null]);

        Auth::login($actor);

        $this->assertCount(2, ExamAttempt::all());
    }

    public function test_status_defaults_to_in_progress(): void
    {
        $this->assertSame('in_progress', $this->attemptA->status);
    }

    public function test_attempt_question_has_no_tenant_columns_and_belongs_to_attempt(): void
    {
        $attemptQuestion = ExamAttemptQuestion::create(['exam_attempt_id' => $this->attemptA->id, 'question_id' => $this->questionA->id, 'marks' => 2]);

        $this->assertFalse($this->attemptA->getConnection()->getSchemaBuilder()->hasColumn('exam_attempt_questions', 'school_id'));
        $this->assertSame($this->attemptA->id, $attemptQuestion->examAttempt->id);
        $this->assertSame(0, $attemptQuestion->order_index);
    }

    public function test_attempt_answer_casts_selected_option_ids_to_array(): void
    {
        $answer = ExamAttemptAnswer::create(['exam_attempt_id' => $this->attemptA->id, 'question_id' => $this->questionA->id, 'selected_option_ids' => [10, 20]]);

        $this->assertSame([10, 20], $answer->refresh()->selected_option_ids);
    }
}
