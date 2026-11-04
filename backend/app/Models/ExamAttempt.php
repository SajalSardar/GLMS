<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExamAttempt extends Model
{
    use SoftDeletes, TenantScoped;

    /**
     * In-memory default mirroring the migration's column default.
     */
    protected $attributes = [
        'status' => 'in_progress',
    ];

    protected $fillable = [
        'exam_id',
        'user_id',
        'school_id',
        'district_id',
        'attempt_number',
        'status',
        'started_at',
        'submitted_at',
        'score',
        'total_possible_marks',
        'passed',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'passed' => 'boolean',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attemptQuestions(): HasMany
    {
        return $this->hasMany(ExamAttemptQuestion::class)->orderBy('order_index');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(ExamAttemptAnswer::class);
    }

    /**
     * If this attempt is in_progress and its exam's time limit has
     * elapsed, finalizes it NOW (grading whatever answers already
     * exist) and returns true. A no-op — returns false — if the attempt
     * isn't in_progress, the exam is untimed, or the deadline hasn't
     * passed yet. Called at the top of every action that touches an
     * in_progress attempt (show, saveAnswer, submit) since this
     * codebase has no queued Job/Horizon infrastructure to do it any
     * other way.
     */
    public function finalizeIfExpired(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        $exam = $this->exam;

        if (! $exam->time_limit_minutes) {
            return false;
        }

        $deadline = $this->started_at->copy()->addMinutes($exam->time_limit_minutes);

        if (now()->lessThan($deadline)) {
            return false;
        }

        $this->finalize($deadline);

        return true;
    }

    /**
     * Grades every attempt_question against its recorded answer (an
     * unanswered question grades as 0) and marks this attempt
     * submitted. The correctness rule is the same regardless of
     * question_type: the selected-option-id set must exactly equal the
     * correct-option-id set — this is already right for mcq_single and
     * true_false, since their correct set always has exactly one
     * member.
     */
    public function finalize(?Carbon $at = null): void
    {
        $this->loadMissing(['attemptQuestions.question.options', 'answers']);

        // Wrapped so a concurrent double-finalize (two near-simultaneous
        // submit()/show() calls both seeing a stale in_progress status)
        // either fully applies or fully rolls back — the per-question
        // ExamAttemptAnswer::create() below can collide with the unique
        // constraint on (exam_attempt_id, question_id) when another
        // request already finalized this attempt first; the caller
        // (ExamAttemptController) is expected to catch that QueryException
        // and treat it as "already finalized," same pattern as
        // ChapterVideoController::updateProgress().
        DB::transaction(function () use ($at) {
            $score = 0;
            $total = 0;

            foreach ($this->attemptQuestions as $attemptQuestion) {
                $total += $attemptQuestion->marks;

                $answer = $this->answers->firstWhere('question_id', $attemptQuestion->question_id);
                $isCorrect = $answer !== null && $this->answerIsCorrect($attemptQuestion, $answer);
                $marksAwarded = $isCorrect ? $attemptQuestion->marks : 0;

                if ($answer !== null) {
                    $answer->update(['is_correct' => $isCorrect, 'marks_awarded' => $marksAwarded]);
                } else {
                    // An unanswered question still needs a graded record so
                    // the resource can show a definite (false) is_correct
                    // once submitted, rather than leaving it null forever
                    // (null is reserved for "not yet graded"/in_progress).
                    ExamAttemptAnswer::create([
                        'exam_attempt_id' => $this->id,
                        'question_id' => $attemptQuestion->question_id,
                        'selected_option_ids' => [],
                        'is_correct' => false,
                        'marks_awarded' => 0,
                    ]);
                }

                $score += $marksAwarded;
            }

            $passed = ($this->exam->passing_score_percent !== null && $total > 0)
                ? (($score / $total) * 100) >= $this->exam->passing_score_percent
                : null;

            $this->update([
                'status' => 'submitted',
                'submitted_at' => $at ?? now(),
                'score' => $score,
                'total_possible_marks' => $total,
                'passed' => $passed,
            ]);
        });
    }

    private function answerIsCorrect(ExamAttemptQuestion $attemptQuestion, ExamAttemptAnswer $answer): bool
    {
        $correctIds = $attemptQuestion->question->options->where('is_correct', true)->pluck('id')->sort()->values()->all();
        $selectedIds = collect($answer->selected_option_ids ?? [])->sort()->values()->all();

        return $correctIds === $selectedIds;
    }
}
