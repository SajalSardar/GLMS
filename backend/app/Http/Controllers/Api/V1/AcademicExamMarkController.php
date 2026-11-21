<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordAcademicExamMarkRequest;
use App\Models\AcademicExamMark;
use App\Models\AcademicSubjectMarkConfigure;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectTeacherAssignment;
use App\Models\TeacherUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicExamMarkController extends Controller
{
    /**
     * Every (section, subject) combination this teacher may touch for
     * mark entry — the class teacher of a section gets one row per
     * subject configured for that section's class; a subject teacher
     * gets one row per their own SubjectTeacherAssignment. Deduplicated
     * by (section_id, subject_id) so a teacher who is both the class
     * teacher AND separately assigned the same subject sees it once.
     * Mirrors AttendanceController::mySections(), extended with the
     * subject dimension mark entry needs that attendance doesn't.
     */
    public function myAssignments(Request $request): JsonResponse
    {
        $teacher = $request->user();
        abort_unless($teacher instanceof TeacherUser, 403);

        $classTeacherSections = Section::query()
            ->with(['shift.classVersion.schoolClass', 'shift.academicYear'])
            ->where('class_teacher_id', $teacher->id)
            ->get();

        $assignmentRows = SubjectTeacherAssignment::query()
            ->with(['section.shift.classVersion.schoolClass', 'section.shift.academicYear', 'subject'])
            ->where('teacher_id', $teacher->id)
            ->get();

        $rows = [];

        foreach ($classTeacherSections as $section) {
            $classId = $section->shift?->classVersion?->class_id;
            $subjects = AcademicSubjectMarkConfigure::where('class_id', $classId)->with('subject')->get();

            foreach ($subjects as $configure) {
                if ($configure->subject === null) {
                    continue;
                }

                $rows[$section->id.'-'.$configure->subject_id] = $this->assignmentRow($section, $configure->subject);
            }
        }

        foreach ($assignmentRows as $assignment) {
            $section = $assignment->section;

            if ($section === null || $assignment->subject === null) {
                continue;
            }

            $rows[$section->id.'-'.$assignment->subject_id] ??= $this->assignmentRow($section, $assignment->subject);
        }

        return response()->json(['data' => array_values($rows)]);
    }

    /**
     * The full roster for a (section, subject, exam term, academic year)
     * — every student in the section, each with whatever marks already
     * exist for this exact combination, plus the subject's configured
     * full marks so the caller can render limits without a second call.
     */
    public function roster(Request $request): JsonResponse
    {
        $section = Section::with('classTeacher')->findOrFail($request->integer('section_id'));
        $subjectId = $request->integer('subject_id');

        $this->authorize('viewSection', [AcademicExamMark::class, $section, $subjectId]);

        return response()->json([
            'data' => $this->rosterPayload($request, $section, $subjectId, canRecord: $request->user()->can('recordSection', [AcademicExamMark::class, $section, $subjectId])),
        ]);
    }

    /**
     * Bulk upsert marks for a (section, subject, exam term, academic
     * year) — one row per student. Re-saving simply updates the existing
     * row (matched on the table's own unique index), never duplicates —
     * same approach as AttendanceController::record().
     */
    public function record(RecordAcademicExamMarkRequest $request): JsonResponse
    {
        $section = Section::with('classTeacher')->findOrFail($request->integer('section_id'));
        $subjectId = $request->integer('subject_id');
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');

        $shift = $section->shift;
        $validStudentIds = StudentProfile::where('section_id', $section->id)->pluck('id');
        $actor = $request->user();

        foreach ($request->array('records') as $record) {
            $studentProfileId = (int) ($record['student_profile_id'] ?? 0);

            if (! $validStudentIds->contains($studentProfileId)) {
                continue;
            }

            $attributes = [
                'school_id' => $section->school_id,
                'district_id' => $section->district_id,
                'class_id' => $shift?->classVersion?->class_id,
                'section_id' => $section->id,
                'shift_id' => $section->shift_id,
                'class_version_id' => $shift?->class_version_id,
                'cq_marks_obtained' => $record['cq_marks_obtained'] ?? null,
                'mcq_marks_obtained' => $record['mcq_marks_obtained'] ?? null,
                'practical_marks_obtained' => $record['practical_marks_obtained'] ?? null,
                'entered_by_id' => $actor instanceof TeacherUser ? $actor->id : null,
            ];

            $existing = AcademicExamMark::where('student_profile_id', $studentProfileId)
                ->where('subject_id', $subjectId)
                ->where('academic_exam_term_id', $termId)
                ->where('academic_year_id', $yearId)
                ->first();

            $isAllNull = $attributes['cq_marks_obtained'] === null
                && $attributes['mcq_marks_obtained'] === null
                && $attributes['practical_marks_obtained'] === null;

            if ($existing !== null) {
                // Always update an existing row, even to all-null — that's
                // a teacher deliberately clearing a previously-entered
                // mark, which must keep working.
                $existing->update($attributes);
            } elseif (! $isAllNull) {
                // Never create a brand-new row for a student the teacher
                // never actually touched — the frontend sends one
                // "record" per roster student regardless of whether they
                // typed anything in, and an all-null row would otherwise
                // be indistinguishable from "entered and scored zero"
                // once MarksheetBuilder computes a result from it.
                AcademicExamMark::create([
                    ...$attributes,
                    'student_profile_id' => $studentProfileId,
                    'subject_id' => $subjectId,
                    'academic_exam_term_id' => $termId,
                    'academic_year_id' => $yearId,
                ]);
            }
        }

        return response()->json([
            'data' => $this->rosterPayload($request, $section, $subjectId, canRecord: true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rosterPayload(Request $request, Section $section, int $subjectId, bool $canRecord): array
    {
        $termId = $request->integer('academic_exam_term_id');
        $yearId = $request->integer('academic_year_id');
        $classId = $section->shift?->classVersion?->class_id;

        $configure = AcademicSubjectMarkConfigure::where('class_id', $classId)->where('subject_id', $subjectId)->first();

        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        $existing = AcademicExamMark::where('section_id', $section->id)
            ->where('subject_id', $subjectId)
            ->where('academic_exam_term_id', $termId)
            ->where('academic_year_id', $yearId)
            ->get()
            ->keyBy('student_profile_id');

        return [
            'section_id' => $section->id,
            'subject_id' => $subjectId,
            'academic_exam_term_id' => $termId,
            'academic_year_id' => $yearId,
            'can_record' => $canRecord,
            'cq_full_marks' => $configure?->cq_full_marks,
            'mcq_full_marks' => $configure?->mcq_full_marks,
            'practical_full_marks' => $configure?->practical_full_marks,
            'students' => $students->map(function (StudentProfile $profile) use ($existing) {
                $mark = $existing->get($profile->id);

                return [
                    'student_profile_id' => $profile->id,
                    'name' => $profile->user?->name,
                    'roll_number' => $profile->roll_number,
                    'cq_marks_obtained' => $mark?->cq_marks_obtained,
                    'mcq_marks_obtained' => $mark?->mcq_marks_obtained,
                    'practical_marks_obtained' => $mark?->practical_marks_obtained,
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentRow(Section $section, Subject $subject): array
    {
        return [
            'section_id' => $section->id,
            'section_name' => $section->bn_name ?? $section->name,
            'subject_id' => $subject->id,
            'subject_name' => $subject->bn_name ?? $subject->name,
            'class_id' => $section->shift?->classVersion?->class_id,
            'class_name' => $section->shift?->classVersion?->schoolClass?->name,
            'class_version_id' => $section->shift?->class_version_id,
            'class_version' => $section->shift?->classVersion?->name,
            'shift_id' => $section->shift_id,
            'shift' => $section->shift?->name,
            'academic_year_id' => $section->shift?->academic_year_id,
            'academic_year' => $section->shift?->academicYear?->name,
        ];
    }
}
