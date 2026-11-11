<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSchoolRequest;
use App\Http\Requests\Api\V1\UpdateSchoolRequest;
use App\Http\Resources\Api\V1\SchoolResource;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SchoolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $schools = School::query()
            ->with(['division', 'district', 'upazila'])
            ->when($request->integer('division_id'), fn ($query, $divisionId) => $query->where('division_id', $divisionId))
            ->when($request->integer('district_id'), fn ($query, $districtId) => $query->where('district_id', $districtId))
            ->when($request->integer('upazila_id'), fn ($query, $upazilaId) => $query->where('upazila_id', $upazilaId))
            ->when($request->string('type')->toString(), fn ($query, $type) => $query->where('type', $type))
            ->when($request->string('level')->toString(), fn ($query, $level) => $query->where('level', $level))
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return SchoolResource::collection($schools);
    }

    /**
     * Display the specified resource.
     */
    public function show(School $school): SchoolResource
    {
        return new SchoolResource($school->load(['division', 'district', 'upazila']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted school whose EIIN matches is restored and updated in
     * place, rather than attempting a second insert that would collide
     * with the still-present unique index on a trashed row. EIIN is
     * nullable, so this only applies when one was actually given.
     */
    public function store(StoreSchoolRequest $request): JsonResponse
    {
        $this->authorize('create', School::class);

        $data = $request->safe()->except(['logo']);

        if ($request->hasFile('logo')) {
            $disk = config('content.school_logo_disk');
            $file = $request->file('logo');
            $data['logo_path'] = $file->storeAs('schools', Str::uuid().'.'.$this->imageExtension($file), $disk);
            $data['logo_disk'] = $disk;
        }

        $school = ($data['eiin'] ?? null)
            ? School::onlyTrashed()->where('eiin', $data['eiin'])->first()
            : null;

        if ($school) {
            $school->restore();
            $school->update($data);
        } else {
            $school = School::create($data);
        }

        return (new SchoolResource($school->load(['division', 'district', 'upazila'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSchoolRequest $request, School $school): SchoolResource
    {
        $this->authorize('update', $school);

        $data = $request->safe()->except(['logo']);

        if ($request->hasFile('logo')) {
            if ($school->logo_path) {
                Storage::disk($school->logo_disk)->delete($school->logo_path);
            }

            $disk = config('content.school_logo_disk');
            $file = $request->file('logo');
            $data['logo_path'] = $file->storeAs('schools', Str::uuid().'.'.$this->imageExtension($file), $disk);
            $data['logo_disk'] = $disk;
        }

        $school->update($data);

        return new SchoolResource($school->load(['division', 'district', 'upazila']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(School $school): Response
    {
        $this->authorize('delete', $school);

        if ($school->logo_path) {
            Storage::disk($school->logo_disk)->delete($school->logo_path);
        }

        $school->delete();

        return response()->noContent();
    }

    /**
     * Derive the stored file extension from the validated MIME type rather
     * than the client-supplied original filename — the original filename
     * is attacker-controlled, so trusting its extension (e.g. "shell.php")
     * could get a non-.jpg/.png file saved on the public disk under an
     * executable extension even though its content passed image
     * validation. The default branch is defensive only: the
     * mimes:jpg,jpeg,png rule on the request already guarantees one of
     * these two MIME types by the time this method runs.
     */
    private function imageExtension(UploadedFile $file): string
    {
        return match ($file->getMimeType()) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => abort(422, 'Unsupported image type'),
        };
    }
}
