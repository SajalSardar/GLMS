<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicSubjectMarkConfigureRequest;
use App\Http\Requests\Api\V1\UpdateAcademicSubjectMarkConfigureRequest;
use App\Http\Resources\Api\V1\AcademicSubjectMarkConfigureResource;
use App\Models\AcademicSubjectMarkConfigure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class AcademicSubjectMarkConfigureController extends Controller
{
    private const WITH = ['schoolClass', 'subject'];

    /**
     * Display a listing of the resource.
     *
     * Public, same tier as classes/subjects. Filterable by class_id so
     * the teacher-portal mark entry screen (a later phase) can ask
     * "what's configured for this class" in one call.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $configures = AcademicSubjectMarkConfigure::query()
            ->with(self::WITH)
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('subject_id'), fn ($query, $subjectId) => $query->where('subject_id', $subjectId))
            ->orderBy('class_id')
            ->paginate($request->integer('per_page', 15));

        return AcademicSubjectMarkConfigureResource::collection($configures);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicSubjectMarkConfigure $academic_subject_mark_configure): AcademicSubjectMarkConfigureResource
    {
        return new AcademicSubjectMarkConfigureResource($academic_subject_mark_configure->load(self::WITH));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreAcademicSubjectMarkConfigureRequest $request): JsonResponse
    {
        $this->authorize('create', AcademicSubjectMarkConfigure::class);

        $trashed = AcademicSubjectMarkConfigure::onlyTrashed()
            ->where('class_id', $request->integer('class_id'))
            ->where('subject_id', $request->integer('subject_id'))
            ->first();

        if ($trashed !== null) {
            $trashed->restore();
            $trashed->update($request->validated());
            $configure = $trashed;
        } else {
            $configure = AcademicSubjectMarkConfigure::create($request->validated());
        }

        return (new AcademicSubjectMarkConfigureResource($configure->load(self::WITH)))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicSubjectMarkConfigureRequest $request, AcademicSubjectMarkConfigure $academic_subject_mark_configure): AcademicSubjectMarkConfigureResource
    {
        $this->authorize('update', $academic_subject_mark_configure);

        $academic_subject_mark_configure->update($request->validated());

        return new AcademicSubjectMarkConfigureResource($academic_subject_mark_configure->load(self::WITH));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicSubjectMarkConfigure $academic_subject_mark_configure): Response
    {
        $this->authorize('delete', $academic_subject_mark_configure);

        $academic_subject_mark_configure->delete();

        return response()->noContent();
    }
}
