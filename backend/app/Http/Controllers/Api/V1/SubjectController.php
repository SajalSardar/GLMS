<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubjectRequest;
use App\Http\Requests\Api\V1\UpdateSubjectRequest;
use App\Http\Resources\Api\V1\SubjectResource;
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
            // Filters via the global class_subjects pivot (Subject::classes()) —
            // the national class<->subject association, not the per-school
            // class_versions/version_subjects pivot used elsewhere.
            ->when(
                $request->integer('class_id'),
                fn ($query, $classId) => $query->whereHas('classes', fn ($q) => $q->where('school_classes.id', $classId)),
            )
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
            ->where(function ($query) use ($data) {
                $query->where('name', $data['name']);

                if (($data['code'] ?? null) !== null) {
                    $query->orWhere('code', $data['code']);
                }
            })
            ->first();

        if ($subject) {
            $this->guardAgainstConflictingRestore($subject, $data);

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
     * The name/code match above can land on a trashed subject that only
     * partially matches the incoming data (e.g. matched on name while a
     * *different* trashed or live subject already holds the incoming
     * code). Restoring and updating in that case would either collide
     * with the still-present unique index (an unhandled 500) or silently
     * overwrite the wrong record. Reject it the same way the create path
     * would via validation.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardAgainstConflictingRestore(Subject $subject, array $data): void
    {
        $errors = [];

        if (Subject::withTrashed()->where('id', '!=', $subject->id)->where('name', $data['name'])->exists()) {
            $errors['name'] = ['The name has already been taken.'];
        }

        if (($data['code'] ?? null) !== null
            && Subject::withTrashed()->where('id', '!=', $subject->id)->where('code', $data['code'])->exists()) {
            $errors['code'] = ['The code has already been taken.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
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

        if ($subject->books()->exists()) {
            throw ValidationException::withMessages([
                'subject' => ['This subject still has books and cannot be deleted.'],
            ]);
        }

        $subject->delete();

        return response()->noContent();
    }
}
