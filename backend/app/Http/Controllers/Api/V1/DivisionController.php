<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDivisionRequest;
use App\Http\Requests\Api\V1\UpdateDivisionRequest;
use App\Http\Resources\Api\V1\DivisionResource;
use App\Models\Division;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class DivisionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $divisions = Division::query()
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return DivisionResource::collection($divisions);
    }

    /**
     * Display the specified resource.
     */
    public function show(Division $division): DivisionResource
    {
        return new DivisionResource($division->load('districts'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreDivisionRequest $request): JsonResponse
    {
        $this->authorize('create', Division::class);

        $division = Division::create($request->validated());

        return (new DivisionResource($division))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateDivisionRequest $request, Division $division): DivisionResource
    {
        $this->authorize('update', $division);

        $division->update($request->validated());

        return new DivisionResource($division);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Division $division): Response
    {
        $this->authorize('delete', $division);

        if ($division->districts()->exists()) {
            throw ValidationException::withMessages([
                'division' => ['This division still has districts attached to it and cannot be deleted.'],
            ]);
        }

        $division->delete();

        return response()->noContent();
    }
}
