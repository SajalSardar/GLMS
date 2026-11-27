<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewStudentLeaveApplicationRequest;
use App\Http\Requests\Api\V1\StoreStudentLeaveApplicationRequest;
use App\Http\Resources\Api\V1\StudentLeaveApplicationResource;
use App\Models\AcademicYear;
use App\Models\StudentLeaveApplication;
use App\Models\TeacherUser;
use App\Support\TargetStudentProfileResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class StudentLeaveApplicationController extends Controller
{
    public function __construct(private readonly TargetStudentProfileResolver $targetStudentProfileResolver) {}

    // schoolClass was missing here despite StudentLeaveApplicationResource
    // always referencing it — `class` silently came back empty on every
    // response (whenLoaded() omits an unloaded relation rather than
    // erroring), never caught because no UI rendered it until now.
    private const WITH = ['studentProfile.user', 'schoolClass', 'section', 'shift', 'classVersion', 'academicYear', 'reviewedBy'];

    /**
     * Staff/teacher listing, scoped only by TenantScope (school/
     * district) plus the student_leave_applications.view permission —
     * any permitted teacher sees every application in their own school,
     * not just their own section's. Reviewing (see review() below) is
     * the action still locked to the section's own class teacher; each
     * row's resource carries a `can_review` flag so the frontend can
     * show the approve/reject/comment form only where it's actually
     * allowed, without re-deriving the class-teacher check itself.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', StudentLeaveApplication::class);

        $applications = StudentLeaveApplication::query()
            ->with(self::WITH)
            ->when($request->string('status')->toString(), fn ($query, $status) => $query->where('status', $status))
            ->when($request->integer('section_id'), fn ($query, $id) => $query->where('section_id', $id))
            ->orderByDesc('created_at')
            ->get();

        return StudentLeaveApplicationResource::collection($applications);
    }

    public function show(StudentLeaveApplication $student_leave_application): StudentLeaveApplicationResource
    {
        $this->authorize('view', $student_leave_application);

        return new StudentLeaveApplicationResource($student_leave_application->load(self::WITH));
    }

    /**
     * A student submits their own leave application, scoped server-side
     * to their exact school/district/class/section/shift/class_version
     * (never taken from the request) — same denormalization reasoning as
     * ChapterMaterialController::resolveUploaderScope(). A student whose
     * profile is missing any of those (no section assigned yet) is
     * rejected outright: there would be no section scoped to review
     * them, so a row like that could never surface to any teacher.
     */
    public function store(StoreStudentLeaveApplicationRequest $request): JsonResponse
    {
        $student = $request->user();
        $profile = $student->profile;

        if (
            $profile === null
            || $profile->school_id === null
            || $profile->district_id === null
            || $profile->class_id === null
            || $profile->section_id === null
            || $profile->shift_id === null
            || $profile->class_version_id === null
        ) {
            throw ValidationException::withMessages([
                'student_profile' => ['Your profile is missing class/section information. Please contact your school.'],
            ]);
        }

        $application = StudentLeaveApplication::create([
            'student_profile_id' => $profile->id,
            'school_id' => $profile->school_id,
            'district_id' => $profile->district_id,
            'class_id' => $profile->class_id,
            'section_id' => $profile->section_id,
            'shift_id' => $profile->shift_id,
            'class_version_id' => $profile->class_version_id,
            'academic_year_id' => AcademicYear::where('is_current', true)->value('id'),
            'from_date' => $request->input('from_date'),
            'to_date' => $request->input('to_date'),
            'reason' => $request->input('reason'),
            'status' => 'pending',
        ]);

        return (new StudentLeaveApplicationResource($application->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * This student's own leave applications, most recent first. A
     * guardian may also call this (read-only history) for one of their
     * linked children — submitting a new application (store() above)
     * stays student-only.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $profile = $this->targetStudentProfileResolver->resolve($request);

        if ($profile === null) {
            return StudentLeaveApplicationResource::collection(collect());
        }

        $applications = StudentLeaveApplication::query()
            ->with(self::WITH)
            ->where('student_profile_id', $profile->id)
            ->orderByDesc('created_at')
            ->get();

        return StudentLeaveApplicationResource::collection($applications);
    }

    /**
     * Approve or reject, with an optional comment. reviewed_by_id is
     * only stamped when the reviewer is a TeacherUser (it's a
     * teacher_users FK) — a staff actor reviewing via the broader
     * .update permission is still fully attributed through the
     * polymorphic updated_by_id/updated_by_type Auditable columns, just
     * not through this teacher-specific pointer.
     */
    public function review(ReviewStudentLeaveApplicationRequest $request, StudentLeaveApplication $student_leave_application): StudentLeaveApplicationResource
    {
        $actor = $request->user();

        $student_leave_application->update([
            'status' => $request->input('status'),
            'teacher_comment' => $request->input('teacher_comment'),
            'reviewed_by_id' => $actor instanceof TeacherUser ? $actor->id : $student_leave_application->reviewed_by_id,
            'reviewed_at' => now(),
        ]);

        return new StudentLeaveApplicationResource($student_leave_application->load(self::WITH));
    }
}
