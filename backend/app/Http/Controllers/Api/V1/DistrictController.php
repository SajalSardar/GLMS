<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDistrictRequest;
use App\Http\Requests\Api\V1\UpdateDistrictRequest;
use App\Http\Resources\Api\V1\DistrictResource;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class DistrictController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $districts = District::query()
            ->with('division')
            ->when($request->integer('division_id'), fn ($query, $divisionId) => $query->where('division_id', $divisionId))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return DistrictResource::collection($districts);
    }

    /**
     * Display the specified resource.
     */
    public function show(District $district): DistrictResource
    {
        return new DistrictResource($district->load(['division', 'upazilas']));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDistrictRequest $request): JsonResponse
    {
        $this->authorize('create', District::class);

        $district = District::create($request->validated());

        return (new DistrictResource($district->load('division')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDistrictRequest $request, District $district): DistrictResource
    {
        $this->authorize('update', $district);

        $district->update($request->validated());

        return new DistrictResource($district->load('division'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(District $district): Response
    {
        $this->authorize('delete', $district);

        if ($district->upazilas()->exists() || $district->schools()->exists()) {
            throw ValidationException::withMessages([
                'district' => ['This district still has upazilas or schools attached to it and cannot be deleted.'],
            ]);
        }

        $district->delete();

        return response()->noContent();
    }
}
