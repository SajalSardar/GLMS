<?php

namespace App\Models;

use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Exam extends Model
{
    use SoftDeletes, TenantScoped;

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
