<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PreviewStudentPromotionRequest;
use App\Http\Requests\Api\V1\StoreStudentPromotionRequest;
use App\Models\AcademicExamTerm;
use App\Models\SchoolClass;
use App\Models\Scopes\TenantScope;
use App\Models\Section;
use App\Models\StudentClassPromotion;
use App\Models\StudentProfile;
use App\Support\MarksheetBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentPromotionController extends Controller
{
    public function __construct(private readonly MarksheetBuilder $marksheetBuilder) {}

    /**
     * Read-only roster for one section's promotion decision: every
     * student's computed result/GPA for the Annual term, a suggested
     * promote/retain default, and the next numeric-level class as a
     * starting suggestion for the target class picker.
     */
    public function preview(PreviewStudentPromotionRequest $request): JsonResponse
    {
        $section = Section::with('shift.classVersion')->findOrFail($request->integer('section_id'));

        $term = AcademicExamTerm::where('is_annual', true)->first();
        if ($term === null) {
            throw ValidationException::withMessages([
                'section_id' => ['No exam term is marked as the Annual/Final term yet.'],
            ]);
        }

        $academicYearId = $request->integer('academic_year_id');
        $fromClass = $section->shift?->classVersion?->schoolClass;
        $suggestedClass = $fromClass?->numeric_level !== null
            ? SchoolClass::where('numeric_level', $fromClass->numeric_level + 1)->first()
            : null;

        $students = StudentProfile::where('section_id', $section->id)->with('user')->get()
            ->sortBy(fn (StudentProfile $profile) => (int) ($profile->roll_number ?? 0))
            ->values();

        $rows = $students->map(function (StudentProfile $profile) use ($term, $academicYearId) {
            $marksheet = $this->marksheetBuilder->build($profile, $term, $academicYearId);
            $hasMarks = count($marksheet['subjects']) > 0;
            $result = $hasMarks ? $marksheet['result'] : 'no_marks';

            return [
                'student_profile_id' => $profile->id,
                'name' => $profile->user?->name,
                'roll_number' => $profile->roll_number,
                'result' => $result,
                'gpa' => $marksheet['gpa'],
                'default_action' => ($hasMarks && $marksheet['result'] === 'pass') ? 'promote' : 'retain',
            ];
        })->values();

        return response()->json([
            'data' => [
                'section_id' => $section->id,
                'academic_exam_term_id' => $term->id,
                'academic_year_id' => $academicYearId,
                'suggested_to_class_id' => $suggestedClass?->id,
                'suggested_to_class_name' => $suggestedClass?->bn_name ?? $suggestedClass?->name,
                'students' => $rows,
            ],
        ]);
    }

    /**
     * Confirm the batch: promoted students have their existing
     * StudentProfile row mutated in place (class_id/section_id/
     * shift_id/class_version_id); retained students are left completely
     * untouched. A student_class_promotions row is written for every
     * student either way — null to_* columns mean "retained."
     */
    public function store(StoreStudentPromotionRequest $request): JsonResponse
    {
        $section = Section::with('shift.classVersion')->findOrFail($request->integer('section_id'));
        $term = AcademicExamTerm::where('is_annual', true)->first();

        if ($term === null) {
            throw ValidationException::withMessages([
                'section_id' => ['No exam term is marked as the Annual/Final term yet.'],
            ]);
        }

        $academicYearId = $request->integer('academic_year_id');
        $toSection = Section::with('shift.classVersion')->findOrFail($request->integer('to_section_id'));

        // Same explicit cross-school guard StudentProfileController::
        // transferSection() already applies for a single-student move —
        // TenantScope alone isn't enough here since a national_admin's
        // queries aren't restricted to one school at all.
        if ($toSection->school_id !== $section->school_id) {
            throw ValidationException::withMessages([
                'to_section_id' => ['The selected section does not belong to the same school as the source section.'],
            ]);
        }

        $toShift = $toSection->shift;
        $toClassId = $toShift?->classVersion?->class_id;
        $fromClassId = $section->shift?->classVersion?->class_id;

        if ($toClassId === null) {
            throw ValidationException::withMessages([
                'to_section_id' => ['The selected section is not attached to a class version; promotion cannot resolve a target class.'],
            ]);
        }

        $actor = $request->user();
        $now = now();
        $records = $request->array('records');

        $overrideRequested = $request->boolean('override_capacity');

        if ($overrideRequested && ! $actor->hasPermission('sections.override_capacity')) {
            throw ValidationException::withMessages([
                'override_capacity' => ['You are not authorized to override section capacity.'],
            ]);
        }

        // Count only records that actually resolve to a real StudentProfile
        // within $section — a request with duplicate or bogus
        // student_profile_ids should not inflate this count (the
        // distinct rule in StoreStudentPromotionRequest narrows but
        // doesn't fully close this gap, since it can't verify section
        // membership).
        $promotedIdsInRequest = array_values(array_filter(
            array_map(fn (array $record) => (bool) ($record['promote'] ?? false) ? ($record['student_profile_id'] ?? null) : null, $records)
        ));

        $promotingCount = $promotedIdsInRequest === [] ? 0 : StudentProfile::where('section_id', $section->id)
            ->whereIn('id', $promotedIdsInRequest)
            ->count();

        if (! $overrideRequested && $toSection->capacity !== null && ($toSection->enrolledCount() + $promotingCount) > $toSection->capacity) {
            throw ValidationException::withMessages([
                'to_section_id' => ['This section does not have enough remaining capacity for all promoted students.'],
            ]);
        }

        $this->assertNoRollNumberCollision($records, $section, $toClassId);

        DB::transaction(function () use ($records, $section, $term, $academicYearId, $toSection, $toShift, $toClassId, $fromClassId, $actor, $now) {
            foreach ($records as $record) {
                $profile = StudentProfile::where('section_id', $section->id)->find($record['student_profile_id'] ?? 0);

                if ($profile === null) {
                    continue;
                }

                $marksheet = $this->marksheetBuilder->build($profile, $term, $academicYearId);
                $hasMarks = count($marksheet['subjects']) > 0;
                $result = $hasMarks ? $marksheet['result'] : 'no_marks';
                $promote = (bool) ($record['promote'] ?? false);

                StudentClassPromotion::create([
                    'student_profile_id' => $profile->id,
                    'academic_exam_term_id' => $term->id,
                    'academic_year_id' => $academicYearId,
                    'from_class_id' => $fromClassId,
                    'from_section_id' => $section->id,
                    'to_class_id' => $promote ? $toClassId : null,
                    'to_section_id' => $promote ? $toSection->id : null,
                    'to_shift_id' => $promote ? $toSection->shift_id : null,
                    'to_class_version_id' => $promote ? $toShift?->class_version_id : null,
                    'result' => $result,
                    'gpa' => $marksheet['gpa'],
                    'promoted_by_id' => $actor?->id,
                    'promoted_at' => $now,
                ]);

                if ($promote) {
                    $profile->update([
                        'class_id' => $toClassId,
                        'section_id' => $toSection->id,
                        'shift_id' => $toSection->shift_id,
                        'class_version_id' => $toShift?->class_version_id,
                    ]);
                }
            }
        });

        return response()->json(['data' => ['section_id' => $section->id, 'promoted' => true]], 201);
    }

    /**
     * Reject the whole batch up front if any promoted student's
     * roll_number already belongs to some OTHER student (not part of
     * this batch) already sitting in the target class — student_profiles
     * enforces unique(['class_id', 'roll_number']), and letting that
     * collision surface mid-DB::transaction() would roll back every
     * other student's legitimate promotion in the same request.
     *
     * @param  list<array{student_profile_id?:int, promote?:bool}>  $records
     */
    private function assertNoRollNumberCollision(array $records, Section $section, ?int $toClassId): void
    {
        $promotedProfileIds = array_values(array_filter(
            array_map(fn (array $record) => (bool) ($record['promote'] ?? false) ? ($record['student_profile_id'] ?? null) : null, $records)
        ));

        if ($promotedProfileIds === [] || $toClassId === null) {
            return;
        }

        $promotedRollNumbers = StudentProfile::where('section_id', $section->id)
            ->whereIn('id', $promotedProfileIds)
            ->pluck('roll_number')
            ->filter()
            ->all();

        // withTrashed(): the unique(['class_id', 'roll_number']) index does
        // NOT exclude soft-deleted rows, so a trashed student in the target
        // class still blocks it — this pre-check must see it too, or the
        // real collision only surfaces as an unhandled 500 mid-transaction.
        // withoutGlobalScope(TenantScope): student_profiles.class_id
        // references school_classes, a NATIONAL table, so the unique index
        // is effectively global across schools, not per-school — a
        // school_admin's query must not be narrowed to their own school
        // here or it misses cross-school collisions a national_admin would
        // catch, giving the same request different behavior by actor.
        $occupiedRollNumbers = StudentProfile::withTrashed()
            ->withoutGlobalScope(TenantScope::class)
            ->where('class_id', $toClassId)
            ->whereNotIn('id', $promotedProfileIds)
            ->pluck('roll_number')
            ->filter()
            ->all();

        $colliding = array_unique(array_intersect($promotedRollNumbers, $occupiedRollNumbers));

        if ($colliding !== []) {
            throw ValidationException::withMessages([
                'records' => ['Roll number(s) '.implode(', ', $colliding).' already exist in the target class. Resolve the conflict before promoting these students.'],
            ]);
        }
    }
}
