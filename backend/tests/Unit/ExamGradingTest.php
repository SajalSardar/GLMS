<?php

namespace Tests\Unit;

use App\Models\Book;
use App\Models\Chapter;
use App\Models\ClassVersion;
use App\Models\District;
use App\Models\Division;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Upazila;
use App\Support\ExamGrading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamGradingTest extends TestCase
{
    use RefreshDatabase;

    private function questionWithOptions(string $type, array $correctFlags): Question
    {
        $question = Question::create(['chapter_id' => $this->chapter()->id, 'question_type' => $type, 'question_text' => 'Q']);

        foreach ($correctFlags as $index => $isCorrect) {
            QuestionOption::create(['question_id' => $question->id, 'option_text' => "Option {$index}", 'is_correct' => $isCorrect, 'order_index' => $index]);
        }

        return $question->fresh('options');
    }

    private function chapter(): Chapter
    {
        $division = Division::create(['name' => 'Division', 'bn_name' => 'বিভাগ']);
        $district = District::create(['division_id' => $division->id, 'name' => 'District', 'bn_name' => 'জেলা']);
        $upazila = Upazila::create(['district_id' => $district->id, 'name' => 'Upazila', 'bn_name' => 'উপজেলা']);
        $school = School::create(['division_id' => $division->id, 'district_id' => $district->id, 'upazila_id' => $upazila->id, 'name' => 'School']);

        $subject = Subject::create(['name' => 'Math']);
        $class = SchoolClass::create(['name' => 'Class One']);
        $classVersion = ClassVersion::create(['class_id' => $class->id, 'school_id' => $school->id, 'district_id' => $school->district_id, 'name' => 'V']);
        $book = Book::create(['class_version_id' => $classVersion->id, 'subject_id' => $subject->id, 'name' => 'Book']);

        return Chapter::create(['book_id' => $book->id, 'name' => 'Chapter 1']);
    }

    public function test_single_correct_option_exact_match(): void
    {
        $question = $this->questionWithOptions('mcq_single', [true, false, false]);
        $correctId = $question->options->where('is_correct', true)->first()->id;

        $this->assertTrue(ExamGrading::isCorrect($question, [$correctId]));
    }

    public function test_single_correct_option_wrong_selection(): void
    {
        $question = $this->questionWithOptions('mcq_single', [true, false, false]);
        $wrongId = $question->options->where('is_correct', false)->first()->id;

        $this->assertFalse(ExamGrading::isCorrect($question, [$wrongId]));
    }

    public function test_multi_correct_requires_exact_set(): void
    {
        $question = $this->questionWithOptions('mcq_multiple', [true, true, false]);
        $correctIds = $question->options->where('is_correct', true)->pluck('id')->all();

        $this->assertTrue(ExamGrading::isCorrect($question, $correctIds));
        $this->assertFalse(ExamGrading::isCorrect($question, [$correctIds[0]])); // partial selection is wrong
    }

    public function test_extra_selection_beyond_correct_set_is_wrong(): void
    {
        $question = $this->questionWithOptions('mcq_multiple', [true, false]);
        $allIds = $question->options->pluck('id')->all();

        $this->assertFalse(ExamGrading::isCorrect($question, $allIds));
    }

    public function test_no_selection_is_wrong(): void
    {
        $question = $this->questionWithOptions('mcq_single', [true, false]);

        $this->assertFalse(ExamGrading::isCorrect($question, []));
    }

    public function test_true_false(): void
    {
        $question = $this->questionWithOptions('true_false', [true, false]);
        $correctId = $question->options->where('is_correct', true)->first()->id;

        $this->assertTrue(ExamGrading::isCorrect($question, [$correctId]));
    }
}
