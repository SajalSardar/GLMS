<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubjectRequest;
use App\Http\Requests\Api\V1\UpdateSubjectRequest;
use App\Http\Resources\Api\V1\SubjectResource;
use App\Models\Scopes\TenantScope;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $subjects = Subject::query()
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return SubjectResource::collection($subjects);
    }

    /**
     * Display the specified resource.
     */
    public function show(Subject $subject): SubjectResource
    {
        return new SubjectResource($subject);
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted subject whose name or code matches is restored and
     * updated in place, rather than attempting a second insert that would
     * collide with the still-present unique index on a trashed row.
     */
    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $this->authorize('create', Subject::class);

        $data = $request->validated();

        $subject = Subject::onlyTrashed()
            ->where('name', $data['name'])
            ->when($data['code'] ?? null, fn ($query, $code) => $query->orWhere('code', $code))
            ->first();

        if ($subject) {
            $subject->restore();
            $subject->update($data);
        } else {
            $subject = Subject::create($data);
        }

        return (new SubjectResource($subject))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSubjectRequest $request, Subject $subject): SubjectResource
    {
        $this->authorize('update', $subject);

        $subject->update($request->validated());

        return new SubjectResource($subject);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Subject $subject): Response
    {
        $this->authorize('delete', $subject);

        if ($subject->classes()->exists()) {
            throw ValidationException::withMessages([
                'subject' => ['This subject is still configured on one or more classes and cannot be deleted.'],
            ]);
        }

        if ($subject->books()->withoutGlobalScope(TenantScope::class)->exists()) {
            throw ValidationException::withMessages([
                'subject' => ['This subject still has books and cannot be deleted.'],
            ]);
        }

        $subject->delete();

        return response()->noContent();
    }
}
