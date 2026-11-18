<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassVersionRequest;
use App\Http\Requests\Api\V1\UpdateClassVersionRequest;
use App\Http\Requests\Api\V1\UpdateVersionSubjectsRequest;
use App\Http\Resources\Api\V1\ClassVersionResource;
use App\Models\ClassVersion;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClassVersionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $versions = ClassVersion::query()
            ->with(['schoolClass', 'subjects'])
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return ClassVersionResource::collection($versions);
    }

    /**
     * Display the specified resource.
     */
    public function show(ClassVersion $class_version): ClassVersionResource
    {
        return new ClassVersionResource($class_version->load(['schoolClass', 'subjects', 'shifts']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted version with the same (class_id, school_id, name) is
     * restored and updated in place, rather than attempting a second
     * insert that would collide with the still-present unique index on a
     * trashed row.
     */
    public function store(StoreClassVersionRequest $request): JsonResponse
    {
        $this->authorize('create', ClassVersion::class);

        $school = School::findOrFail($request->integer('school_id'));

        $attributes = [
            'name' => $request->string('name')->toString(),
            'bn_name' => $request->input('bn_name'),
            'is_active' => $request->boolean('is_active', true),
            'class_id' => $request->integer('class_id'),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ];

        $version = ClassVersion::onlyTrashed()
            ->where('class_id', $attributes['class_id'])
            ->where('school_id', $attributes['school_id'])
            ->where('name', $attributes['name'])
            ->first();

        if ($version) {
            $version->restore();
            $version->update($attributes);
        } else {
            $version = ClassVersion::create($attributes);
        }

        return (new ClassVersionResource($version->load('schoolClass')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClassVersionRequest $request, ClassVersion $class_version): ClassVersionResource
    {
        $this->authorize('update', $class_version);

        $class_version->update($request->validated());

        return new ClassVersionResource($class_version->load('schoolClass'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClassVersion $class_version): Response
    {
        $this->authorize('delete', $class_version);

        $class_version->delete();

        return response()->noContent();
    }

    /**
     * Replace the version's configured subject list and required weekly
     * periods per subject.
     */
    public function updateSubjects(UpdateVersionSubjectsRequest $request, ClassVersion $class_version): ClassVersionResource
    {
        $this->authorize('updateSubjects', $class_version);

        $subjects = collect($request->validated('subjects'))
            ->mapWithKeys(fn (array $row) => [
                $row['subject_id'] => ['required_weekly_periods' => $row['required_weekly_periods'] ?? null],
            ]);

        $class_version->subjects()->sync($subjects->all());

        return new ClassVersionResource($class_version->load('subjects'));
    }
}
