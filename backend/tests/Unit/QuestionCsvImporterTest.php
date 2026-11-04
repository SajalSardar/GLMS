<?php

namespace Tests\Unit;

use App\Support\QuestionCsvImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QuestionCsvImporterTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'chapter_id' => '1',
            'question_type' => 'mcq_single',
            'question_text' => 'What is 2+2?',
            'bn_question_text' => '',
            'marks' => '2',
            'difficulty' => 'easy',
            'option_1' => '3',
            'option_2' => '4',
            'option_3' => '',
            'option_4' => '',
            'option_5' => '',
            'option_6' => '',
            'correct_options' => '2',
        ], $overrides);
    }

    public function test_parses_a_valid_mcq_single_row(): void
    {
        $parsed = QuestionCsvImporter::parseRow($this->row());

        $this->assertSame(1, $parsed['question']['chapter_id']);
        $this->assertSame('mcq_single', $parsed['question']['question_type']);
        $this->assertSame(2, $parsed['question']['marks']);
        $this->assertSame('easy', $parsed['question']['difficulty']);
        $this->assertCount(2, $parsed['options']);
        $this->assertFalse($parsed['options'][0]['is_correct']);
        $this->assertTrue($parsed['options'][1]['is_correct']);
    }

    public function test_parses_a_valid_mcq_multiple_row_with_several_correct_options(): void
    {
        $parsed = QuestionCsvImporter::parseRow($this->row([
            'question_type' => 'mcq_multiple',
            'option_3' => '5',
            'correct_options' => '2;3',
        ]));

        $this->assertCount(3, $parsed['options']);
        $this->assertTrue($parsed['options'][1]['is_correct']);
        $this->assertTrue($parsed['options'][2]['is_correct']);
    }

    public function test_defaults_marks_to_one_and_difficulty_to_medium_when_blank(): void
    {
        $parsed = QuestionCsvImporter::parseRow($this->row(['marks' => '', 'difficulty' => '']));

        $this->assertSame(1, $parsed['question']['marks']);
        $this->assertSame('medium', $parsed['question']['difficulty']);
    }

    public static function invalidRows(): array
    {
        return [
            'unknown type' => [['question_type' => 'essay']],
            'blank question_text' => [['question_text' => '']],
            'no options at all' => [['option_1' => '', 'option_2' => '']],
            'correct_options blank' => [['correct_options' => '']],
            'correct_options references a blank option' => [['correct_options' => '5']],
            'true_false with 3 options' => [['question_type' => 'true_false', 'option_1' => 'True', 'option_2' => 'False', 'option_3' => 'Maybe', 'correct_options' => '1']],
            'mcq_single with 2 correct' => [['correct_options' => '1;2']],
            'unknown difficulty' => [['difficulty' => 'impossible']],
            'non-numeric marks' => [['marks' => 'abc']],
            'negative marks' => [['marks' => '-5']],
        ];
    }

    #[DataProvider('invalidRows')]
    public function test_rejects_structurally_invalid_rows(array $overrides): void
    {
        $this->expectException(\InvalidArgumentException::class);

        QuestionCsvImporter::parseRow($this->row($overrides));
    }
}
