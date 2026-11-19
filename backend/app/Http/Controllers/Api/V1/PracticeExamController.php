<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePracticeExamRequest;
use App\Models\AcademicYear;
use App\Models\Chapter;
use App\Models\Exam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PracticeExamController extends Controller
{
    public function config(): JsonResponse
    {
        return response()->json([
            'single_chapter_question_count' => config('practice_exams.single_chapter_question_count'),
            'multi_chapter_question_count' => config('practice_exams.multi_chapter_question_count'),
            'tier_pass_percent' => config('practice_exams.tier_pass_percent'),
            'tier_good_percent' => config('practice_exams.tier_good_percent'),
            'tier_best_percent' => config('practice_exams.tier_best_percent'),
        ]);
    }

    /**
     * Creates a hidden, student-driven Exam (is_practice = true) scoped
     * via exam_random_chapters to exactly the submitted chapters. No
     * find-or-create/dedup — every call creates a fresh row (accepted
     * tradeoff, see the design spec).
     *
     * This route sits outside the auth:sanctum group (it must also serve
     * guests), so the acting user is resolved explicitly from the
     * sanctum guard rather than via $request->user().
     */
    public function store(StorePracticeExamRequest $request): JsonResponse
    {
        // Validation's `distinct` rule rejects a duplicated id outright,
        // but dedupe defensively anyway: it's what keeps the single-vs-
        // multi-chapter question count and the exam_random_chapters
        // unique-constraint insert correct even if that rule is ever
        // relaxed or bypassed.
        $chapterIds = array_values(array_unique($request->validated('chapter_ids')));
        $subjectId = $request->validated('subject_id');

        $questionCount = count($chapterIds) > 1
            ? config('practice_exams.multi_chapter_question_count')
            : config('practice_exams.single_chapter_question_count');

        [$classVersionId, $academicYearId, $schoolId, $districtId] = $this->resolveOwnerContext($request, $chapterIds);

        $exam = DB::transaction(function () use ($classVersionId, $subjectId, $academicYearId, $schoolId, $districtId, $questionCount, $chapterIds) {
            $exam = Exam::create([
                'class_version_id' => $classVersionId,
                'subject_id' => $subjectId,
                'academic_year_id' => $academicYearId,
                'school_id' => $schoolId,
                'district_id' => $districtId,
                'name' => 'Practice #'.Str::random(8),
                'selection_mode' => 'per_attempt_random',
                'random_question_count' => $questionCount,
                'marks_per_question' => 1,
                'passing_score_percent' => config('practice_exams.tier_pass_percent'),
                'status' => 'published',
                'is_practice' => true,
            ]);

            $exam->randomChapters()->attach($chapterIds);

            return $exam;
        });

        return response()->json(['data' => ['id' => $exam->id]], 201);
    }

    /**
     * [class_version_id, academic_year_id, school_id, district_id].
     *
     * A logged-in student's own enrollment is used when available — this
     * is what lets ExamAttemptController::start()'s enrollment check
     * (comparing the attempting student's own class_version_id/
     * academic_year_id against the exam's) succeed for the exact
     * student who requested this practice exam. A guest — or an
     * authenticated actor with no usable student profile/section/shift,
     * mirroring the frontend's existing "403 falls through to guest"
     * pattern in ExamRunnerPage.tsx — falls back to the first selected
     * chapter's own book's class_version/school/district and the
     * current academic year; this is cosmetic/reporting-only for a
     * guest, since guests never reach that enrollment check at all.
     *
     * @param  list<int>  $chapterIds
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function resolveOwnerContext(Request $request, array $chapterIds): array
    {
        $actor = $request->user('sanctum');
        $section = $actor?->profile?->section;
        $shift = $section?->shift;

        if ($shift !== null) {
            return [$shift->class_version_id, $shift->academic_year_id, $section->school_id, $section->district_id];
        }

        // Book carries no school_id/district_id of its own (dropped in
        // migration 2026_11_18_100100_drop_tenant_columns_from_books_table —
        // only class_version_id/subject_id remain), so those two come
        // from the book's own ClassVersion instead.
        $firstChapter = Chapter::with('book.classVersion')->find($chapterIds[0]);
        $classVersion = $firstChapter->book->classVersion;
        $academicYearId = AcademicYear::where('is_current', true)->first()?->id;

        return [$classVersion->id, $academicYearId, $classVersion->school_id, $classVersion->district_id];
    }
}
