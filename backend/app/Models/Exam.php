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
     * The single source of truth for "which questions can a
     * fixed_random/per_attempt_random draw pick from": every chapter
     * reachable through a book under this exam's (class_version_id,
     * subject_id), optionally narrowed by exam_random_chapters,
     * optionally filtered by random_difficulty. Used by both
     * ExamController::randomize() and ExamAttemptController::start() —
     * must not be duplicated a third time.
     *
     * @return Collection<int, int>
     */
    public function eligibleRandomQuestionIds(): Collection
    {
        $chapterIds = Chapter::query()
            ->whereHas('book', fn ($query) => $query->where('class_version_id', $this->class_version_id)->where('subject_id', $this->subject_id))
            ->pluck('id');

        $allowedChapterIds = $this->randomChapters()->pluck('chapters.id');

        if ($allowedChapterIds->isNotEmpty()) {
            $chapterIds = $chapterIds->intersect($allowedChapterIds);
        }

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
