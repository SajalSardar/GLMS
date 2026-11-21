<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PublishAcademicExamRequest;
use App\Models\AcademicExamMark;
use App\Models\AcademicExamPublication;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use App\Models\StudentProfile;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AcademicExamPublicationController extends Controller
{
    /**
     * Per-subject entered-vs-roster-size counts for a (section, term,
     * year), plus whether it's already published — the data behind the
     * management portal's Publish Results screen, so an admin can see at
     * a glance which subjects still need marks before publishing.
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicExamPublication::class);

        $section = Section::findOrFail($request->integer('section_id'));
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');
        $classId = $section->shift?->classVersion?->class_id;

        $rosterSize = StudentProfile::where('section_id', $section->id)->count();

        $subjects = AcademicSubjectMarkConfigure::where('class_id', $classId)->with('subject')->get();

        $subjectRows = $subjects->map(function (AcademicSubjectMarkConfigure $configure) use ($section, $termId, $yearId) {
            $enteredCount = AcademicExamMark::where('section_id', $section->id)
                ->where('subject_id', $configure->subject_id)
                ->where('academic_exam_term_id', $termId)
                ->where('academic_year_id', $yearId)
                ->count();

            return [
                'subject_id' => $configure->subject_id,
                'subject_name' => $configure->subject?->bn_name ?? $configure->subject?->name,
                'entered_count' => $enteredCount,
            ];
        })->values();

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'academic_exam_term_id' => $termId,
                'academic_year_id' => $yearId,
                'roster_size' => $rosterSize,
                'subjects' => $subjectRows,
                'is_published' => AcademicExamPublication::isPublished($section->id, $termId, $yearId),
            ],
        ]);
    }

    /**
     * Publish a (section, term, year) — additive only: inserts into
     * academic_exam_publications, never touches academic_exam_marks.
     * Already-published is a no-op (not an error), matching the table's
     * own unique index semantics instead of fighting them.
     */
    public function store(PublishAcademicExamRequest $request): JsonResponse
    {
        $section = Section::findOrFail($request->integer('section_id'));
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');

        if (! AcademicExamPublication::isPublished($section->id, $termId, $yearId)) {
            try {
                AcademicExamPublication::create([
                    'section_id' => $section->id,
                    'academic_exam_term_id' => $termId,
                    'academic_year_id' => $yearId,
                    'school_id' => $section->school_id,
                    'district_id' => $section->district_id,
                ]);
            } catch (QueryException $exception) {
                // Lost the race to a concurrent publish for the exact same
                // (section, term, year) — the unique index already prevented
                // a duplicate row; treat this exactly like the
                // already-published branch above, not an error.
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
            }
        }

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'academic_exam_term_id' => $termId,
                'academic_year_id' => $yearId,
                'is_published' => true,
            ],
        ], Response::HTTP_CREATED);
    }
}
