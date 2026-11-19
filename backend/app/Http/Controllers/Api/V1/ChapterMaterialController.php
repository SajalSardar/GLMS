<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreChapterMaterialRequest;
use App\Http\Requests\Api\V1\UpdateChapterMaterialRequest;
use App\Http\Resources\Api\V1\ChapterMaterialResource;
use App\Models\Chapter;
use App\Models\ChapterMaterial;
use App\Models\ContentAccessLog;
use App\Models\Section;
use App\Models\StudentUser;
use App\Models\TeacherUser;
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
    /**
     * A material with a null section_id predates per-section scoping (or
     * was uploaded by a non-class-teacher actor) and stays visible to the
     * whole chapter; one with a section_id set is visible only to a
     * student in that exact section. This route sits outside auth:sanctum
     * (guests browse it too), so the actor is resolved explicitly from the
     * sanctum guard — a guest, or any non-student actor, only ever sees
     * the unscoped materials.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user('sanctum');
        $ownSectionId = $actor instanceof StudentUser ? $actor->profile?->section_id : null;

        $materials = ChapterMaterial::query()
            ->with('chapter')
            ->when($request->integer('chapter_id'), fn ($query, $id) => $query->where('chapter_id', $id))
            ->where(function ($query) use ($ownSectionId) {
                $query->whereNull('section_id');

                if ($ownSectionId !== null) {
                    $query->orWhere('section_id', $ownSectionId);
                }
            })
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

    /**
     * Materials scoped to exactly this student's own school/district/
     * shift/class_version/class/section — e.g. so DashboardPage.tsx can
     * surface "new materials for your class" without the student ever
     * having to open the chapter materials list. All six fields are
     * checked explicitly (not just section_id, even though
     * resolveUploaderScope() above only ever sets them together) so this
     * stays correct even if that invariant is ever broken by a future
     * change or a direct DB edit. A student with no section assigned yet
     * has nothing to match against, so this is always empty for them.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        abort_unless($actor instanceof StudentUser, 403);

        $profile = $actor->profile;

        if ($profile === null || $profile->section_id === null) {
            return ChapterMaterialResource::collection(collect());
        }

        $materials = ChapterMaterial::query()
            ->with('chapter')
            ->where('status', 'active')
            ->where('school_id', $profile->school_id)
            ->where('district_id', $profile->district_id)
            ->where('shift_id', $profile->shift_id)
            ->where('section_id', $profile->section_id)
            ->where('class_id', $profile->class_id)
            ->where('class_version_id', $profile->class_version_id)
            ->orderByDesc('created_at')
            ->get();

        return ChapterMaterialResource::collection($materials);
    }

    public function store(StoreChapterMaterialRequest $request): JsonResponse
    {
        $this->authorize('create', ChapterMaterial::class);

        $chapter = Chapter::with('book')->findOrFail($request->integer('chapter_id'));
        $disk = config('content.pdf_disk');
        $file = $request->file('pdf');
        $path = $file->storeAs("chapter-materials/{$chapter->id}", Str::uuid().'.pdf', $disk);

        $material = ChapterMaterial::create([
            'chapter_id' => $chapter->id,
            ...$this->resolveUploaderScope($request->user(), $chapter),
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

    /**
     * [school_id, district_id, shift_id, class_version_id, class_id,
     * section_id] — all null unless the uploader is a TeacherUser who is
     * the class_teacher of a section whose own shift.class_version_id
     * matches this chapter's book's class_version_id. A school_admin/
     * national_admin upload, or a teacher with no matching section
     * (doesn't teach this exact class, or teaches several sections of
     * it — ambiguous, so left unscoped rather than guessing), leaves the
     * material visible to the whole chapter, same as before this scoping
     * existed.
     *
     * @return array{school_id: ?int, district_id: ?int, shift_id: ?int, class_version_id: ?int, class_id: ?int, section_id: ?int}
     */
    private function resolveUploaderScope(mixed $actor, Chapter $chapter): array
    {
        $empty = ['school_id' => null, 'district_id' => null, 'shift_id' => null, 'class_version_id' => null, 'class_id' => null, 'section_id' => null];

        if (! $actor instanceof TeacherUser) {
            return $empty;
        }

        $classVersionId = $chapter->book->class_version_id;

        $sections = Section::query()
            ->where('class_teacher_id', $actor->id)
            ->whereHas('shift', fn ($query) => $query->where('class_version_id', $classVersionId))
            ->with('shift.classVersion')
            ->get();

        if ($sections->count() !== 1) {
            return $empty;
        }

        $section = $sections->first();
        $shift = $section->shift;

        return [
            'school_id' => $section->school_id,
            'district_id' => $section->district_id,
            'shift_id' => $shift->id,
            'class_version_id' => $shift->class_version_id,
            'class_id' => $shift->classVersion->class_id,
            'section_id' => $section->id,
        ];
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
