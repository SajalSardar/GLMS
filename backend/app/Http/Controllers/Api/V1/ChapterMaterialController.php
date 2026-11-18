<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreChapterMaterialRequest;
use App\Http\Requests\Api\V1\UpdateChapterMaterialRequest;
use App\Http\Resources\Api\V1\ChapterMaterialResource;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ContentAccessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChapterMaterialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $materials = ChapterMaterial::query()
            ->with('chapter')
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->orderBy('chapter_id')
            ->orderBy('type')
            ->orderBy('order_index')
            ->get();

        return ChapterMaterialResource::collection($materials);
    }

    public function show(ChapterMaterial $chapter_material): ChapterMaterialResource
    {
        return new ChapterMaterialResource($chapter_material->load('chapter'));
    }

    public function store(StoreChapterMaterialRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterMaterial::class);

        $chapter = Chapter::findOrFail($request->integer('chapter_id'));
        // ClassVersion is still TenantScoped, so this resolves to null
        // (not an exception) for a chapter outside this actor's own
        // tenant — Chapter itself is national content and no longer
        // tenant-scoped, so that check can no longer happen implicitly
        // via Chapter::findOrFail() the way it used to.
        $classVersion = $chapter->book->classVersion ?? abort(404);
        $disk = config('content.pdf_disk');
        $file = $request->file('pdf');
        $path = $file->storeAs("chapter-materials/{$chapter->id}", Str::uuid().'.pdf', $disk);

        $material = ChapterMaterial::create([
            'chapter_id' => $chapter->id,
            'school_id' => $classVersion->school_id,
            'district_id' => $classVersion->district_id,
            'type' => $request->input('type'),
            'title' => $request->input('title'),
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by_id' => $request->user()->id,
            'order_index' => $request->input('order_index'),
        ]);

        return (new ChapterMaterialResource($material->load('chapter')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateChapterMaterialRequest $request, ChapterMaterial $chapter_material): ChapterMaterialResource
    {
        $this->authorize('update', $chapter_material);

        $data = $request->validated();

        if ($request->hasFile('pdf')) {
            Storage::disk($chapter_material->disk)->delete($chapter_material->stored_path);

            $file = $request->file('pdf');
            $path = $file->storeAs("chapter-materials/{$chapter_material->chapter_id}", Str::uuid().'.pdf', $chapter_material->disk);

            $data['original_filename'] = $file->getClientOriginalName();
            $data['stored_path'] = $path;
            $data['mime_type'] = $file->getClientMimeType();
            $data['size_bytes'] = $file->getSize();
        }

        $chapter_material->update($data);

        return new ChapterMaterialResource($chapter_material->load('chapter'));
    }

    public function destroy(ChapterMaterial $chapter_material): Response
    {
        $this->authorize('delete', $chapter_material);

        Storage::disk($chapter_material->disk)->delete($chapter_material->stored_path);
        $chapter_material->delete();

        return response()->noContent();
    }

    public function downloadLink(Request $request, ChapterMaterial $chapter_material): JsonResponse
    {
        $this->authorize('view', $chapter_material);

        if ($chapter_material->status !== 'active') {
            throw ValidationException::withMessages([
                'material' => ['This material is archived and cannot be downloaded.'],
            ]);
        }

        $expiry = now()->addMinutes(config('content.signed_download_expiry_minutes'));
        $params = ['chapter_material' => $chapter_material->id, 'actor_type' => get_class($request->user()), 'actor' => $request->user()->id];

        return response()->json([
            'download_url' => URL::temporarySignedRoute('chapter-materials.download', $expiry, $params),
            'preview_url' => URL::temporarySignedRoute('chapter-materials.preview', $expiry, $params),
        ]);
    }

    public function download(Request $request, ChapterMaterial $chapter_material): mixed
    {
        abort_unless($chapter_material->status === 'active', 404);

        ContentAccessLog::create([
            'chapter_id' => $chapter_material->chapter_id,
            'chapter_material_id' => $chapter_material->id,
            'user_type' => $request->string('actor_type')->toString() ?: null,
            'user_id' => $request->integer('actor') ?: null,
            'action' => 'downloaded',
        ]);

        return Storage::disk($chapter_material->disk)->download($chapter_material->stored_path, $chapter_material->original_filename);
    }

    public function preview(Request $request, ChapterMaterial $chapter_material): mixed
    {
        abort_unless($chapter_material->status === 'active', 404);

        ContentAccessLog::create([
            'chapter_id' => $chapter_material->chapter_id,
            'chapter_material_id' => $chapter_material->id,
            'user_type' => $request->string('actor_type')->toString() ?: null,
            'user_id' => $request->integer('actor') ?: null,
            'action' => 'previewed',
        ]);

        return Storage::disk($chapter_material->disk)->response($chapter_material->stored_path, $chapter_material->original_filename);
    }
}
