<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreAcademicYearRequest;
use App\Http\Requests\Api\V1\UpdateAcademicYearRequest;
use App\Http\Resources\Api\V1\AcademicYearResource;
use App\Models\AcademicYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class AcademicYearController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Years beyond the current one are pre-seeded so they already exist
     * once their turn comes, but they stay hidden from every listing until
     * then — the list only ever shows up to the current year.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $academicYears = AcademicYear::query()
            ->whereYear('start_date', '<=', now()->year)
            ->when($request->boolean('current_only'), fn ($query) => $query->where('is_current', true))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return AcademicYearResource::collection($academicYears);
    }

    /**
     * Display the specified resource.
     */
    public function show(AcademicYear $academic_year): AcademicYearResource
    {
        return new AcademicYearResource($academic_year);
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted year with the same name is restored and updated in
     * place, rather than attempting a second insert that would collide
     * with the still-present unique index on a trashed row.
     */
    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $this->authorize('create', AcademicYear::class);

        $attributes = [
            ...$request->safe()->only(['name', 'start_date', 'end_date', 'description']),
            // Explicit rather than relying on the DB column default, so the
            // model attribute returned in the response matches what's
            // actually stored instead of being left unset in memory.
            'is_current' => $request->boolean('is_current'),
        ];

        $academicYear = DB::transaction(function () use ($attributes) {
            if ($attributes['is_current'] ?? false) {
                $this->unsetOtherCurrentYears();
            }

            $academicYear = AcademicYear::onlyTrashed()
                ->where('name', $attributes['name'])
                ->first();

            if ($academicYear) {
                $academicYear->restore();
                $academicYear->update($attributes);
            } else {
                $academicYear = AcademicYear::create($attributes);
            }

            return $academicYear;
        });

        return (new AcademicYearResource($academicYear))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateAcademicYearRequest $request, AcademicYear $academic_year): AcademicYearResource
    {
        $this->authorize('update', $academic_year);

        $data = $request->validated();

        DB::transaction(function () use ($academic_year, $data) {
            if ($data['is_current'] ?? false) {
                $this->unsetOtherCurrentYears($academic_year->id);
            }

            $academic_year->update($data);
        });

        return new AcademicYearResource($academic_year);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(AcademicYear $academic_year): Response
    {
        $this->authorize('delete', $academic_year);

        $academic_year->delete();

        return response()->noContent();
    }

    /**
     * Mark this academic year as the current one nationally, unsetting
     * any other year previously marked current.
     */
    public function setCurrent(AcademicYear $academic_year): AcademicYearResource
    {
        $this->authorize('setCurrent', $academic_year);

        DB::transaction(function () use ($academic_year) {
            $this->unsetOtherCurrentYears($academic_year->id);
            $academic_year->update(['is_current' => true]);
        });

        return new AcademicYearResource($academic_year);
    }

    /**
     * Only one academic year nationally may be marked current at a time.
     */
    private function unsetOtherCurrentYears(?int $exceptId = null): void
    {
        AcademicYear::where('is_current', true)
            ->when($exceptId, fn ($query, $exceptId) => $query->whereKeyNot($exceptId))
            ->update(['is_current' => false]);
    }
}
