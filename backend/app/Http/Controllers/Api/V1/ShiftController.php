<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreShiftRequest;
use App\Http\Requests\Api\V1\UpdateShiftRequest;
use App\Http\Resources\Api\V1\ShiftResource;
use App\Models\AcademicYear;
use App\Models\ClassVersion;
use App\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ShiftController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $shifts = Shift::query()
            ->with(['classVersion.schoolClass.school', 'academicYear'])
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when(
                $request->integer('class_id'),
                fn ($query, $classId) => $query->whereHas(
                    'classVersion',
                    fn ($versionQuery) => $versionQuery->where('class_id', $classId),
                ),
            )
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return ShiftResource::collection($shifts);
    }

    /**
     * Display the specified resource.
     */
    public function show(Shift $shift): ShiftResource
    {
        return new ShiftResource($shift->load(['classVersion.schoolClass.school', 'academicYear', 'sections']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted shift with the same (class_version_id, name) is
     * restored and updated in place, rather than attempting a second
     * insert that would collide with the still-present unique index on a
     * trashed row.
     */
    public function store(StoreShiftRequest $request): JsonResponse
    {
        $this->authorize('create', Shift::class);

        $classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));

        $this->assertAcademicYearBelongsToSchool($request->integer('academic_year_id') ?: null, $classVersion->school_id);

        $attributes = [
            ...$request->safe()->only(['name', 'bn_name', 'start_time', 'end_time', 'days_of_week', 'period_duration_minutes', 'breaks']),
            'academic_year_id' => $request->integer('academic_year_id') ?: null,
            'is_active' => $request->boolean('is_active', true),
            'class_version_id' => $classVersion->id,
            'school_id' => $classVersion->school_id,
            'district_id' => $classVersion->district_id,
        ];

        $shift = Shift::onlyTrashed()
            ->where('class_version_id', $classVersion->id)
            ->where('name', $attributes['name'])
            ->first();

        if ($shift) {
            $shift->restore();
            $shift->update($attributes);
        } else {
            $shift = Shift::create($attributes);
        }

        return (new ShiftResource($shift->load('classVersion.schoolClass.school')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        $this->authorize('update', $shift);

        $data = $request->validated();

        if (array_key_exists('academic_year_id', $data)) {
            $this->assertAcademicYearBelongsToSchool($data['academic_year_id'], $shift->school_id);
        }

        $shift->update($data);

        return new ShiftResource($shift->load('classVersion.schoolClass.school'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Shift $shift): Response
    {
        $this->authorize('delete', $shift);

        $shift->delete();

        return response()->noContent();
    }

    /**
     * An academic_year_id, when given, must belong to the shift's own
     * school — otherwise a shift could be pinned to another school's
     * academic calendar.
     */
    private function assertAcademicYearBelongsToSchool(?int $academicYearId, int $schoolId): void
    {
        if ($academicYearId === null) {
            return;
        }

        if (AcademicYear::where('id', $academicYearId)->where('school_id', $schoolId)->doesntExist()) {
            throw ValidationException::withMessages([
                'academic_year_id' => ['The selected academic year does not belong to this shift\'s school.'],
            ]);
        }
    }
}
