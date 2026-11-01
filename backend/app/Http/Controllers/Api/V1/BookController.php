<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreBookRequest;
use App\Http\Requests\Api\V1\UpdateBookRequest;
use App\Http\Resources\Api\V1\BookResource;
use App\Models\Book;
use App\Models\ClassVersion;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $books = Book::query()
            ->with(['classVersion', 'subject'])
            ->when($request->integer('class_version_id'), fn ($query, $id) => $query->where('class_version_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
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
        return new BookResource($book->load(['classVersion', 'subject']));
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
        $data['school_id'] = $classVersion->school_id;
        $data['district_id'] = $classVersion->district_id;

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

        return (new BookResource($book->load(['classVersion', 'subject'])))
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

        return new BookResource($book->load(['classVersion', 'subject']));
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
}
