<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreClassSchoolRequest;
use App\Http\Requests\Api\V1\UpdateClassSchoolSubjectsRequest;
use App\Http\Resources\Api\V1\ClassSchoolResource;
use App\Models\ClassSchool;
use App\Models\ClassVersion;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassSchoolController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $assignments = ClassSchool::query()
            ->with(['schoolClass', 'school'])
            ->when($request->integer('class_id'), fn ($query, $classId) => $query->where('class_id', $classId))
            ->when($request->integer('school_id'), fn ($query, $schoolId) => $query->where('school_id', $schoolId))
            ->orderByDesc('id')
            ->get();

        return ClassSchoolResource::collection($assignments);
    }

    /**
     * Display the specified resource.
     */
    public function show(ClassSchool $class_school): ClassSchoolResource
    {
        return new ClassSchoolResource($class_school->load(['schoolClass', 'school']));
    }

    /**
     * Store a newly created resource in storage: assign a class to a
     * school.
     */
    public function store(StoreClassSchoolRequest $request): JsonResponse
    {
        $this->authorize('create', ClassSchool::class);

        $school = School::findOrFail($request->integer('school_id'));

        $assignment = ClassSchool::create([
            'class_id' => $request->integer('class_id'),
            'school_id' => $school->id,
            'district_id' => $school->district_id,
        ]);

        return (new ClassSchoolResource($assignment->load(['schoolClass', 'school'])))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Remove the specified resource from storage: unassign a class from a
     * school. Blocked while the school still has a ClassVersion under
     * this class — removing the assignment would orphan live curriculum
     * data.
     */
    public function destroy(ClassSchool $class_school): Response
    {
        $this->authorize('delete', $class_school);

        if (ClassVersion::where('class_id', $class_school->class_id)->where('school_id', $class_school->school_id)->exists()) {
            throw ValidationException::withMessages([
                'class_school' => ['This school still has class versions under this class and cannot be unassigned.'],
            ]);
        }

        $class_school->delete();

        return response()->noContent();
    }

    /**
     * Replace this school's subject list for this class.
     */
    public function updateSubjects(UpdateClassSchoolSubjectsRequest $request, ClassSchool $class_school): ClassSchoolResource
    {
        $this->authorize('updateSubjects', $class_school);

        DB::table('class_subjects')->where('class_id', $class_school->class_id)->where('school_id', $class_school->school_id)->delete();

        $rows = collect($request->validated('subject_ids'))->map(fn (int $subjectId) => [
            'class_id' => $class_school->class_id,
            'subject_id' => $subjectId,
            'school_id' => $class_school->school_id,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($rows !== []) {
            DB::table('class_subjects')->insert($rows);
        }

        // Not loaded via ->load('subjects'): ClassSchool::subjects() adds
        // a wherePivot('school_id', $this->school_id) constraint that
        // depends on this specific row's school_id. Eloquent's eager
        // loader resolves the relation method once on a fresh model
        // instance (to build one batched query), so that constraint
        // would silently evaluate school_id as null there. Fetching it
        // directly keeps $this bound to this actual $class_school row.
        $class_school->load(['schoolClass', 'school']);
        $class_school->setRelation('subjects', $class_school->subjects()->get());

        return new ClassSchoolResource($class_school);
    }
}
