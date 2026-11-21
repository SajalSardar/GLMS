<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicGradeConfigureRequest;
use App\Http\Requests\Api\V1\UpdateAcademicGradeConfigureRequest;
use App\Http\Resources\Api\V1\AcademicGradeConfigureResource;
use App\Models\AcademicGradeConfigure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AcademicGradeConfigureController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Public, same tier as holidays/academic-exam-terms. Ordered by
     * sort_order (admin-controlled, typically highest grade first).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $grades = AcademicGradeConfigure::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 15));

        return AcademicGradeConfigureResource::collection($grades);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicGradeConfigure $academic_grade_configure): AcademicGradeConfigureResource
    {
        return new AcademicGradeConfigureResource($academic_grade_configure);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAcademicGradeConfigureRequest $request): JsonResponse
    {
        $this->authorize('create', AcademicGradeConfigure::class);

        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        $grade = AcademicGradeConfigure::create($data);

        return (new AcademicGradeConfigureResource($grade))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicGradeConfigureRequest $request, AcademicGradeConfigure $academic_grade_configure): AcademicGradeConfigureResource
    {
        $this->authorize('update', $academic_grade_configure);

        $academic_grade_configure->update($request->validated());

        return new AcademicGradeConfigureResource($academic_grade_configure);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicGradeConfigure $academic_grade_configure): Response
    {
        $this->authorize('delete', $academic_grade_configure);

        $academic_grade_configure->delete();

        return response()->noContent();
    }
}
