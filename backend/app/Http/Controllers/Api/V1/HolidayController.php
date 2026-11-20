<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreHolidayRequest;
use App\Http\Requests\Api\V1\UpdateHolidayRequest;
use App\Http\Resources\Api\V1\HolidayResource;
use App\Models\Holiday;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class HolidayController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * Public, same tier as classes/subjects/class-routines — the
     * calendar is national reference data, not sensitive. Paginated
     * (unlike class-routines) so the management portal's GeoCrudTable can
     * manage it the same way as academic-years/classrooms/sections — a
     * read-only caller (teacher/student calendar view) just asks for a
     * generous per_page instead, same as listSchools({ perPage: 200 })
     * elsewhere in the frontend. Ordered weekly-first (so Friday/
     * Saturday always lead the list) then by start_date, closest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $holidays = Holiday::query()
            ->with('academicYear')
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->where('academic_year_id', $id))
            ->when($request->string('type')->toString(), fn ($query, $type) => $query->where('type', $type))
            ->when($request->boolean('active_only'), fn ($query) => $query->where('is_active', true))
            ->orderByRaw("type = 'weekly' desc")
            ->orderBy('start_date')
            ->paginate($request->integer('per_page', 15));

        return HolidayResource::collection($holidays);
    }

    /**
     * Display the specified resource.
     */
    public function show(Holiday $holiday): HolidayResource
    {
        return new HolidayResource($holiday->load('academicYear'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreHolidayRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active', true);

        $holiday = Holiday::create($data);

        return (new HolidayResource($holiday->load('academicYear')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateHolidayRequest $request, Holiday $holiday): HolidayResource
    {
        $holiday->update($request->validated());

        return new HolidayResource($holiday->load('academicYear'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Holiday $holiday): Response
    {
        $this->authorize('delete', $holiday);

        $holiday->delete();

        return response()->noContent();
    }
}
