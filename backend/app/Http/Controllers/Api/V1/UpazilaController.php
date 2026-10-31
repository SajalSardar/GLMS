<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreUpazilaRequest;
use App\Http\Requests\Api\V1\UpdateUpazilaRequest;
use App\Http\Resources\Api\V1\UpazilaResource;
use App\Models\Upazila;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class UpazilaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $upazilas = Upazila::query()
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->orderBy('name')
            ->get();

        return UpazilaResource::collection($upazilas);
    }

    /**
     * Display the specified resource.
     */
    public function show(Upazila $upazila): UpazilaResource
    {
        return new UpazilaResource($upazila->load('district'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUpazilaRequest $request): JsonResponse
    {
        $this->authorize('create', Upazila::class);

        $upazila = Upazila::create($request->validated());

        return (new UpazilaResource($upazila->load('district')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUpazilaRequest $request, Upazila $upazila): UpazilaResource
    {
        $this->authorize('update', $upazila);

        $upazila->update($request->validated());

        return new UpazilaResource($upazila->load('district'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Upazila $upazila): Response
    {
        $this->authorize('delete', $upazila);

        if ($upazila->schools()->exists()) {
            throw ValidationException::withMessages([
                'upazila' => ['This upazila still has schools attached to it and cannot be deleted.'],
            ]);
        }

        $upazila->delete();

        return response()->noContent();
    }
}
