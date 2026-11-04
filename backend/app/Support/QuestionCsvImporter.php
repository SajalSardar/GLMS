<?php

namespace App\Support;

use App\Models\Question;
use InvalidArgumentException;

class QuestionCsvImporter
{
    /**
     * @var list<string>
     */
    public const HEADERS = [
        'chapter_id', 'question_type', 'question_text', 'bn_question_text',
        'marks', 'difficulty', 'option_1', 'option_2', 'option_3', 'option_4',
        'option_5', 'option_6', 'correct_options',
    ];

    private const TYPES = ['mcq_single', 'mcq_multiple', 'true_false'];

    private const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /**
     * Parse one CSV data row (an associative array keyed by HEADERS) into
     * Question/QuestionOption creation data. Throws InvalidArgumentException
     * with a human-readable message on any structural problem — the
     * caller is responsible for catching it and recording a per-row
     * error, never letting one bad row abort the whole import.
     *
     * @param  array<string, string>  $row
     * @return array{question: array<string, mixed>, options: list<array<string, mixed>>}
     */
    public static function parseRow(array $row): array
    {
        $type = trim($row['question_type'] ?? '');

        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown question_type '{$type}'.");
        }

        if (trim($row['question_text'] ?? '') === '') {
            throw new InvalidArgumentException('question_text is required.');
        }

        $options = [];
        for ($i = 1; $i <= 6; $i++) {
            $text = trim($row["option_{$i}"] ?? '');
            if ($text !== '') {
                $options[$i] = $text;
            }
        }

        if ($options === []) {
            throw new InvalidArgumentException('At least one option is required.');
        }

        $correctIndexes = array_values(array_filter(
            array_map('trim', explode(';', (string) ($row['correct_options'] ?? ''))),
            fn ($value) => $value !== ''
        ));
        $correctIndexes = array_map('intval', $correctIndexes);

        if ($correctIndexes === []) {
            throw new InvalidArgumentException('correct_options must name at least one correct option.');
        }

        foreach ($correctIndexes as $index) {
            if (! array_key_exists($index, $options)) {
                throw new InvalidArgumentException("correct_options references option_{$index}, which is blank or missing.");
            }
        }

        if ($type === 'true_false' && count($options) !== 2) {
            throw new InvalidArgumentException('true_false questions must have exactly 2 options.');
        }

        if (in_array($type, ['mcq_single', 'true_false'], true) && count($correctIndexes) !== 1) {
            throw new InvalidArgumentException("{$type} questions must have exactly 1 correct option.");
        }

        if ($type === 'mcq_multiple' && count($options) < 2) {
            throw new InvalidArgumentException('mcq_multiple questions must have at least 2 options.');
        }

        $difficulty = trim($row['difficulty'] ?? '') ?: 'medium';

        if (! in_array($difficulty, self::DIFFICULTIES, true)) {
            throw new InvalidArgumentException("Unknown difficulty '{$difficulty}'.");
        }

        $marksRaw = trim((string) ($row['marks'] ?? ''));

        if ($marksRaw !== '' && ! ctype_digit($marksRaw)) {
            throw new InvalidArgumentException("marks must be a non-negative integer, got '{$marksRaw}'.");
        }

        $marks = $marksRaw === '' ? 1 : (int) $marksRaw;

        $optionRows = [];
        $orderIndex = 0;

        foreach ($options as $index => $text) {
            $optionRows[] = [
                'option_text' => $text,
                'bn_option_text' => null,
                'is_correct' => in_array($index, $correctIndexes, true),
                'order_index' => $orderIndex++,
            ];
        }

        return [
            'question' => [
                'chapter_id' => (int) ($row['chapter_id'] ?? 0),
                'question_type' => $type,
                'question_text' => $row['question_text'],
                'bn_question_text' => trim($row['bn_question_text'] ?? '') ?: null,
                'marks' => $marks,
                'difficulty' => $difficulty,
            ],
            'options' => $optionRows,
        ];
    }

    /**
     * The inverse of parseRow() — flattens a Question (with its options
     * relation already loaded, ordered by order_index) back into a CSV
     * row matching HEADERS exactly, so an exported file re-imports
     * cleanly. Used by QuestionController::export() (Task 6).
     *
     * @return list<string|int|null>
     */
    public static function toRow(Question $question): array
    {
        $columns = ['', '', '', '', '', ''];
        $correctIndexes = [];

        foreach ($question->options as $position => $option) {
            $columns[$position] = $option->option_text;

            if ($option->is_correct) {
                $correctIndexes[] = $position + 1;
            }
        }

        return [
            $question->chapter_id,
            $question->question_type,
            $question->question_text,
            $question->bn_question_text,
            $question->marks,
            $question->difficulty,
            ...$columns,
            implode(';', $correctIndexes),
        ];
    }
}
