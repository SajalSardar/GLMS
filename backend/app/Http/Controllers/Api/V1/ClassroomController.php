<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassroomRequest;
use App\Http\Requests\Api\V1\UpdateClassroomRequest;
use App\Http\Resources\Api\V1\ClassroomResource;
use App\Models\Classroom;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClassroomController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $classrooms = Classroom::query()
            ->with('school')
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->orderBy('school_id')
            ->orderBy('name')
            ->get();

        return ClassroomResource::collection($classrooms);
    }

    /**
     * Display the specified resource.
     */
    public function show(Classroom $classroom): ClassroomResource
    {
        return new ClassroomResource($classroom->load('school'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted classroom with the same (school_id, name) is
     * restored and updated in place, rather than attempting a second
     * insert that would collide with the still-present unique index on a
     * trashed row.
     */
    public function store(StoreClassroomRequest $request): JsonResponse
    {
        $school = School::findOrFail($request->integer('school_id'));

        $attributes = [
            ...$request->safe()->only(['name', 'building', 'floor', 'capacity', 'description']),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ];

        $classroom = Classroom::onlyTrashed()
            ->where('school_id', $school->id)
            ->where('name', $attributes['name'])
            ->first();

        if ($classroom) {
            $classroom->restore();
            $classroom->update($attributes);
        } else {
            $classroom = Classroom::create($attributes);
        }

        return (new ClassroomResource($classroom->load('school')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClassroomRequest $request, Classroom $classroom): ClassroomResource
    {
        $classroom->update($request->validated());

        return new ClassroomResource($classroom->load('school'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Classroom $classroom): Response
    {
        $this->authorize('delete', $classroom);

        $classroom->delete();

        return response()->noContent();
    }
}
