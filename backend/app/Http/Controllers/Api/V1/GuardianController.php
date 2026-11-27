<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreGuardianRequest;
use App\Http\Requests\Api\V1\UpdateGuardianRequest;
use App\Http\Resources\Api\V1\GuardianResource;
use App\Http\Resources\Api\V1\StudentProfileResource;
use App\Models\Guardian;
use App\Models\GuardianUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GuardianController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Guardian::class);

        $guardians = Guardian::query()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->paginate($request->integer('per_page', 15));

        return GuardianResource::collection($guardians);
    }

    /**
     * Every child linked to the signed-in guardian — the Student Portal's
     * post-login child picker (and the data source for its "switch
     * child" control) reads this. Deliberately not filtered/paginated:
     * a guardian's own linked-children list is always small.
     */
    public function myChildren(Request $request): AnonymousResourceCollection
    {
        $actor = $request->user();
        abort_unless($actor instanceof GuardianUser, 403);

        $guardian = $actor->profile;

        if ($guardian === null) {
            return StudentProfileResource::collection(collect());
        }

        return StudentProfileResource::collection(
            $guardian->students()->with(['user', 'schoolClass', 'section'])->get()
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(Guardian $guardian): GuardianResource
    {
        $this->authorize('view', $guardian);

        return new GuardianResource($guardian->load('students'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * A soft-deleted guardian matching the same phone, nid_number, or
     * user_id is restored and updated in place, rather than attempting a
     * second insert that would collide with the still-present unique
     * index on a trashed row. That restore writes the COMPLETE attribute
     * set (see completeAttributes()), so the reused row keeps nothing of
     * whoever it used to belong to.
     */
    public function store(StoreGuardianRequest $request): JsonResponse
    {
        $this->authorize('create', Guardian::class);

        $data = $this->completeAttributes($request);

        $guardian = Guardian::onlyTrashed()->where('phone', $data['phone'])->first();

        // nid_number and user_id each carry their own unique index, and
        // their validation rules are withoutTrashed() (trusting this
        // restore-check to catch a trashed collision) — so a submission
        // that doesn't match an old guardian by phone may still collide
        // with a trashed row on one of these.
        if ($guardian === null && ($data['nid_number'] ?? null) !== null) {
            $guardian = Guardian::onlyTrashed()->where('nid_number', $data['nid_number'])->first();
        }

        if ($guardian === null && ($data['user_id'] ?? null) !== null) {
            $guardian = Guardian::onlyTrashed()->where('user_id', $data['user_id'])->first();
        }

        if ($guardian) {
            $this->guardAgainstConflictingRestore($guardian, $data);

            // restore() and update() are two separate writes: if the
            // second one fails, an unwrapped pair would leave the row
            // un-deleted — a request that returned 500 to the caller would
            // have silently resurrected a deleted guardian.
            DB::transaction(function () use ($guardian, $data) {
                $guardian->restore();
                $guardian->update($data);
            });
        } else {
            $guardian = Guardian::create($data);
        }

        return (new GuardianResource($guardian))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Build the complete attribute set for this submission: every fillable
     * column the request did not include is explicitly set to null.
     *
     * A restore-check match can land on a trashed guardian that is a
     * COMPLETELY DIFFERENT person (it is matched by phone, nid_number or
     * user_id — any one of them is enough). Updating such a row with just
     * the submitted keys is a partial merge, not a replacement: every
     * fillable column absent from the new submission silently keeps the
     * previous owner's value. At its worst that includes user_id, so a
     * brand-new guardian submitted with no user_id at all would come back
     * still linked to the deleted guardian's login account — one person's
     * account answering to another person's record — alongside the old
     * email, nid_number and occupation. Restoring a trashed row found via
     * ANY column match must therefore behave exactly like a fresh
     * create() with complete, honest data, never a partial merge that can
     * leak a previous, unrelated owner's PII onto a new identity.
     *
     * @return array<string, mixed>
     */
    private function completeAttributes(StoreGuardianRequest $request): array
    {
        $data = [];

        foreach ((new Guardian)->getFillable() as $column) {
            $data[$column] = $request->has($column) ? $request->validated($column) : null;
        }

        return $data;
    }

    /**
     * The phone/nid_number/user_id match above can land on a trashed
     * guardian that only partially matches the incoming data (e.g. matched
     * on nid_number while a *different* trashed or live guardian already
     * holds the incoming user_id). Restoring and updating in that case
     * would either collide with the still-present unique index (an
     * unhandled 500) or silently overwrite the wrong record. Reject it the
     * same way the create path would via validation.
     *
     * phone itself needs no guard here: it's the primary match the
     * restore-check above already keys its main lookup on, so a
     * phone-matched restore is always correct to proceed.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardAgainstConflictingRestore(Guardian $guardian, array $data): void
    {
        $errors = [];

        if (($data['nid_number'] ?? null) !== null
            && Guardian::withTrashed()->where('id', '!=', $guardian->id)->where('nid_number', $data['nid_number'])->exists()) {
            $errors['nid_number'] = ['The nid number has already been taken.'];
        }

        if (($data['user_id'] ?? null) !== null
            && Guardian::withTrashed()->where('id', '!=', $guardian->id)->where('user_id', $data['user_id'])->exists()) {
            $errors['user_id'] = ['The user id has already been taken.'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateGuardianRequest $request, Guardian $guardian): GuardianResource
    {
        $this->authorize('update', $guardian);

        $guardian->update($request->validated());

        return new GuardianResource($guardian);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Guardian $guardian): Response
    {
        $this->authorize('delete', $guardian);

        $guardian->delete();

        return response()->noContent();
    }
}
