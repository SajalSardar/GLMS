<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassRequest;
use App\Http\Requests\Api\V1\UpdateClassRequest;
use App\Http\Requests\Api\V1\UpdateClassSubjectsRequest;
use App\Http\Resources\Api\V1\ClassResource;
use App\Models\School;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClassController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $classes = SchoolClass::query()
            ->with(['school', 'subjects'])
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return ClassResource::collection($classes);
    }

    /**
     * Display the specified resource.
     */
    public function show(SchoolClass $class): ClassResource
    {
        return new ClassResource($class->load(['school', 'subjects']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted class with the same (school_id, name, section) is
     * restored and updated in place, rather than attempting a second
     * insert that would collide with the still-present unique index on a
     * trashed row.
     */
    public function store(StoreClassRequest $request): JsonResponse
    {
        $this->authorize('create', SchoolClass::class);

        $school = School::findOrFail($request->integer('school_id'));
        $section = $request->input('section');

        $attributes = [
            ...$request->safe()->only(['name', 'bn_name', 'numeric_level', 'section']),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ];

        $class = SchoolClass::onlyTrashed()
            ->where('school_id', $school->id)
            ->where('name', $attributes['name'])
            ->when($section !== null, fn ($query) => $query->where('section', $section), fn ($query) => $query->whereNull('section'))
            ->first();

        if ($class) {
            $class->restore();
            $class->update($attributes);
        } else {
            $class = SchoolClass::create($attributes);
        }

        return (new ClassResource($class->load('school')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClassRequest $request, SchoolClass $class): ClassResource
    {
        $this->authorize('update', $class);

        $class->update($request->validated());

        return new ClassResource($class->load(['school', 'subjects']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SchoolClass $class): Response
    {
        $this->authorize('delete', $class);

        $class->delete();

        return response()->noContent();
    }

    /**
     * Replace the class's configured subject list.
     */
    public function updateSubjects(UpdateClassSubjectsRequest $request, SchoolClass $class): ClassResource
    {
        $this->authorize('updateSubjects', $class);

        $class->subjects()->sync($request->validated('subject_ids'));

        return new ClassResource($class->load(['school', 'subjects']));
    }
}
