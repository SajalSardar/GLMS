<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicExamTermRequest;
use App\Http\Requests\Api\V1\UpdateAcademicExamTermRequest;
use App\Http\Resources\Api\V1\AcademicExamTermResource;
use App\Models\AcademicExamTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AcademicExamTermController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Public, same tier as classes/subjects/holidays — national
     * reference data, not sensitive. Ordered by sort_order so the
     * configured term sequence (First Terminal, Second Terminal,
     * Annual...) stays stable regardless of insertion order.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $terms = AcademicExamTerm::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->paginate($request->integer('per_page', 15));

        return AcademicExamTermResource::collection($terms);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicExamTerm $academic_exam_term): AcademicExamTermResource
    {
        return new AcademicExamTermResource($academic_exam_term);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAcademicExamTermRequest $request): JsonResponse
    {
        $this->authorize('create', AcademicExamTerm::class);

        $data = $request->validated();
        $data['is_annual'] = $request->boolean('is_annual');
        $data['is_active'] = $request->boolean('is_active', true);

        $term = AcademicExamTerm::create($data);

        return (new AcademicExamTermResource($term))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicExamTermRequest $request, AcademicExamTerm $academic_exam_term): AcademicExamTermResource
    {
        $this->authorize('update', $academic_exam_term);

        $academic_exam_term->update($request->validated());

        return new AcademicExamTermResource($academic_exam_term);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicExamTerm $academic_exam_term): Response
    {
        $this->authorize('delete', $academic_exam_term);

        $academic_exam_term->delete();

        return response()->noContent();
    }
}
