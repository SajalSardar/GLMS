<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePracticeExamRequest;
use App\Models\AcademicYear;
use App\Models\Chapter;
use App\Models\Exam;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     * Creates (or reuses) a hidden, student-driven Exam (is_practice =
     * true) scoped via exam_random_chapters to exactly the submitted
     * chapters.
     *
     * exams has a unique(class_version_id, subject_id, academic_year_id,
     * name) constraint, and namesFromChapters() derives `name`
     * deterministically from the chapter set — so re-practicing the same
     * chapter(s) (by the same student or a different one) would hit that
     * constraint and 500 if we always inserted. Every exam for the same
     * (class_version_id, subject_id, academic_year_id, chapter set) is
     * functionally identical — same random-draw configuration, same
     * eligible question pool — so the correct behavior is to reuse the
     * existing row rather than error or create a near-duplicate; each
     * student's own attempts are already tracked independently via
     * ExamAttempt(exam_id, user_id, attempt_number).
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

        $configuredCount = count($chapterIds) > 1
            ? config('practice_exams.multi_chapter_question_count')
            : config('practice_exams.single_chapter_question_count');

        // Without this the exam row is persisted and the student is
        // navigated to it, only for the draw (ExamAttemptController::
        // start() / GuestExamAttemptController::randomDraw()) to reject
        // it with an untranslatable English "Only N eligible
        // question(s) are available" error and no way back. Counted
        // directly rather than via $exam->eligibleRandomQuestionIds()
        // because no Exam exists yet.
        $eligibleCount = Question::whereIn('chapter_id', $chapterIds)->count();

        if ($eligibleCount === 0) {
            throw ValidationException::withMessages([
                'chapter_ids' => 'The selected chapters have no questions available yet.',
            ]);
        }

        $questionCount = min($configuredCount, $eligibleCount);

        [$classVersionId, $academicYearId, $schoolId, $districtId] = $this->resolveOwnerContext($request, $chapterIds);
        [$name, $bnName] = $this->namesFromChapters($chapterIds);

        $exam = DB::transaction(function () use ($classVersionId, $subjectId, $academicYearId, $schoolId, $districtId, $questionCount, $chapterIds, $name, $bnName) {
            $matching = fn ($query) => $query
                ->where('class_version_id', $classVersionId)
                ->where('subject_id', $subjectId)
                ->where('academic_year_id', $academicYearId)
                ->where('name', $name);

            // A live exam already covers this exact chapter set: reuse it
            // as-is. Its chapters are already correctly attached from
            // when it was first created, so there's nothing left to do.
            // Deliberately not "refreshed": a reused exam keeps whatever
            // random_question_count/passing_score_percent etc. it had
            // when first created, even if the practice_exams config has
            // since changed — updating those is out of scope here.
            $existing = $matching(Exam::query())->first();

            if ($existing !== null) {
                return $existing;
            }

            // No live match — check for a soft-deleted one (e.g. an admin
            // previously deleted this exact practice exam) before
            // inserting, since a trashed row still occupies the unique
            // index slot and would otherwise make the insert below fail.
            $trashed = $matching(Exam::onlyTrashed())->first();

            if ($trashed !== null) {
                $trashed->restore();

                // syncWithoutDetaching rather than attach(): soft-deleting
                // an exam (ExamController::destroy()) never detaches its
                // exam_random_chapters pivot rows, so they're typically
                // still there after restore() — attach() would try to
                // re-insert them and collide with the pivot's own
                // unique(exam_id, chapter_id) constraint.
                $trashed->randomChapters()->syncWithoutDetaching($chapterIds);

                return $trashed;
            }

            $exam = Exam::create([
                'class_version_id' => $classVersionId,
                'subject_id' => $subjectId,
                'academic_year_id' => $academicYearId,
                'school_id' => $schoolId,
                'district_id' => $districtId,
                'name' => $name,
                'bn_name' => $bnName,
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
        $firstChapter = Chapter::with('book.classVersion')->findOrFail($chapterIds[0]);
        $classVersion = $firstChapter->book->classVersion;
        $academicYearId = AcademicYear::where('is_current', true)->first()?->id;

        // exams.academic_year_id is a NOT NULL FK: without this guard a
        // null here surfaces as an unhandled QueryException/500 from
        // Exam::create() further down.
        if ($academicYearId === null) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'No current academic year is configured.',
            ]);
        }

        return [$classVersion->id, $academicYearId, $classVersion->school_id, $classVersion->district_id];
    }

    /**
     * Builds the exam's own name/bn_name from the submitted chapters'
     * names, joined in the order they were submitted — replaces the
     * previous synthetic "Practice #<random>" slug so a student's score
     * history (ExamsPage.tsx) can show which chapter(s) a practice exam
     * actually covered instead of an internal label.
     *
     * @param  list<int>  $chapterIds
     * @return array{0: string, 1: string}
     */
    private function namesFromChapters(array $chapterIds): array
    {
        $chaptersById = Chapter::whereIn('id', $chapterIds)->get(['id', 'name', 'bn_name'])->keyBy('id');
        $orderedChapters = collect($chapterIds)->map(fn (int $id) => $chaptersById->get($id))->filter();

        $name = $orderedChapters->pluck('name')->implode(', ');
        $bnName = $orderedChapters->map(fn (Chapter $chapter) => $chapter->bn_name ?? $chapter->name)->implode(', ');

        return [$name, $bnName];
    }
}
