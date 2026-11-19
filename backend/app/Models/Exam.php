<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Exam extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    /**
     * In-memory default mirroring the migration's column default.
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'class_version_id',
        'subject_id',
        'academic_year_id',
        'school_id',
        'district_id',
        'name',
        'bn_name',
        'selection_mode',
        'time_limit_minutes',
        'max_attempts',
        'passing_score_percent',
        'available_from',
        'available_until',
        'status',
        'is_practice',
        'random_question_count',
        'random_difficulty',
        'marks_per_question',
    ];

    protected function casts(): array
    {
        return [
            'available_from' => 'datetime',
            'available_until' => 'datetime',
        ];
    }

    public function classVersion(): BelongsTo
    {
        return $this->belongsTo(ClassVersion::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'exam_questions')
            ->withPivot('id', 'order_index')
            ->orderBy('exam_questions.order_index');
    }

    public function randomChapters(): BelongsToMany
    {
        return $this->belongsToMany(Chapter::class, 'exam_random_chapters');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(ExamAttempt::class);
    }

    /**
     * Whether this exam is currently visible/attemptable to a viewer with
     * no exams.view permission (which includes every unauthenticated
     * guest) — published, and within its availability window if one is
     * set. The single place this rule lives: ExamController::show(),
     * ExamAttemptController::start(), and GuestExamAttemptController's
     * two endpoints all call this instead of repeating the three checks
     * inline.
     */
    public function isPubliclyTakeable(): bool
    {
        if ($this->status !== 'published') {
            return false;
        }

        $now = now();

        if ($this->available_from && $now->lessThan($this->available_from)) {
            return false;
        }

        if ($this->available_until && $now->greaterThan($this->available_until)) {
            return false;
        }

        return true;
    }

    /**
     * The single source of truth for "which questions can a
     * fixed_random/per_attempt_random draw pick from": exam_random_chapters
     * directly when set (chapters may span multiple schools), otherwise
     * every chapter reachable through a book under this exam's
     * (class_version_id, subject_id). Optionally filtered by
     * random_difficulty. Used by both ExamController::randomize() and
     * ExamAttemptController::start() — must not be duplicated a third time.
     *
     * @return Collection<int, int>
     */
    public function eligibleRandomQuestionIds(): Collection
    {
        $allowedChapterIds = $this->randomChapters()->pluck('chapters.id');

        // When exam_random_chapters is explicitly set, trust it directly —
        // it may span multiple schools' class_versions (a practice exam's
        // chapters are pooled cross-school by the student's own picks).
        // Only fall back to "every chapter under this exam's own
        // (class_version_id, subject_id)" when nothing has been narrowed.
        // Behavior-preserving for every pre-existing exam:
        // SetExamRandomChaptersRequest already requires any chapter it
        // accepts to belong to the exam's own class_version_id/subject_id,
        // so for those exams this is the exact same set as before.
        $chapterIds = $allowedChapterIds->isNotEmpty()
            ? $allowedChapterIds
            : Chapter::query()
                ->whereHas('book', fn ($query) => $query->where('class_version_id', $this->class_version_id)->where('subject_id', $this->subject_id))
                ->pluck('id');

        return Question::query()
            ->whereIn('chapter_id', $chapterIds)
            ->when($this->random_difficulty, fn ($query, $value) => $query->where('difficulty', $value))
            ->pluck('id');
    }

    /**
     * Never persisted — a stored total would go stale the moment the
     * question list or marks_per_question changes, and nothing in this
     * sub-project needs to query by total marks. Requires `questions` to
     * be loaded for the manual/fixed_random branch.
     */
    protected function totalMarks(): Attribute
    {
        return Attribute::make(get: function () {
            if ($this->selection_mode === 'per_attempt_random') {
                return ($this->random_question_count ?? 0) * ($this->marks_per_question ?? 0);
            }

            return $this->questions->sum('marks');
        });
    }
}
