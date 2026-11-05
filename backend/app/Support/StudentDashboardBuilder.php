<?php

namespace App\Support;

use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ChapterVideo;
use App\Models\ChapterVideoProgress;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\Exam;
use App\Models\ExamAttempt;
use App\Models\StudentProfile;
use App\Models\Subject;
use Illuminate\Support\Collection;

class StudentDashboardBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(StudentProfile $profile): array
    {
        $shift = $profile->section?->shift;
        $classVersionId = $shift?->class_version_id;
        $academicYearId = $shift?->academic_year_id;

        if ($classVersionId === null || $academicYearId === null) {
            return [
                'student_profile_id' => $profile->id,
                'class_version_id' => null,
                'academic_year_id' => null,
                'subjects' => [],
            ];
        }

        $classVersion = ClassVersion::findOrFail($classVersionId);

        $subjects = $classVersion->subjects->map(
            fn (Subject $subject) => $this->buildSubjectRollup($profile, $classVersionId, $academicYearId, $subject)
        )->values()->all();

        return [
            'student_profile_id' => $profile->id,
            'class_version_id' => $classVersionId,
            'academic_year_id' => $academicYearId,
            'subjects' => $subjects,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSubjectRollup(StudentProfile $profile, int $classVersionId, int $academicYearId, Subject $subject): array
    {
        $userId = $profile->user_id;

        $chapters = Chapter::query()
            ->whereHas('book', fn ($query) => $query
                ->where('class_version_id', $classVersionId)
                ->where('subject_id', $subject->id)
                ->where('is_active', true))
            ->orderBy('order_index')
            ->get(['id', 'name']);

        $chapterIds = $chapters->pluck('id');

        $videos = ChapterVideo::query()
            ->whereIn('chapter_id', $chapterIds)
            ->where('is_enabled', true)
            ->get(['id', 'chapter_id']);

        $completedVideoIds = ChapterVideoProgress::query()
            ->whereIn('chapter_video_id', $videos->pluck('id'))
            ->where('user_id', $userId)
            ->where('status', 'completed')
            ->pluck('chapter_video_id');

        $materials = ChapterMaterial::query()
            ->whereIn('chapter_id', $chapterIds)
            ->where('status', 'active')
            ->get(['id', 'chapter_id']);

        $accessedMaterialIds = ContentAccessLog::query()
            ->whereIn('chapter_material_id', $materials->pluck('id'))
            ->where('user_id', $userId)
            ->distinct()
            ->pluck('chapter_material_id');

        $videosByChapter = $videos->groupBy('chapter_id');
        $materialsByChapter = $materials->groupBy('chapter_id');

        $chapterRollups = $chapters->map(function ($chapter) use ($videosByChapter, $materialsByChapter, $completedVideoIds, $accessedMaterialIds) {
            /** @var Collection $chapterVideos */
            $chapterVideos = $videosByChapter->get($chapter->id, collect());
            /** @var Collection $chapterMaterials */
            $chapterMaterials = $materialsByChapter->get($chapter->id, collect());

            return [
                'chapter_id' => $chapter->id,
                'chapter_name' => $chapter->name,
                'video_total' => $chapterVideos->count(),
                'video_completed' => $chapterVideos->filter(fn ($video) => $completedVideoIds->contains($video->id))->count(),
                'materials_total' => $chapterMaterials->count(),
                'materials_accessed' => $chapterMaterials->filter(fn ($material) => $accessedMaterialIds->contains($material->id))->count(),
            ];
        })->values()->all();

        $exams = Exam::query()
            ->where('class_version_id', $classVersionId)
            ->where('subject_id', $subject->id)
            ->where('academic_year_id', $academicYearId)
            ->where('status', 'published')
            ->get(['id', 'name', 'max_attempts']);

        $attempts = ExamAttempt::query()
            ->whereIn('exam_id', $exams->pluck('id'))
            ->where('user_id', $userId)
            ->get(['id', 'exam_id', 'status', 'score', 'total_possible_marks', 'passed']);

        $attemptsByExam = $attempts->groupBy('exam_id');

        $examRollups = $exams->map(function ($exam) use ($attemptsByExam) {
            /** @var Collection $examAttempts */
            $examAttempts = $attemptsByExam->get($exam->id, collect());
            $submitted = $examAttempts->where('status', 'submitted');
            $best = $submitted->sortByDesc('score')->first();

            return [
                'exam_id' => $exam->id,
                'exam_name' => $exam->name,
                'attempts_used' => $examAttempts->count(),
                'max_attempts' => $exam->max_attempts,
                'best_score' => $best?->score,
                'total_possible_marks' => $best?->total_possible_marks,
                'passed' => $submitted->isEmpty() ? null : $submitted->contains('passed', true),
            ];
        })->values()->all();

        return [
            'subject_id' => $subject->id,
            'subject_name' => $subject->name,
            'chapters' => $chapterRollups,
            'exams' => $examRollups,
        ];
    }
}
