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
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamEligibleRandomQuestionIdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_eligible_pool_respects_difficulty_and_random_chapters_filters(): void
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);
        $subject = Subject::create(['name' => 'Mathematics']);
        $class = SchoolClass::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Class 6']);
        $version = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Bangla Version']);
        $academicYear = AcademicYear::create(['school_id' => $school->id, 'district_id' => $school->district_id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $book = Book::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Mathematics Book']);
        $chapterA = Chapter::create(['book_id' => $book->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Chapter 1']);
        $chapterB = Chapter::create(['book_id' => $book->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Chapter 2']);

        $easyInA = Question::create(['chapter_id' => $chapterA->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Easy A', 'difficulty' => 'easy']);
        $hardInA = Question::create(['chapter_id' => $chapterA->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Hard A', 'difficulty' => 'hard']);
        $hardInB = Question::create(['chapter_id' => $chapterB->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'question_type' => 'mcq_single', 'question_text' => 'Hard B', 'difficulty' => 'hard']);

        $exam = Exam::create(['class_version_id' => $version->id, 'subject_id' => $subject->id, 'academic_year_id' => $academicYear->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'Final', 'selection_mode' => 'per_attempt_random', 'random_question_count' => 1]);

        $this->assertEqualsCanonicalizing([$easyInA->id, $hardInA->id, $hardInB->id], $exam->eligibleRandomQuestionIds()->all());

        $exam->update(['random_difficulty' => 'hard']);
        $this->assertEqualsCanonicalizing([$hardInA->id, $hardInB->id], $exam->eligibleRandomQuestionIds()->all());

        $exam->randomChapters()->attach($chapterA->id);
        $this->assertSame([$hardInA->id], $exam->eligibleRandomQuestionIds()->all());
    }
}
