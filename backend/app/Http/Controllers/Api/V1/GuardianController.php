<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuardianRequest;
use App\Http\Requests\Api\V1\UpdateGuardianRequest;
use App\Http\Resources\Api\V1\GuardianResource;
use App\Models\Guardian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class GuardianController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Guardian::class);

        $guardians = Guardian::query()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return GuardianResource::collection($guardians);
    }

    /**
     * Display the specified resource.
     */
    public function show(Guardian $guardian): GuardianResource
    {
        $this->authorize('view', $guardian);

        return new GuardianResource($guardian->load('students'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted guardian with the same phone number is restored and
     * updated in place, rather than attempting a second insert that would
     * collide with the still-present unique index on a trashed row.
     */
    public function store(StoreGuardianRequest $request): JsonResponse
    {
        $this->authorize('create', Guardian::class);

        $data = $request->validated();

        $guardian = Guardian::onlyTrashed()->where('phone', $data['phone'])->first();

        if ($guardian) {
            $guardian->restore();
            $guardian->update($data);
        } else {
            $guardian = Guardian::create($data);
        }

        return (new GuardianResource($guardian))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGuardianRequest $request, Guardian $guardian): GuardianResource
    {
        $this->authorize('update', $guardian);

        $guardian->update($request->validated());

        return new GuardianResource($guardian);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Guardian $guardian): Response
    {
        $this->authorize('delete', $guardian);

        $guardian->delete();

        return response()->noContent();
    }
}
