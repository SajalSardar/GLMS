<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBookRequest;
use App\Http\Requests\Api\V1\UpdateBookPdfStatusRequest;
use App\Http\Requests\Api\V1\UpdateBookRequest;
use App\Http\Requests\Api\V1\UploadBookPdfRequest;
use App\Http\Resources\Api\V1\BookResource;
use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\ContentAccessLog;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $books = Book::query()
            ->with(['classVersion', 'subject', 'academicYear'])
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->when($request->integer('academic_year_id'), fn ($query, $id) => $query->where('academic_year_id', $id))
            ->orderBy('class_version_id')
            ->orderBy('subject_id')
            ->orderBy('order_index')
            ->orderBy('name')
            ->get();

        return BookResource::collection($books);
    }

    /**
     * Display the specified resource.
     */
    public function show(Book $book): BookResource
    {
        return new BookResource($book->load(['classVersion', 'subject', 'academicYear']));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted book with the same (class_version_id, subject_id,
     * name) is restored and updated in place, rather than attempting a
     * second insert that would collide with the still-present unique
     * index on a trashed row.
     */
    public function store(StoreBookRequest $request): JsonResponse
    {
        $this->authorize('create', Book::class);

        $classVersion = ClassVersion::findOrFail($request->integer('class_version_id'));
        Subject::findOrFail($request->integer('subject_id'));

        $data = $request->validated();

        $book = Book::onlyTrashed()
            ->where('class_version_id', $data['class_version_id'])
            ->where('subject_id', $data['subject_id'])
            ->where('name', $data['name'])
            ->first();

        if ($book) {
            $book->restore();
            $book->update($data);
        } else {
            $book = Book::create($data);
        }

        return (new BookResource($book->load(['classVersion', 'subject', 'academicYear'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBookRequest $request, Book $book): BookResource
    {
        $this->authorize('update', $book);

        $book->update($request->validated());

        return new BookResource($book->load(['classVersion', 'subject', 'academicYear']));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Book $book): Response
    {
        $this->authorize('delete', $book);

        if ($book->chapters()->exists()) {
            throw ValidationException::withMessages([
                'book' => ['This book still has chapters and cannot be deleted.'],
            ]);
        }

        $book->delete();

        return response()->noContent();
    }

    public function uploadPdf(UploadBookPdfRequest $request, Book $book): BookResource
    {
        $this->authorize('update', $book);

        $disk = config('content.pdf_disk');
        $file = $request->file('pdf');

        if ($book->pdf_stored_path) {
            Storage::disk($book->pdf_disk ?? $disk)->delete($book->pdf_stored_path);
        }

        $path = $file->storeAs("books/{$book->id}/pdf", Str::uuid().'.pdf', $disk);

        $book->update([
            'pdf_original_filename' => $file->getClientOriginalName(),
            'pdf_stored_path' => $path,
            'pdf_disk' => $disk,
            'pdf_mime_type' => $file->getClientMimeType(),
            'pdf_size_bytes' => $file->getSize(),
            'pdf_uploaded_by_id' => $request->user()->id,
            'pdf_uploaded_at' => now(),
            'pdf_status' => 'active',
        ]);

        return new BookResource($book);
    }

    public function updatePdfStatus(UpdateBookPdfStatusRequest $request, Book $book): BookResource
    {
        $this->authorize('update', $book);

        $book->update(['pdf_status' => $request->validated()['status']]);

        return new BookResource($book);
    }

    public function deletePdf(Book $book): Response
    {
        $this->authorize('delete', $book);

        if ($book->pdf_stored_path) {
            Storage::disk($book->pdf_disk)->delete($book->pdf_stored_path);
        }

        $book->update([
            'pdf_original_filename' => null,
            'pdf_stored_path' => null,
            'pdf_disk' => null,
            'pdf_mime_type' => null,
            'pdf_size_bytes' => null,
            'pdf_uploaded_by_id' => null,
            'pdf_uploaded_at' => null,
            'pdf_status' => null,
        ]);

        return response()->noContent();
    }

    /**
     * Mint a short-lived signed download/preview URL for this book's PDF.
     *
     * Unlike index()/show() — which only expose non-sensitive reference
     * metadata — this action hands out a credential for the PDF's actual
     * bytes, which live on a private disk precisely because a licensed
     * book PDF is confidential (see config/content.php's `pdf_disk`
     * note). The two routes that stream those bytes
     * (books.pdf.download/books.pdf.preview) are gated only by
     * ValidateSignature, so the signature minted here IS the access
     * credential and this authorize() call is the only gate on it.
     * A previous revision dropped it "to match index()/show()'s public
     * shape", which made every book PDF in the system downloadable with
     * no authentication at all; it is deliberately restored.
     *
     * The route carries `auth:sanctum` for the same reason: $this->
     * authorize() resolves its actor from the *default* guard, which is
     * `web` (session) unless the auth middleware has called
     * Auth::shouldUse('sanctum') for this request — so a bearer token on
     * an unguarded route would authorize as a guest and be denied. All
     * ten seeded roles hold `books.view` (RolePermissionSeeder), so every
     * legitimate actor — student, teacher, guardian, admin, staff — still
     * passes.
     */
    public function pdfDownloadLink(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        if (! $book->hasAvailablePdf()) {
            throw ValidationException::withMessages([
                'pdf' => ['This book has no available PDF to download.'],
            ]);
        }

        $actor = $request->user();

        $expiry = now()->addMinutes(config('content.signed_download_expiry_minutes'));

        $params = [
            'book' => $book->id,
            'actor_type' => get_class($actor),
            'actor' => $actor->id,
        ];

        return response()->json([
            'download_url' => URL::temporarySignedRoute('books.pdf.download', $expiry, $params),
            'preview_url' => URL::temporarySignedRoute('books.pdf.preview', $expiry, $params),
        ]);
    }

    public function downloadPdf(Request $request, Book $book): mixed
    {
        if (! $book->hasAvailablePdf()) {
            abort(404);
        }

        ContentAccessLog::create([
            'book_id' => $book->id,
            'user_type' => $request->string('actor_type')->toString() ?: null,
            'user_id' => $request->integer('actor') ?: null,
            'action' => 'downloaded',
        ]);

        return Storage::disk($book->pdf_disk)->download($book->pdf_stored_path, $book->pdf_original_filename);
    }

    public function previewPdf(Request $request, Book $book): mixed
    {
        if (! $book->hasAvailablePdf()) {
            abort(404);
        }

        ContentAccessLog::create([
            'book_id' => $book->id,
            'user_type' => $request->string('actor_type')->toString() ?: null,
            'user_id' => $request->integer('actor') ?: null,
            'action' => 'previewed',
        ]);

        return Storage::disk($book->pdf_disk)->response($book->pdf_stored_path, $book->pdf_original_filename);
    }
}
