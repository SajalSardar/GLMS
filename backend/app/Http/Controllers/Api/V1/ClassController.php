<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassRequest;
use App\Http\Requests\Api\V1\UpdateClassRequest;
use App\Http\Resources\Api\V1\ClassResource;
use App\Models\ClassSchool;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ClassController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $classes = SchoolClass::query()
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
        return new ClassResource($class);
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted class with the same name is restored and updated in
     * place, rather than attempting a second insert that would collide
     * with the still-present unique index on a trashed row.
     */
    public function store(StoreClassRequest $request): JsonResponse
    {
        $this->authorize('create', SchoolClass::class);

        $attributes = $request->safe()->only(['name', 'bn_name', 'numeric_level']);

        $class = SchoolClass::onlyTrashed()
            ->where('name', $attributes['name'])
            ->first();

        if ($class) {
            $class->restore();
            $class->update($attributes);
        } else {
            $class = SchoolClass::create($attributes);
        }

        return (new ClassResource($class))
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

        return new ClassResource($class);
    }

    /**
     * Remove the specified resource from storage. Blocked while any
     * school still has this class adopted via class_schools — this class
     * is national now, so deleting it would orphan every adopting
     * school's curriculum data at once.
     */
    public function destroy(SchoolClass $class): Response
    {
        $this->authorize('delete', $class);

        if (ClassSchool::where('class_id', $class->id)->exists()) {
            throw ValidationException::withMessages([
                'class' => ['This class is still adopted by one or more schools and cannot be deleted.'],
            ]);
        }

        $class->delete();

        return response()->noContent();
    }
}
