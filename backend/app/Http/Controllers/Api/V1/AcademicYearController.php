<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicYearRequest;
use App\Http\Requests\Api\V1\UpdateAcademicYearRequest;
use App\Http\Resources\Api\V1\AcademicYearResource;
use App\Models\AcademicYear;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AcademicYearController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $academicYears = AcademicYear::query()
            ->with('school')
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->boolean('current_only'), fn ($query) => $query->where('is_current', true))
            ->orderBy('school_id')
            ->orderBy('start_date')
            ->get();

        return AcademicYearResource::collection($academicYears);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicYear $academic_year): AcademicYearResource
    {
        return new AcademicYearResource($academic_year->load('school'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted year with the same (school_id, name) is restored and
     * updated in place, rather than attempting a second insert that would
     * collide with the still-present unique index on a trashed row.
     */
    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $school = School::findOrFail($request->integer('school_id'));

        $attributes = [
            ...$request->safe()->only(['name', 'start_date', 'end_date', 'description']),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
            // Explicit rather than relying on the DB column default, so the
            // model attribute returned in the response matches what's
            // actually stored instead of being left unset in memory.
            'is_current' => $request->boolean('is_current'),
        ];

        $academicYear = DB::transaction(function () use ($school, $attributes) {
            if ($attributes['is_current'] ?? false) {
                $this->unsetOtherCurrentYears($school->id);
            }

            $academicYear = AcademicYear::onlyTrashed()
                ->where('school_id', $school->id)
                ->where('name', $attributes['name'])
                ->first();

            if ($academicYear) {
                $academicYear->restore();
                $academicYear->update($attributes);
            } else {
                $academicYear = AcademicYear::create($attributes);
            }

            return $academicYear;
        });

        return (new AcademicYearResource($academicYear->load('school')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicYearRequest $request, AcademicYear $academic_year): AcademicYearResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($academic_year, $data) {
            if ($data['is_current'] ?? false) {
                $this->unsetOtherCurrentYears($academic_year->school_id, $academic_year->id);
            }

            $academic_year->update($data);
        });

        return new AcademicYearResource($academic_year->load('school'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicYear $academic_year): Response
    {
        $this->authorize('delete', $academic_year);

        $academic_year->delete();

        return response()->noContent();
    }

    /**
     * Mark this academic year as the school's current one, unsetting any
     * other year previously marked current for the same school.
     */
    public function setCurrent(AcademicYear $academic_year): AcademicYearResource
    {
        $this->authorize('setCurrent', $academic_year);

        DB::transaction(function () use ($academic_year) {
            $this->unsetOtherCurrentYears($academic_year->school_id, $academic_year->id);
            $academic_year->update(['is_current' => true]);
        });

        return new AcademicYearResource($academic_year->load('school'));
    }

    /**
     * Only one academic year per school may be marked current at a time.
     */
    private function unsetOtherCurrentYears(int $schoolId, ?int $exceptId = null): void
    {
        AcademicYear::where('school_id', $schoolId)
            ->where('is_current', true)
            ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
            ->update(['is_current' => false]);
    }
}
