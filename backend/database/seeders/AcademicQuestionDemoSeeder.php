<?php

namespace Database\Seeders;

use App\Models\AcademicQuestion;
use App\Models\Book;
use App\Models\Chapter;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Version;
use Illuminate\Database\Seeder;

class AcademicQuestionDemoSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Dummy data for the national question bank (academic_questions) —
     * one CQ and one MCQ per chapter, reusing the exact Class 6 / Bangla
     * chapters LmsContentDemoSeeder already creates (Mathematics for
     * Class 6: Whole Numbers, Fractions, Basic Geometry; General Science
     * for Class 6: Our Environment, Force and Motion, States of Matter),
     * so this is a no-op until that seeder has run.
     */
    public function run(): void
    {
        $class = SchoolClass::where('name', 'Class 6')->first();
        $versionId = Version::where('name', 'Bangla')->value('id');
        $mathSubject = Subject::where('name', 'Mathematics')->first();
        $scienceSubject = Subject::where('name', 'Science')->first();

        if (! $class || ! $versionId || ! $mathSubject || ! $scienceSubject) {
            return;
        }

        $this->seedForBook($class, $versionId, $mathSubject, 'Mathematics for Class 6', [
            'Whole Numbers' => [
                'cq' => [
                    'text' => 'Explain the properties of whole numbers with examples. Also show how addition and multiplication of whole numbers are associative.',
                    'marks' => 10,
                ],
                'mcq' => [
                    'text' => 'Which of the following is the smallest whole number?',
                    'options' => ['0', '1', '-1', '10'],
                    'marks' => 1,
                ],
            ],
            'Fractions' => [
                'cq' => [
                    'text' => 'Add 2/3 and 3/4. Show your work and simplify the result to its lowest form.',
                    'marks' => 8,
                ],
                'mcq' => [
                    'text' => 'What is 1/2 + 1/4?',
                    'options' => ['3/4', '1/4', '2/4', '1/2'],
                    'marks' => 1,
                ],
            ],
            'Basic Geometry' => [
                'cq' => [
                    'text' => 'Define a triangle and classify triangles based on their sides and angles.',
                    'marks' => 10,
                ],
                'mcq' => [
                    'text' => 'How many sides does a hexagon have?',
                    'options' => ['5', '6', '7', '8'],
                    'marks' => 1,
                ],
            ],
        ]);

        $this->seedForBook($class, $versionId, $scienceSubject, 'General Science for Class 6', [
            'Our Environment' => [
                'cq' => [
                    'text' => 'Describe the components of our environment and explain the importance of a balanced ecosystem.',
                    'marks' => 10,
                ],
                'mcq' => [
                    'text' => 'Which of the following is a renewable resource?',
                    'options' => ['Coal', 'Solar Energy', 'Natural Gas', 'Petroleum'],
                    'marks' => 1,
                ],
            ],
            'Force and Motion' => [
                'cq' => [
                    'text' => "State Newton's first law of motion and give two real-life examples.",
                    'marks' => 10,
                ],
                'mcq' => [
                    'text' => 'What is the SI unit of force?',
                    'options' => ['Joule', 'Newton', 'Watt', 'Pascal'],
                    'marks' => 1,
                ],
            ],
            'States of Matter' => [
                'cq' => [
                    'text' => 'Explain the three states of matter and describe the process of sublimation with an example.',
                    'marks' => 8,
                ],
                'mcq' => [
                    'text' => 'Which state of matter has a definite shape and volume?',
                    'options' => ['Gas', 'Liquid', 'Solid', 'Plasma'],
                    'marks' => 1,
                ],
            ],
        ]);
    }

    /**
     * @param  array<string, array{cq: array{text: string, marks: int}, mcq: array{text: string, options: list<string>, marks: int}}>  $chaptersContent
     */
    private function seedForBook(SchoolClass $class, int $versionId, Subject $subject, string $bookName, array $chaptersContent): void
    {
        $book = Book::where('name', $bookName)->first();

        if (! $book) {
            return;
        }

        foreach ($chaptersContent as $chapterName => $content) {
            $chapter = Chapter::where('book_id', $book->id)->where('name', $chapterName)->first();

            if (! $chapter) {
                continue;
            }

            AcademicQuestion::updateOrCreate(
                ['chapter_id' => $chapter->id, 'question_type' => 'cq', 'question_text' => $content['cq']['text']],
                [
                    'school_class_id' => $class->id,
                    'subject_id' => $subject->id,
                    'version_id' => $versionId,
                    'marks' => $content['cq']['marks'],
                    'is_active' => true,
                ],
            );

            AcademicQuestion::updateOrCreate(
                ['chapter_id' => $chapter->id, 'question_type' => 'mcq', 'question_text' => $content['mcq']['text']],
                [
                    'school_class_id' => $class->id,
                    'subject_id' => $subject->id,
                    'version_id' => $versionId,
                    'options' => $content['mcq']['options'],
                    'marks' => $content['mcq']['marks'],
                    'is_active' => true,
                ],
            );
        }
    }
}
