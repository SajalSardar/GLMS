<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Exam;
use App\Models\Guardian;
use App\Models\GuardianUser;
use App\Models\Permission;
use App\Models\Question;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Shift;
use App\Models\StudentProfile;
use App\Models\StudentUser;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthorizedActors;
use Tests\TestCase;

class ExamAttemptControllerTest extends TestCase
{
    use CreatesAuthorizedActors;
    use RefreshDatabase;

    private School $school;

    private ClassVersion $version;

    private Subject $subject;

    private AcademicYear $academicYear;

    private Chapter $chapter;

    private Section $section;

    private Exam $manualExam;

    protected function setUp(): void
    {
        parent::setUp();

        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $this->school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $this->subject = Subject::create(['name' => 'Mathematics']);

        $class = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 6']);
        $this->version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Bangla Version']);
        $this->academicYear = AcademicYear::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $shift = Shift::create(['class_version_id' => $this->version->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $this->section = Section::create(['shift_id' => $shift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section A']);

        $book = Book::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Mathematics Book']);
        $this->chapter = Chapter::create(['book_id' => $book->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Chapter 1']);

        $this->manualExam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Midterm', 'selection_mode' => 'manual', 'status' => 'published']);

        $question = Question::create(['chapter_id' => $this->chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Q1', 'marks' => 5]);
        $this->manualExam->questions()->attach($question->id, ['order_index' => 0]);
    }

    private function eligibleStudent(): StudentUser
    {
        $role = Role::create(['name' => 'student_'.uniqid(), 'label' => 'Student']);
        $permission = Permission::firstOrCreate(['name' => 'exam_attempts.create'], ['label' => 'Start exam attempts']);
        $role->permissions()->attach($permission);

        $user = StudentUser::factory()->create(['role_id' => $role->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        StudentProfile::create(['user_id' => $user->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $this->section->id]);

        return $user;
    }

    public function test_an_eligible_student_can_start_an_attempt(): void
    {
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertCreated()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.attempt_number', 1)
            ->assertJsonCount(1, 'data.questions');
    }

    public function test_start_is_rejected_for_an_unpublished_exam(): void
    {
        $this->manualExam->update(['status' => 'draft']);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_start_is_rejected_for_a_student_in_a_different_class_version(): void
    {
        $otherClass = SchoolClass::create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Class 7']);
        $otherVersion = ClassVersion::create(['class_id' => $otherClass->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Other Version']);
        $otherShift = Shift::create(['class_version_id' => $otherVersion->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Shift', 'start_time' => '08:00', 'end_time' => '13:00', 'days_of_week' => ['saturday']]);
        $otherSection = Section::create(['shift_id' => $otherShift->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Section B']);

        $role = Role::create(['name' => 'student_ineligible', 'label' => 'Student']);
        $permission = Permission::firstOrCreate(['name' => 'exam_attempts.create'], ['label' => 'Start exam attempts']);
        $role->permissions()->attach($permission);
        $user = StudentUser::factory()->create(['role_id' => $role->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id]);
        StudentProfile::create(['user_id' => $user->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'section_id' => $otherSection->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_start_is_rejected_once_max_attempts_is_exceeded(): void
    {
        $this->manualExam->update(['max_attempts' => 1]);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertCreated();
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertUnprocessable();
    }

    public function test_per_attempt_random_start_rejects_a_too_small_pool(): void
    {
        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Final', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 5, 'marks_per_question' => 2, 'status' => 'published']);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$exam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_per_attempt_random_start_performs_the_draw_and_snapshots_marks_per_question(): void
    {
        foreach (range(1, 5) as $i) {
            Question::create(['chapter_id' => $this->chapter->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'question_type' => 'mcq_single', 'question_text' => "Random Q{$i}", 'marks' => $i + 10]);
        }

        $exam = Exam::create(['class_version_id' => $this->version->id, 'subject_id' => $this->subject->id, 'academic_year_id' => $this->academicYear->id, 'school_id' => $this->school->id, 'district_id' => $this->school->district_id, 'name' => 'Final', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 3, 'marks_per_question' => 2, 'status' => 'published']);
        $student = $this->eligibleStudent();

        $response = $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$exam->id}/attempts")
            ->assertCreated();

        $response->assertJsonCount(3, 'data.questions');

        foreach ($response->json('data.questions') as $attemptQuestion) {
            $this->assertSame(2, $attemptQuestion['marks']);
        }
    }

    public function test_start_is_rejected_when_available_from_is_in_the_future(): void
    {
        $this->manualExam->update(['available_from' => now()->addDay()]);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_start_is_rejected_when_available_until_is_in_the_past(): void
    {
        $this->manualExam->update(['available_until' => now()->subDay()]);
        $student = $this->eligibleStudent();

        $this->actingAs($student, 'sanctum')
            ->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertUnprocessable();
    }

    public function test_show_is_visible_to_the_owner_a_linked_guardian_and_management_but_not_an_unrelated_student(): void
    {
        $student = $this->eligibleStudent();
        $attemptId = $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->json('data.id');

        $this->actingAs($student, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();

        $guardian = Guardian::create(['name' => 'Guardian', 'phone' => '01700000001', 'user_id' => GuardianUser::factory()->create(['school_id' => $this->school->id, 'district_id' => $this->school->district_id])->id]);
        $studentProfile = StudentProfile::where('user_id', $student->id)->first();
        $guardian->students()->attach($studentProfile->id, ['relation' => 'father', 'is_primary' => true]);
        $this->actingAs($guardian->user, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();

        $manager = $this->actorWithPermissions(['exam_attempts.view'], $this->school->id, $this->school->district_id);
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertOk();

        $unrelatedStudent = $this->eligibleStudent();
        $this->actingAs($unrelatedStudent, 'sanctum')->getJson("/api/v1/exam-attempts/{$attemptId}")->assertForbidden();
    }

    public function test_index_lists_attempts_for_management_but_not_for_a_student(): void
    {
        $student = $this->eligibleStudent();
        $this->actingAs($student, 'sanctum')->postJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertCreated();

        $manager = $this->actorWithPermissions(['exam_attempts.view'], $this->school->id, $this->school->district_id);
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/exams/{$this->manualExam->id}/attempts")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($student, 'sanctum')->getJson("/api/v1/exams/{$this->manualExam->id}/attempts")->assertForbidden();
    }
}
